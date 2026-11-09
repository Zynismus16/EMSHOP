<?php

declare(strict_types=1);

/**
 * 站点正版授权服务。
 *
 * 存储模型：全部落 Config 表（一个实例一份激活状态）
 *   license_emkey         激活码（明文，方便重新绑定用）
 *   license_emkey_type    等级整数：1=VIP 2=SVIP 3=至尊
 *   license_main_host     主授权域名（中心服务返回的完整域名，含 www / 子域名）
 *   license_alias_hosts   其它允许访问的域名 JSON 数组（纯本地概念，不通知中心服务）
 *
 * 行为约定：
 *   - 激活：调 /api/open/v1/em/license/bind（code + domain）成功 → 写 emkey / emkey_type / main_host 到 Config
 *   - 解绑：只清 main_host + emkey_type；emkey 和 alias 保留
 *   - isActivated 判据：main_host 已配置
 *   - 访问别名域名时，isActivated 仍返 true；所有中心服务调用的 host 参数一律用 main_host
 *   - 授权状态由 activate(=bind) 写入，由 api/open/v1/em/license/status 周期核对。
 *     fetchBaseData() 仅供后台首页展示（公告/广告/版本/代理商），不参与授权判定
 */
final class LicenseService
{
    public const LEVEL_NONE = 'none';
    public const LEVEL_VIP = 'vip';
    public const LEVEL_SVIP = 'svip';
    public const LEVEL_SUPREME = 'supreme';

    /** 等级权重（越高越优） */
    private const LEVEL_WEIGHT = [
        self::LEVEL_NONE => 0,
        self::LEVEL_VIP => 1,
        self::LEVEL_SVIP => 2,
        self::LEVEL_SUPREME => 3,
    ];

    /** 中心服务 type 数字 → 内部 level 字符串 */
    private const TYPE_TO_LEVEL = [
        1 => self::LEVEL_VIP,
        2 => self::LEVEL_SVIP,
        3 => self::LEVEL_SUPREME,
    ];

    /** 同时支持反查 */
    private const LEVEL_TO_TYPE = [
        self::LEVEL_VIP => 1,
        self::LEVEL_SVIP => 2,
        self::LEVEL_SUPREME => 3,
    ];

    /** 别名数量上限 */
    public const MAX_ALIAS_HOSTS = 10;

    /** 对外展示用的中文名 */
    public static function levelLabel(string $level): string
    {
        return [
            self::LEVEL_NONE => '未授权',
            self::LEVEL_VIP => 'VIP',
            self::LEVEL_SVIP => 'SVIP',
            self::LEVEL_SUPREME => '至尊',
        ][$level] ?? '未知';
    }

    /**
     * 当前生效的授权记录。未激活返 null。
     *
     * ⚠️ 授权是**这个安装（数据库）**的属性，不是「本次请求域名」的属性：
     *    只要库里存了主授权域名，就返回授权记录；当前访问域名不是主授权域名、
     *    也不在别名里时，只是 `host_matched = false`，不再直接返回 null。
     *    调用方要严格按域名判断时看这个字段（后台首页据此提示
     *    「当前域名已由主授权域名关联激活」）。
     *
     * @return array<string, mixed>|null
     */
    public static function currentLicense(): ?array
    {
        $mainHostRaw = (string) Config::get('license_main_host', '');
        if ($mainHostRaw === '') {
            return null; // 未激活
        }
        $mainHost = self::normalizeHost($mainHostRaw);
        if ($mainHost === '') {
            return null; // 配置异常：解析不到有效 host
        }
        // 当前访问域名是否命中主授权域名 / 别名
        $req = self::normalizeHost(self::currentDomain());
        $aliases = array_values(array_filter(array_map([self::class, 'normalizeHost'], self::aliasHosts()), static fn(string $v): bool => $v !== ''));
        $hostMatched = (
            $req === '' ||
            $req === 'localhost' ||
            self::hostMatches($req, $mainHost) ||
            self::inHostList($req, $aliases)
        );

        $emkey = (string) Config::get('license_emkey', '');
        $type = (int) Config::get('license_emkey_type', '0');
        $level = self::TYPE_TO_LEVEL[$type] ?? self::LEVEL_NONE;

        return [
            'license_code' => $emkey,
            'level'        => $level,
            'level_label'  => self::levelLabel($level),
            'bound_domain' => $mainHost, // 给老调用方保持字段名
            'alias_hosts'  => $aliases,
            'host_matched' => $hostMatched,
        ];
    }

    /**
     * 当前域名的有效等级。未激活 → 'none'。
     */
    public static function currentLevel(): string
    {
        $row = self::currentLicense();
        return $row !== null ? (string) $row['level'] : self::LEVEL_NONE;
    }

    /** 是否已激活（任意等级）。 */
    public static function isActivated(): bool
    {
        return self::currentLevel() !== self::LEVEL_NONE;
    }

    /** 等级是否满足（比较权重）。 */
    public static function hasLevel(string $required): bool
    {
        $cur = self::LEVEL_WEIGHT[self::currentLevel()] ?? 0;
        $need = self::LEVEL_WEIGHT[$required] ?? 0;
        return $cur >= $need;
    }

    /**
     * 激活：把激活码绑到当前域名（code + domain），成功后把 emkey / type / main_host 写进 Config。
     *
     * 服务端对"本来就绑在这个域名上"也返回成功（bound=false），所以这里不需要区分，
     * 两种都当作激活成功处理。
     *
     * @return array{level:string, level_label:string, bound_domain:string}
     * @throws RuntimeException 激活码为空 / 已绑别的域名 / 码不存在 / 网络错误
     */
    public static function activate(string $licenseCode): array
    {
        $licenseCode = trim($licenseCode);
        if ($licenseCode === '') {
            throw new RuntimeException('请输入激活码');
        }

        $domain = self::currentDomain();
        $result = LicenseClient::bind($licenseCode, $domain);

        // license_type 是数字档位（1=VIP / 2=SVIP / 3=至尊），转成内部 level 字符串
        $type = (int) ($result['license_type'] ?? 0);
        $level = self::TYPE_TO_LEVEL[$type] ?? '';
        if ($level === '') {
            throw new RuntimeException('服务端返回的等级无效：' . var_export($result['license_type'] ?? null, true));
        }

        // 主授权域名以中心服务归一化后的为准（完整域名，含 www / 子域名）
        $mainHost = trim((string) ($result['domain'] ?? ''));
        if ($mainHost === '') {
            $mainHost = $domain;
        }

        Config::set('license_emkey', $licenseCode);
        Config::set('license_emkey_type', (string) $type);
        Config::set('license_main_host', $mainHost);

        return [
            'level'        => $level,
            'level_label'  => self::levelLabel($level),
            'bound_domain' => $mainHost,
        ];
    }

    /**
     * 解绑当前主授权域名。
     * 流程：远程解绑（code + domain）→ 只清 main_host + emkey_type；保留 emkey 和 alias_hosts。
     *
     * 正常情况下 main_host 和 emkey 是 activate() 一起写进去的，两个都在。
     * emkey 为空只可能是配置被手工改坏 —— 这时没有可提交的授权码，
     * 跳过远程、只清本地，免得把一个坏配置卡死在这里。
     *
     * @throws RuntimeException 未激活 / 接口判定域名与授权码不匹配 / 网络错误
     */
    public static function unbind(): void
    {
        $emkey = (string) Config::get('license_emkey', '');
        $mainHost = (string) Config::get('license_main_host', '');
        if ($mainHost === '') {
            throw new RuntimeException('当前未激活，无需解绑');
        }

        if ($emkey !== '') {
            // 远程解绑；网络失败/域名与码不匹配直接抛，本地不动
            LicenseClient::unbind($emkey, $mainHost);
        }

        Config::set('license_main_host', '');
        Config::set('license_emkey_type', '0');
    }

    /**
     * 周期性校验当前激活状态（进入 license 页或后台首页时触发）。
     *
     * 走 api/open/v1/em/license/status（传 domain + code，与 app-list / app-update-check 同口径）：
     *  - 本地没绑域名（未激活）→ 跳过，不发请求
     *  - authorized === false → 服务端明确判定未授权 → 清空本地授权（见 clearLocalAuthorization）
     *  - authorized === true  → 档位与服务端不一致就以服务端为准（改本地 emkey_type）
     *  - 字段缺失 / 网络异常  → 保守保留，不动本地
     *
     * 注意：base-data 的 authorized 字段**不参与**本地授权判定 —— 它只用于首页展示
     * （公告/广告/版本/代理商那几块）。判定只看这个专用接口。
     */
    public static function revalidateCurrent(): void
    {
        if ((string) Config::get('license_main_host', '') === '') {
            return;
        }

        try {
            $result = LicenseClient::status(
                self::effectiveHost(),
                (string) Config::get('license_emkey', '')
            );
        } catch (Throwable $e) {
            // 网络异常 / 服务端 500 等 → 保守保留
            return;
        }

        // var_dump($result); die;

        $authorized = $result['authorized'] ?? null;

        if ($authorized === false) {
            // 服务端明确判定未授权 → 清空本地授权
            self::clearLocalAuthorization();
            return;
        }

        if ($authorized !== true) {
            return; // 字段缺失（老服务端 / 响应异常）→ 不动本地，别误清
        }

        // 已授权：档位以服务端返回的为准（没授权时它是 null，走不到这里）
        $type = (int) ($result['license_type'] ?? 0);
        if (!isset(self::TYPE_TO_LEVEL[$type])) {
            return; // 授权有效但档位没给全 → 保留本地原值，不误清
        }
        if ((int) Config::get('license_emkey_type', '0') !== $type) {
            Config::set('license_emkey_type', (string) $type);
        }
    }

    /**
     * 清空本地授权：把站点打回「未授权」。
     *
     * 只清授权状态本身 —— main_host + emkey_type（判据见 currentLicense()）；
     * **emkey 与别名保留**，与 unbind() 的口径一致（换码 / 迁站后重新绑定还用得上）。
     *
     * 调用点：服务端在检测授权接口里**明确**回了 authorized === false
     * （license/status、应用商店列表 app-list、应用更新检测 app-update-check）——
     * 只有明确的 false 才清；字段缺失（老服务端）或网络异常一律不动本地。
     */
    public static function clearLocalAuthorization(): void
    {
        Config::set('license_main_host', '');
        Config::set('license_emkey_type', '0');
    }

    /**
     * 拉取后台基础数据（授权 / 代理商 / 公告 / 广告位 / 版本）并归一化。
     *
     * 数据源是 POST /api/open/v1/em/base-data（LicenseClient::baseData）。
     * 请求带「完整域名 + 本地保存的授权码」：上游据此按「码 + 域名」双重校验，
     * 域名取 effectiveHost()（有主授权域名就用它，没有才用当前访问域名）。
     * 后台首页 `_action=admin_index_data` 与代理商弹窗（admin/license.php?_popup=agent）
     * 共用这里的输出结构，所以字段名以本方法为准，调用方不要再自行解析原始响应。
     *
     * 返回结构：
     *   domain / license_type / license_label / identity_matched / used_fallback
     *   authorized  三态：true=已授权 / false=服务端明确判定未授权 / null=字段缺失
     *               —— 仅作展示与排查用，**不参与**本地授权判定（那只看激活码那条链路）
     *   update      {has_new, version, force, min_version, package_url}
     *   buy_links / download_links   [{name, url}]
     *   contact     固定 6 个键，wechat_qr 已补成完整图片地址
     *   announcements / ad_slots     公告与广告位（content 是富文本 HTML）
     *
     * @return array<string, mixed>
     * @throws RuntimeException 网络不可达 / 响应格式异常 / code != 200
     */
    public static function fetchBaseData(): array
    {
        $data = LicenseClient::baseData(
            defined('SERVICE_TOKEN') ? (string) SERVICE_TOKEN : '',
            self::effectiveHost(),
            defined('EM_VERSION') ? (string) EM_VERSION : '',
            // 本地保存的授权码：上游据此按「码 + 域名」双重校验（未激活时为空串）
            (string) Config::get('license_emkey', '')
        );

        // echo '<pre>'; print_r($data);die;

        return [
            'domain'           => (string) ($data['domain'] ?? ''),
            'authorized'       => array_key_exists('authorized', $data) ? ($data['authorized'] === true) : null,
            'license_type'     => (string) ($data['license_type'] ?? ''),
            'license_label'    => (string) ($data['license_type_label'] ?? ''),
            'identity_matched' => ($data['identity_matched'] ?? null) === true,
            'used_fallback'    => ($data['used_fallback'] ?? null) === true,
            'buy_links'        => self::normalizeLinks($data['buy_links'] ?? []),
            'download_links'   => self::normalizeLinks($data['download_links'] ?? []),
            'contact'          => self::normalizeContact($data['contact'] ?? []),
            'announcements'    => self::normalizeEntries($data['announcements'] ?? [], false),
            'ad_slots'         => self::normalizeEntries($data['ad_slots'] ?? [], true),
            'update'           => self::normalizeUpdate($data),
        ];
    }

    /**
     * 整理版本更新信息。
     *
     * 关键：min_version 不影响「有没有新版本」，只决定能不能用增量包 ——
     * 当前版本低于它时只能下完整安装包，因此这时把 package_url 置空，
     * 让前端退回「手动下载」（前端只认 package_url 非空 = 可以走在线升级）。
     *
     * @param array<string, mixed> $data 服务端 data 原文
     * @return array{has_new:bool, version:string, force:bool, min_version:string, package_url:string}
     */
    private static function normalizeUpdate(array $data): array
    {
        $hasNew     = ($data['has_new_version'] ?? null) === true;
        $minVersion = trim((string) ($data['min_version'] ?? ''));
        $patchUrl   = self::toAbsoluteUrl(trim((string) ($data['patch_package_url'] ?? '')));

        $current = defined('EM_VERSION') ? (string) EM_VERSION : '';
        $patchOk = $hasNew
            && $patchUrl !== ''
            && ($minVersion === '' || $current === '' || version_compare($current, $minVersion, '>='));

        return [
            'has_new'     => $hasNew,
            'version'     => (string) ($data['latest_version'] ?? ''),
            'force'       => ($data['force_update'] ?? null) === true,
            'min_version' => $minVersion,
            'package_url' => $patchOk ? $patchUrl : '',
        ];
    }

    /**
     * 归一化 [{name, url}] 链接列表：丢掉空 url，name 缺失时回退成 url。
     *
     * @param mixed $raw
     * @return array<int, array{name:string, url:string}>
     */
    private static function normalizeLinks($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) continue;
            $url = trim((string) ($item['url'] ?? ''));
            if ($url === '') continue;
            $name = trim((string) ($item['name'] ?? ''));
            $out[] = ['name' => $name !== '' ? $name : $url, 'url' => $url];
        }
        return $out;
    }

    /**
     * 归一化联系方式：固定 6 个键，没填的补空串。
     * wechat_qr 是相对地址（/uploads/...），补上线路域名才是能直接用的图片地址。
     *
     * @param mixed $raw
     * @return array<string, string>
     */
    private static function normalizeContact($raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach (['qq_service', 'qq_group', 'wechat_service', 'wechat_qr', 'tg_service', 'tg_group_url'] as $key) {
            $out[$key] = trim((string) ($raw[$key] ?? ''));
        }
        if ($out['wechat_qr'] !== '') {
            $out['wechat_qr'] = self::toAbsoluteUrl($out['wechat_qr']);
        }
        return $out;
    }

    /**
     * 归一化公告 / 广告位列表。广告位多一个 expires_on。
     *
     * content 是服务端已过滤的富文本 HTML，前端按 HTML 渲染 —— 这里不转义、不清洗，
     * 与项目其余「远程数据一律转义」的做法不同，是有意为之（见计划里的取舍说明）。
     *
     * @param mixed $raw
     * @return array<int, array<string, mixed>>
     */
    private static function normalizeEntries($raw, bool $withExpires): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) continue;
            $row = [
                'id'          => (int) ($item['id'] ?? 0),
                'title'       => (string) ($item['title'] ?? ''),
                'label'       => (string) ($item['label'] ?? ''),
                'label_color' => (string) ($item['label_color'] ?? ''),
                'content'     => (string) ($item['content'] ?? ''),
                'link_url'    => (string) ($item['link_url'] ?? ''),
                'created_at'  => (string) ($item['created_at'] ?? ''),
            ];
            if ($withExpires) {
                $row['expires_on'] = (string) ($item['expires_on'] ?? '');
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * 相对地址 → 补当前线路域名（线路没配好 / 解析失败时原样返回，不打断渲染）。
     */
    private static function toAbsoluteUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        try {
            return UpdateService::resolvePackageUrl($url);
        } catch (Throwable $e) {
            return $url;
        }
    }

    /**
     * 购买跳转 URL。
     *
     * @throws RuntimeException
     */
    public static function getBuyUrl(string $level, string $adminEmail = '', string $returnUrl = ''): string
    {
        if ($returnUrl === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $returnUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/admin/license.php?from=buy';
        }
        return LicenseClient::getBuyUrl($level, self::currentDomain(), $adminEmail, $returnUrl);
    }

    /**
     * 给所有中心服务调用用的 "有效 host"：优先 Config 里配的主授权域名，没配则回退当前 HTTP_HOST。
     *
     * 用法示例：`LicenseClient::appList(['domain' => LicenseService::effectiveHost(), ...])`
     */
    public static function effectiveHost(): string
    {
        $main = (string) Config::get('license_main_host', '');
        return $main !== '' ? $main : self::currentDomain();
    }

    /**
     * 读取别名域名列表。
     *
     * @return array<int, string>
     */
    public static function aliasHosts(): array
    {
        $raw = (string) Config::get('license_alias_hosts', '');
        if ($raw === '') return [];
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return [];
        return array_values(array_filter(array_map('strval', $decoded), static fn(string $v): bool => $v !== ''));
    }

    /**
     * 保存别名域名列表（整个数组覆盖）。
     *
     * 规则：去空白 → 去空行 → 去重 → 剔除和主授权域名一样的 → 最多保留前 MAX_ALIAS_HOSTS 个。
     *
     * @param string|array<int, string> $input 可以是 textarea 原文（一行一个）或数组
     */
    public static function saveAliasHosts($input): array
    {
        if (is_array($input)) {
            $lines = array_map('strval', $input);
        } else {
            $lines = preg_split('/\r\n|\r|\n/', (string) $input) ?: [];
        }

        $mainHost = strtolower((string) Config::get('license_main_host', ''));
        $seen = [];
        $out = [];
        foreach ($lines as $raw) {
            $v = strtolower(trim((string) $raw));
            if ($v === '') continue;
            if ($mainHost !== '' && $v === $mainHost) continue; // 和主的重了 → 跳过
            if (isset($seen[$v])) continue;                     // 已有 → 跳过
            $seen[$v] = true;
            $out[] = $v;
            if (count($out) >= self::MAX_ALIAS_HOSTS) break;
        }
        Config::set('license_alias_hosts', json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $out;
    }

    // ---------- 线路配置 ----------
    // 线路列表与当前索引都由 LicenseClient 提供（多线路）。请求不做自动换线：
    // 只走这里记的当前线路，用户在后台手动切换。

    /** 所有可用的授权服务器线路。 */
    public static function getAllLines(): array
    {
        return LicenseClient::lines();
    }

    /** 当前线路索引（越界回落 0）。 */
    public static function currentLineIndex(): int
    {
        return LicenseClient::currentLineIndex();
    }

    /** 切换当前线路。 @throws RuntimeException 索引越界 */
    public static function switchLine(int $idx): void
    {
        $lines = self::getAllLines();
        if ($idx < 0 || $idx >= count($lines)) {
            throw new RuntimeException('无效的线路索引');
        }
        Config::set('license_line_index', (string) $idx);
    }

    // ---------- 官方账号（官方站前台账号，与授权码无关） ----------
    // 只存 token + 展示用的账号信息 + 过期时间，不存密码。
    // token 是官方站签发的 JWT（scope=site），过期后按未登录处理。

    private const ACCOUNT_TOKEN_KEY  = 'official_account_token';
    private const ACCOUNT_USER_KEY   = 'official_account_user';
    private const ACCOUNT_EXPIRE_KEY = 'official_account_expire';

    /**
     * 登录官方账号并落库。返回账号信息（同 officialAccount()）。
     *
     * @throws RuntimeException 账号密码为空 / 登录失败 / 响应异常
     */
    public static function loginOfficialAccount(string $account, string $password): array
    {
        $account = trim($account);
        if ($account === '' || $password === '') {
            throw new RuntimeException('请输入账号和密码');
        }

        return self::storeOfficialAccount(LicenseClient::accountLogin($account, $password), '登录响应异常，请稍后重试');
    }

    /**
     * 发送邮箱验证码。走官方站 /api/auth/email-code。
     *
     * @param string $scene register（注册）或 login（验证码登录）
     * @throws RuntimeException 邮箱格式不对 / 场景不对 / 上游拒绝（限流等）
     */
    public static function sendOfficialEmailCode(string $email, string $scene = 'register'): void
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('邮箱格式不正确');
        }
        if (!in_array($scene, ['register', 'login'], true)) {
            throw new RuntimeException('无效的验证码场景');
        }

        LicenseClient::accountEmailCode($email, $scene);
    }

    /**
     * 验证码登录官方账号（邮箱 + 邮箱验证码，免密码）。成功即落 token。
     *
     * @throws RuntimeException 参数不合法 / 验证码错误 / 账号不存在 / 上游拒绝
     */
    public static function loginOfficialAccountByCode(string $email, string $code): array
    {
        $email = strtolower(trim($email));
        $code  = trim($code);

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('邮箱格式不正确');
        }
        if (strlen($code) !== 6 || !ctype_digit($code)) {
            throw new RuntimeException('请输入 6 位邮箱验证码');
        }

        return self::storeOfficialAccount(
            LicenseClient::accountLoginByCode($email, $code),
            '登录响应异常，请稍后重试'
        );
    }

    /**
     * 注册官方账号（邮箱 + 密码）。注册成功即登录，直接落 token。
     *
     * @throws RuntimeException 参数不合法 / 邮箱已注册 / 上游拒绝
     */
    public static function registerOfficialAccount(string $email, string $password, string $passwordConfirmation): array
    {
        $email = strtolower(trim($email));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('邮箱格式不正确');
        }
        if (strlen($password) < 6 || strlen($password) > 16) {
            throw new RuntimeException('密码长度需为 6-16 位');
        }
        if ($password !== $passwordConfirmation) {
            throw new RuntimeException('两次输入的密码不一致');
        }

        return self::storeOfficialAccount(LicenseClient::accountRegister([
            'email'                 => $email,
            'password'              => $password,
            'password_confirmation' => $passwordConfirmation,
            // 与登录一致：注册即签长期 token（官方站 10 年）
            'remember'              => 1,
        ]), '注册响应异常，请稍后重试');
    }

    /**
     * 解析登录/注册响应并落库（两者返回形状一致）。
     *
     * @param array<string, mixed> $data 服务端 data：{token, expires_in, user{...}}
     * @throws RuntimeException 响应缺 token / user
     */
    private static function storeOfficialAccount(array $data, string $errorMessage): array
    {
        $token = trim((string) ($data['token'] ?? ''));
        $user  = is_array($data['user'] ?? null) ? $data['user'] : [];
        if ($token === '' || $user === []) {
            throw new RuntimeException($errorMessage);
        }

        $profile = self::profileFromUser($user);
        $expiresIn = (int) ($data['expires_in'] ?? 0);

        Config::set(self::ACCOUNT_TOKEN_KEY, $token);
        Config::set(self::ACCOUNT_USER_KEY, (string) json_encode($profile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        Config::set(self::ACCOUNT_EXPIRE_KEY, (string) ($expiresIn > 0 ? time() + $expiresIn : 0));

        return $profile;
    }

    /**
     * 上游用户行 → 本地展示快照（只留展示字段，不存密码等敏感项）。
     *
     * @param array<string, mixed> $user
     * @return array{id:int,username:string,email:string,nickname:string,avatar:string,role:string}
     */
    private static function profileFromUser(array $user): array
    {
        return [
            'id'       => (int) ($user['id'] ?? 0),
            'username' => (string) ($user['username'] ?? ''),
            'email'    => (string) ($user['email'] ?? ''),
            'nickname' => (string) ($user['nickname'] ?? ''),
            'avatar'   => (string) ($user['avatar'] ?? ''),
            'role'     => (string) ($user['role'] ?? ''),
        ];
    }

    /**
     * 当前官方账号名下的全部激活码（实时向官方站拉取，不是本站授权信息）。
     *
     * @return array<int, array<string, mixed>>
     * @throws RuntimeException 未登录 / token 失效 / 网络失败
     */
    public static function officialAccountLicenses(): array
    {
        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        return LicenseClient::myLicenses($token);
    }

    /**
     * 当前官方账号的资料（实时向官方站 /api/auth/me 拉取）。
     *
     * 只用于「个人资料」弹窗展示；返回上游用户表整行，展示层按需取。
     * 失败（token 失效 / 网络不通）抛异常，由调用方提示。
     *
     * @return array<string, mixed>
     * @throws RuntimeException 未登录 / token 失效 / 网络失败
     */
    public static function officialAccountProfile(): array
    {
        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        return LicenseClient::accountProfile($token);
    }

    /**
     * 当前官方账号概况：账号资料 + 名下激活码数量（实时向官方站拉取）。
     *
     * 宫格页只用这一个请求就够 —— 之前分别拉 /auth/me 和 /my/licenses，
     * 上游慢的时候一个页面要等两轮。返回 data：{user, licenses_count}
     *
     * @return array<string, mixed>
     * @throws RuntimeException 未登录 / token 失效 / 网络失败
     */
    public static function officialAccountOverview(): array
    {
        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        $data = LicenseClient::accountOverview($token);

        // 顺手把最新资料写回本地快照：下次打开窗口，头部先渲染的就是新昵称/邮箱/角色
        $user = is_array($data['user'] ?? null) ? $data['user'] : [];
        if ($user !== []) {
            Config::set(self::ACCOUNT_USER_KEY, (string) json_encode(
                self::profileFromUser($user),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        }

        return $data;
    }

    /**
     * 当前官方账号的余额变动明细（实时向官方站拉取）。
     *
     * 上游一次返回明细 + 当前余额，窗口里余额和列表同源，不会一新一旧。
     *
     * @return array<string, mixed>
     * @throws RuntimeException 未登录 / token 失效 / 网络失败
     */
    public static function officialAccountBalanceLogs(int $page = 1, int $perPage = 10): array
    {
        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        return LicenseClient::accountBalanceLogs($token, $page, $perPage);
    }

    /**
     * 把激活码绑定到当前官方账号。
     *
     * 只接受「还没被任何人绑定」的码：池子里未发出的、或无主（user_id 为空）的旧码。
     * 已被其他账号绑定的会原样抛上游的「该激活码已被其他账号绑定」。
     *
     * @return array<string, mixed> 上游 data（含 license）
     * @throws RuntimeException 未登录 / 激活码为空 / 已被其他账号绑定 / 网络失败
     */
    public static function officialAccountBindLicense(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('请输入激活码');
        }

        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        return LicenseClient::accountBindLicense($token, $code);
    }

    /**
     * 移除授权：清空该激活码在上游的授权域名，并同步清掉本机的授权状态。
     *
     * 只清「本机正在用的那个码」的本地授权（main_host + emkey_type），
     * 清别的码不该把当前站点打回未授权。上游失败时不改本地。
     *
     * @return array{license:array<string,mixed>, local_cleared:bool}
     * @throws RuntimeException 未登录 / 激活码为空 / 上游拒绝 / 网络失败
     */
    public static function officialAccountRemoveLicense(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('请输入激活码');
        }

        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        // 先远程：失败直接抛，本地保持原样（与 unbind() 同口径）
        $result = LicenseClient::accountRemoveLicense($token, $code);

        $localKey = self::normalizeLicenseCode((string) Config::get('license_emkey', ''));
        $localCleared = false;
        if ($localKey !== '' && $localKey === self::normalizeLicenseCode($code)) {
            self::clearLocalAuthorization();
            $localCleared = true;
        }

        return [
            'license'       => is_array($result['license'] ?? null) ? $result['license'] : [],
            'local_cleared' => $localCleared,
        ];
    }

    /**
     * 重置授权码：换一个新码，旧码立即失效（域名、档位不动）。
     *
     * 如果这条码就是本机在用的那个，本地保存的激活码要同步换成新码，
     * 否则本机会继续拿已失效的旧码去校验、直接变未授权。
     *
     * @return array{license:array<string,mixed>, local_updated:bool}
     * @throws RuntimeException 未登录 / 激活码为空 / 上游拒绝 / 网络失败
     */
    public static function officialAccountResetLicense(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('请输入激活码');
        }

        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        $result = LicenseClient::accountResetLicense($token, $code);
        $license = is_array($result['license'] ?? null) ? $result['license'] : [];
        $newCode = trim((string) ($license['code'] ?? ''));

        $localCode = self::normalizeLicenseCode((string) Config::get('license_emkey', ''));
        $localUpdated = false;
        if ($newCode !== '' && $localCode !== '' && $localCode === self::normalizeLicenseCode($code)) {
            Config::set('license_emkey', $newCode);
            $localUpdated = true;
        }

        return [
            'license'       => $license,
            'local_updated' => $localUpdated,
        ];
    }

    /**
     * 当前官方账号的代理端配置：身份标识 / 联系方式 / EMSHOP 链接。
     *
     * 只读：本站**不保存**这些配置，每次打开「代理配置」窗口都从上游取最新值。
     *
     * @return array<string, string>
     * @throws RuntimeException 未登录 / token 失效 / 网络失败
     */
    public static function officialAccountAgentConfig(): array
    {
        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        $data = LicenseClient::accountConfig($token);
        $config = is_array($data['config'] ?? null) ? $data['config'] : [];

        $out = [];
        foreach ($config as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    /**
     * 保存代理端配置：本地界面改，直接把值同步到上游（本站不落库）。
     *
     * 保存必须带当前账号的登录密码，由上游校验后才写入；
     * 密码本身不保存、不回显。
     *
     * @param array<string, string> $config 白名单字段
     * @return array<string, string> 保存后的完整配置
     * @throws RuntimeException 未登录 / 密码为空 / 密码错误 / 校验失败 / 网络失败
     */
    public static function officialAccountSaveAgentConfig(array $config, string $password): array
    {
        // 密码保持原样提交（不 trim，免得吃掉密码里的首尾空格）
        if (trim($password) === '') {
            throw new RuntimeException('请输入登录密码');
        }

        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        $identity = trim((string) ($config['identity'] ?? ''));
        if ($identity === '') {
            throw new RuntimeException('请输入身份标识');
        }

        // 只提交白名单字段；上游没登记的键传了也写不进去
        $allowed = [
            'identity',
            'qq_service',
            'qq_group',
            'wechat_service',
            'wechat_qr',
            'tg_service',
            'tg_group_url',
            'em_buy_links',
            'em_download_links',
        ];

        $payload = [];
        foreach ($allowed as $key) {
            $payload[$key] = trim((string) ($config[$key] ?? ''));
        }
        $payload['password'] = $password;

        $data = LicenseClient::accountConfigUpdateWithPassword($token, $payload);
        $saved = is_array($data['config'] ?? null) ? $data['config'] : [];

        $out = [];
        foreach ($saved as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    /**
     * 代理的激活码仓库：我生成的码 + 价格表 + 余额（上游一次返回）。
     *
     * $usage = unused（默认，未绑用户且未绑域名）/ used（绑了用户或域名）。
     *
     * @return array<string, mixed>
     * @throws RuntimeException 未登录 / 无代理权限 / 网络失败
     */
    public static function officialAccountEmCodes(int $page = 1, int $perPage = 10, string $usage = 'unused'): array
    {
        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        $data = LicenseClient::accountEmCodes($token, $page, $perPage, $usage);

        // 生成激活码不允许透支：上游的 affordable 是「余额 + 可透支额度」算出来的，
        // 这里按当前余额重算，界面上的「最多还能生成几个」只认账号自己的钱。
        $balanceCents = self::moneyToCents((string) ($data['balance'] ?? '0'));
        $pricing = is_array($data['pricing'] ?? null) ? $data['pricing'] : null;

        if ($pricing !== null) {
            $fees = is_array($pricing['fees'] ?? null) ? $pricing['fees'] : [];

            foreach ($fees as $key => $fee) {
                $feeCents = self::moneyToCents((string) ($fee['fee'] ?? '0'));
                $fees[$key]['affordable'] = $feeCents > 0
                    ? intdiv(max(0, $balanceCents), $feeCents)
                    : 0;
            }

            $pricing['fees'] = $fees;
            $pricing['overdraft'] = '0.00';
            $data['pricing'] = $pricing;
        }

        return $data;
    }

    /**
     * 生成一批激活码：按档位单价从上游账号余额扣费。
     *
     * @return array{codes:array<int,string>, cost:string, balance:string}
     * @throws RuntimeException 未登录 / 档位不对 / 价格未配置 / 余额不足 / 网络失败
     */
    public static function officialAccountGenerateEmCodes(string $type, int $count): array
    {
        if (!in_array($type, ['1', '2', '3'], true)) {
            throw new RuntimeException('无效的授权档位');
        }

        $count = max(1, min(200, $count));

        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        // 不允许透支：先按当前余额核对一遍（上游按站长配置可能仍放行透支），
        // 钱不够就在本地拦掉，不白白发一次生成请求。
        $wallet = LicenseClient::accountEmCodes($token, 1, 1);
        $pricing = is_array($wallet['pricing'] ?? null) ? $wallet['pricing'] : [];

        if (empty($pricing['configured'])) {
            throw new RuntimeException('站长还没配好授权码价格，暂时生成不了');
        }

        $fee = $pricing['fees'][$type]['fee'] ?? '';
        if ($fee === '') {
            throw new RuntimeException('该档位还没配置价格，暂时生成不了');
        }

        $feeCents     = self::moneyToCents((string) $fee);
        $costCents    = $feeCents * $count;
        $balanceCents = self::moneyToCents((string) ($wallet['balance'] ?? '0'));

        if ($costCents > 0 && $costCents > $balanceCents) {
            $affordable = $feeCents > 0 ? intdiv(max(0, $balanceCents), $feeCents) : 0;

            throw new RuntimeException(sprintf(
                '余额不足：生成 %d 个需要 %s 元，当前余额 %s 元，最多还能生成 %d 个',
                $count,
                self::centsToMoney($costCents),
                self::centsToMoney($balanceCents),
                $affordable
            ));
        }

        $data = LicenseClient::accountGenerateEmCodes($token, $type, $count);

        return [
            'codes'   => is_array($data['codes'] ?? null) ? array_values($data['codes']) : [],
            'cost'    => (string) ($data['cost'] ?? ''),
            'balance' => (string) ($data['balance'] ?? ''),
        ];
    }

    /** 官方账号 token；未登录 / 已过期直接抛（这一组新接口共用）。 */
    private static function officialTokenOrFail(): string
    {
        if (self::officialAccount() === []) {
            throw new RuntimeException('请先登录官方账号');
        }

        $token = (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
        if ($token === '') {
            throw new RuntimeException('请先登录官方账号');
        }

        return $token;
    }

    /** 代理版本安装包（上游只给 EMSHOP 已发布的版本）。 */
    public static function officialAccountVersions(int $page = 1, int $perPage = 10): array
    {
        return LicenseClient::accountVersions(self::officialTokenOrFail(), $page, $perPage);
    }

    /** 下载版本源码包（二进制；文件名 / 错误信息都来自上游）。 */
    public static function officialAccountVersionSource(int $id): array
    {
        if ($id <= 0) {
            throw new RuntimeException('无效的版本');
        }

        return LicenseClient::accountVersionSource(self::officialTokenOrFail(), $id);
    }

    /** 代理订单记录（只读；归属由上游按 token 过滤）。 */
    public static function officialAccountOrders(array $params): array
    {
        return LicenseClient::accountOrders(self::officialTokenOrFail(), $params);
    }

    /** 我的广告位列表（只做 EMSHOP）。 */
    public static function officialAccountAdSlots(array $params): array
    {
        return LicenseClient::accountAdSlots(self::officialTokenOrFail(), $params);
    }

    /** 广告位详情（编辑时拉正文）。 */
    public static function officialAccountAdSlotDetail(int $id): array
    {
        if ($id <= 0) {
            throw new RuntimeException('无效的广告位');
        }

        return LicenseClient::accountAdSlotDetail(self::officialTokenOrFail(), $id);
    }

    /**
     * 新建 / 修改广告位。`$id` 为空 = 新建。
     *
     * 只透传上游写入白名单里的字段；`agent_id` 由上游按 token 注入，这里不碰。
     */
    public static function officialAccountAdSlotSave(?int $id, array $fields): array
    {
        $allowed = ['title', 'label', 'label_color', 'content', 'link_url', 'expires_on', 'status'];
        $payload = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }

            $payload[$key] = $key === 'status'
                ? (int) $fields[$key]
                : (string) $fields[$key];
        }

        if (trim((string) ($payload['title'] ?? '')) === '') {
            throw new RuntimeException('请填名称');
        }

        if (trim((string) ($payload['expires_on'] ?? '')) === '') {
            throw new RuntimeException('请填到期日');
        }

        $token = self::officialTokenOrFail();

        return ($id !== null && $id > 0)
            ? LicenseClient::accountAdSlotUpdate($token, $id, $payload)
            : LicenseClient::accountAdSlotCreate($token, $payload);
    }

    /** 只改广告位的启用 / 停用状态（不动正文）。 */
    public static function officialAccountAdSlotStatus(int $id, int $status): void
    {
        if ($id <= 0) {
            throw new RuntimeException('无效的广告位');
        }

        LicenseClient::accountAdSlotUpdate(
            self::officialTokenOrFail(),
            $id,
            ['status' => $status === 0 ? 0 : 1],
        );
    }

    /** 删除广告位（上游按 token 校验归属）。 */
    public static function officialAccountAdSlotDelete(int $id): void
    {
        if ($id <= 0) {
            throw new RuntimeException('无效的广告位');
        }

        LicenseClient::accountAdSlotDelete(self::officialTokenOrFail(), $id);
    }

    /** 广告位上移 / 下移。 */
    public static function officialAccountAdSlotMove(int $id, string $direction): void
    {
        if ($id <= 0) {
            throw new RuntimeException('无效的广告位');
        }

        if (!in_array($direction, ['up', 'down'], true)) {
            throw new RuntimeException('无效的排序方向');
        }

        LicenseClient::accountAdSlotMove(self::officialTokenOrFail(), $id, $direction);
    }

    /** 代理安装记录（只读；归属由上游按 token 过滤）。 */
    public static function officialAccountInstallRecords(array $params): array
    {
        return LicenseClient::accountInstallRecords(self::officialTokenOrFail(), $params);
    }

    /** 金额字符串（元）转分：按字符串拆，不走浮点，避免 0.1 + 0.2 那类误差。 */
    private static function moneyToCents(string $money): int
    {
        $money = trim($money);
        if ($money === '') {
            return 0;
        }

        $negative = substr($money, 0, 1) === '-';
        if ($negative) {
            $money = substr($money, 1);
        }

        $parts = explode('.', $money, 2);
        $yuan  = (int) preg_replace('/\D/', '', $parts[0]);
        $cent  = substr((string) preg_replace('/\D/', '', $parts[1] ?? ''), 0, 2);
        $cents = $yuan * 100 + (int) str_pad($cent, 2, '0');

        return $negative ? -$cents : $cents;
    }

    /** 分转金额字符串（元，两位小数）。 */
    private static function centsToMoney(int $cents): string
    {
        $negative = $cents < 0;
        $cents    = abs($cents);

        return ($negative ? '-' : '') . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** 激活码归一：去空白、转大写（连字符保留，与上游 normalizeCode 同口径）。 */
    private static function normalizeLicenseCode(string $code): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $code));
    }

    /** 当前官方账号 token（只给服务端内部请求带身份用，不要输出到页面）。 */
    public static function officialAccountToken(): string
    {
        return (string) Config::get(self::ACCOUNT_TOKEN_KEY, '');
    }

    /** 当前登录的官方账号（未登录 / 已过期返回空数组）。只读本地配置，不发请求。 */
    public static function officialAccount(): array
    {
        if ((string) Config::get(self::ACCOUNT_TOKEN_KEY, '') === '') {
            return [];
        }

        $expire = (int) Config::get(self::ACCOUNT_EXPIRE_KEY, '0');
        if ($expire > 0 && $expire < time()) {
            self::logoutOfficialAccount();
            return [];
        }

        $user = json_decode((string) Config::get(self::ACCOUNT_USER_KEY, ''), true);
        return is_array($user) && $user !== [] ? $user : [];
    }

    /** 退出官方账号：清掉本地 token 与账号展示信息。 */
    public static function logoutOfficialAccount(): void
    {
        Config::set(self::ACCOUNT_TOKEN_KEY, '');
        Config::set(self::ACCOUNT_USER_KEY, '');
        Config::set(self::ACCOUNT_EXPIRE_KEY, '0');
    }


    /** 取当前请求域名（去端口）。*/
    private static function currentDomain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') return 'localhost';
        $p = strpos($host, ':');
        return strtolower($p === false ? $host : substr($host, 0, $p));
    }

    /** 归一化输入为 host（小写、去协议/路径/端口/首尾点）。 */
    private static function normalizeHost(string $input): string
    {
        $s = strtolower(trim($input));
        if ($s === '') return '';

        // 兼容用户误填：example.com/xxx 或 https://example.com:443
        if (strpos($s, '://') !== false) {
            $h = (string) (parse_url($s, PHP_URL_HOST) ?? '');
        } else {
            $s2 = preg_replace('/[\/?#].*$/', '', $s) ?? $s; // 去路径/查询/片段
            $p = strpos($s2, ':');                           // 去端口
            $h = $p === false ? $s2 : substr($s2, 0, $p);
        }

        $h = trim($h, ". \t\n\r\0\x0B");
        return $h;
    }

    /**
     * 判断请求域名是否命中授权域名：
     * - 完全相等
     * - 或者是其子域（以 ".{$licensed}" 结尾）
     */
    private static function hostMatches(string $req, string $licensed): bool
    {
        if ($req === '' || $licensed === '') return false;
        if ($req === $licensed) return true;

        // IP 场景不做子域匹配
        if (filter_var($req, FILTER_VALIDATE_IP) || filter_var($licensed, FILTER_VALIDATE_IP)) {
            return false;
        }

        return self::endsWith($req, '.' . $licensed);
    }

    /** @param array<int, string> $list */
    private static function inHostList(string $req, array $list): bool
    {
        foreach ($list as $licensed) {
            if (self::hostMatches($req, $licensed)) return true;
        }
        return false;
    }

    private static function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') return true;
        $hl = strlen($haystack);
        $nl = strlen($needle);
        if ($nl > $hl) return false;
        return substr($haystack, $hl - $nl) === $needle;
    }
}
