<?php

declare(strict_types=1);

/**
 * 安装上报：装完之后跟授权服务器说一声「谁在哪台机器上装了」。
 *
 * 服务端那边的落点是 `POST /api/open/v1/em/install-report`，落在
 * `bs_install_record` 表（服务端迁移 047），代理端「安装记录」那一页读的就是它；
 * 同一个版本还会给版本表的 `install_count`（安装次数）加一（服务端迁移 048）。
 *
 * ── 铁律：它失败**绝不能**影响安装 ────────────────────────────
 * 使用者原话：「不管成功还是失败，都不要影响我们安装，也不要阻塞我们安装，
 * 接口最多等待他 5 秒钟」。所以这个类里：
 *   · 全部逻辑包在 try/catch(Throwable) 里，**没有一个异常会冒出去**
 *   · 超时 5 秒、**不重试**（安装程序不该为了报个到多等一轮）
 *   · 连不上 / 服务端 5xx / 返回一堆乱码 —— 一律静默吞掉，返回 void
 * 调用点（`install/index.php`）因此**不需要**做任何错误处理。
 *
 * ⚠️ 代价：**这张表天然不是全集**。网络抖一下、服务端那会儿在重启，
 *    这次安装就没记上，而且**没有任何补偿**（不重试、不落本地待补发队列）。
 *    服务端那边也是同一套口径（它挂了也不影响别的），两边对齐。
 *    要对账时别拿记录数当安装总数 —— 那是使用者认下的。
 *
 * ── 为什么要单独一个类 ────────────────────────────────────────
 * 安装程序跑在 **`init.php` 还没加载**的环境里（没有 config.php、没有
 * `EM_VERSION`、没有数据库连接），所以**不能**直接复用 `LicenseClient` ——
 * 那个类的每次请求都在拼 `emshop-{EM_VERSION}` 这个头，还要 Config 里那份
 * 开发模式开关。这里只借它一个常量（服务端地址），HTTP 自己做，
 * 免得把「安装环境」和「正常运行环境」的要求绑在一起。
 */
final class InstallReporter
{
    /**
     * 单次请求的总超时（秒）。**使用者定的就是 5**。
     *
     * 别调大：这个数是「安装完成后用户还要多等多久」的上限 ——
     * 上报是顺带做的事，让人为它多等，不如让这次记录丢掉。
     */
    private const TIMEOUT = 5;

    /** 连接阶段的超时。比总超时更短：连不上的机器要在 3 秒内放弃，别耗满 5 秒 */
    private const CONNECT_TIMEOUT = 3;

    /**
     * 上报接口的路径（服务端那边由 `OpenInstallController::report()` 接）。
     * 服务端地址来自 `EM_LICENSE_SERVER_URL`（正常运行时由 init.php 定义）
     * 或 `LicenseClient::officialLines()` 的第一条（安装时 —— 那时候 init.php 还没跑）。
     */
    private const PATH = 'api/open/v1/em/install-report';

    /**
     * 发一次安装上报。**不抛异常、不返回失败原因** —— 调用方无从判断，
     * 这正是要点（见类注释）。
     *
     * @param array{
     *     version?: string,        // 安装的程序版本号（EM_VERSION）
     *     site_url?: string,       // 完整站点地址，如 https://example.com
     *     admin_username?: string, // 安装时设的管理员账号
     *     admin_email?: string,    // 安装时填的管理员邮箱
     *     is_reinstall?: bool      // 这一趟是不是删了旧表重建
     * } $context
     */
    public static function report(array $context = []): void
    {
        try {
            self::loadBaseFile();

            $url = self::endpoint();

            if ($url === '') {
                return;
            }
            
            self::post($url, self::payload($context));
        } catch (Throwable $ignored) {
            /* 见类注释：这里**故意**什么也不做，连日志都不写 ——
               安装环境多半还没有日志文件，写进去也没人看，
               反而可能因为目录不可写再抛一次 */
        }
    }

    /**
     * 确保 `base.php` 里的常量已经加载。
     *
     * ⚠️ **必须有这一步**：`base.php` 平时是由 `init.php` require 的，而安装程序
     * **不加载 init.php**（那时候还没有 config.php）。少了它，
     * `SERVICE_TOKEN` 读出来是空串 —— 上报照发，但**服务端认不出归属**，
     * 每条安装记录都会变成「没归属」的那种，这个功能等于白做。
     *
     * `base.php` 里只有两个常量定义，加载它没有副作用；用 `require_once`
     * 兜住「init.php 已经加载过」的正常场景（那时两个常量都在，直接返回）。
     */
    private static function loadBaseFile(): void
    {
        if (defined('SERVICE_TOKEN') && defined('SERVER_NAME')) {
            return;
        }

        $root = defined('EM_ROOT') ? (string) EM_ROOT : dirname(__DIR__, 2);
        $path = $root . '/base.php';

        if (is_file($path)) {
            require_once $path;
        }
    }

    /**
     * 拼上报内容。**字段名就是服务端 `OpenInstallController::report()` 认的那些**，
     * 改这里要同步改那边（服务端多几个字段是兼容的，少一个就是那一列空着）。
     *
     * @param array<string, mixed> $context
     * @return array<string, string|int>
     */
    private static function payload(array $context): array
    {
        $siteUrl = trim((string) ($context['site_url'] ?? ''));

        return [
            /* 程序名称：base.php 的 SERVER_NAME（服务端靠它区分两个产品线的包，
               和路径段里的 em 是两回事 —— 那个是「发给谁」，这个是「装的什么」） */
            'name' => self::constant('SERVER_NAME', 'EMSHOP'),
            'version' => trim((string) ($context['version'] ?? '')),
            /* 域名：服务端会自己归一（去协议/端口/www），这里给完整地址更省事 */
            'domain' => $siteUrl,
            'site_url' => $siteUrl,
            /* 身份标识 = 这个包是哪个代理的（服务端用它反查归属）。
               **这正是 base.php 存在的意义**，服务端认不出就是没归属，不影响记录 */
            'identity' => self::constant('SERVICE_TOKEN', ''),
            'ip' => self::clientIp(),
            'admin_username' => trim((string) ($context['admin_username'] ?? '')),
            'admin_email' => trim((string) ($context['admin_email'] ?? '')),
            'php_version' => PHP_VERSION,
            'db_version' => self::dbVersion(),
            'server_software' => trim((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')),
            'os' => self::os(),
            'is_reinstall' => !empty($context['is_reinstall']) ? 1 : 0,
        ];
    }

    /**
     * 安装者的 IP。
     *
     * **客户端的解析规则和服务端那份不一致是正常的**：这里取的是「这次访问
     * 安装程序的人」的真实来源 IP（用户在 CDN / 反代后面时，转发头里那个才是他），
     * 服务端还会再记一个它自己看到的（`server_ip`）。两个都留着，排查时对照看。
     *
     * `X-Forwarded-For` 取**第一个**（最靠近客户端的那一跳）；
     * 取不到就退回 `REMOTE_ADDR`。
     */
    private static function clientIp(): string
    {
        $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);

            if ($first !== '') {
                return $first;
            }
        }

        return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    /**
     * 数据库版本。**取不到就是空串**，不值得为它多做任何事。
     *
     * 走 `Database` 而不是自己开一个 PDO：安装流程走到这一步时
     * `InstallService::setup()` 已经用它建完表了，连接是现成的。
     * 但**不能假设它一定在**（比如以后有人在别的场景调这个类），所以照样包起来。
     */
    private static function dbVersion(): string
    {
        try {
            $row = Database::fetchOne('SELECT VERSION() AS `v`');

            return trim((string) ($row['v'] ?? ''));
        } catch (Throwable $ignored) {
            return '';
        }
    }

    /** 操作系统一行：`Linux 5.15.0-91-generic`。`php_uname` 在个别环境会返回空，兜一下 */
    private static function os(): string
    {
        $sys = (string) @php_uname('s');
        $release = (string) @php_uname('r');

        return trim($sys . ' ' . $release);
    }

    /**
     * 读一个常量，没有就给默认值。
     *
     * `base.php` 正常都在（它和 base 包一起发出去），但用户手动删过 / 改过
     * 的环境也有 —— 那种情况下**照样上报**，只是没归属（服务端认不出 identity），
     * 「有人装了一个没有身份标识的包」这件事本身就是要看见的。
     */
    private static function constant(string $name, string $default): string
    {
        if (!defined($name)) {
            return $default;
        }

        $value = trim((string) constant($name));

        return $value === '' ? $default : $value;
    }

    /** 上报地址。服务端地址只认常量，不读 config.php（理由同 LicenseClient::serverUrl()） */
    private static function endpoint(): string
    {
        // 正常运行时 init.php 已 define（当前选中线路）；安装时还没有 init.php，
        // 直接取官方线路常量的第一条 —— 只碰常量，不读 Config（安装环境没有数据库）。
        $base = defined('EM_LICENSE_SERVER_URL') ? trim((string) EM_LICENSE_SERVER_URL) : '';
        if ($base === '' && class_exists('LicenseClient')) {
            $lines = LicenseClient::officialLines();
            $base  = trim((string) ($lines[0]['url'] ?? ''));
        }

        if ($base === '') {
            return '';
        }

        return rtrim($base, '/') . '/' . self::PATH;
    }

    /**
     * 真正发出去。**返回 void**：调用方不关心结果，也不该关心。
     *
     * `CURLOPT_TIMEOUT` + `CURLOPT_CONNECTTIMEOUT` 就是那个「最多等 5 秒」；
     * 不设 `CURLOPT_FOLLOWLOCATION`（这个接口本来就不该有跳转，
     * 跟着跳反而可能把身份标识发到别的域去）。
     *
     * @param array<string, string|int> $payload
     */
    private static function post(string $url, array $payload): void
    {
        if (!function_exists('curl_init')) {
            return;
        }

        $ch = curl_init($url);

        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                /* 复用授权那条通道的头，服务端日志里一眼看得出是客户端发的 */
                'X-Em-Client: emshop-installer',
            ],
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            /* 和 LicenseClient 里的 TLS 口径保持一致（那两处为什么关校验，见那边的注释） */
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        curl_exec($ch);
        curl_close($ch);
    }
}
