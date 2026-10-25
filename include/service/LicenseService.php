<?php

declare(strict_types=1);

/**
 * 站点正版授权服务。
 *
 * 存储模型：全部落 Config 表（一个实例一份激活状态）
 *   license_emkey         激活码（明文，方便重新绑定用）
 *   license_emkey_type    等级整数：1=VIP 2=SVIP 3=至尊
 *   license_main_host     主授权域名（中心服务返回归一化后的 host）
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
        // 如果当前请求域名既不是主也不在别名里，则不认可"本次访问是授权通过的"
        // 允许访问：main OR alias
        $req = self::normalizeHost(self::currentDomain());
        $aliases = array_values(array_filter(array_map([self::class, 'normalizeHost'], self::aliasHosts()), static fn(string $v): bool => $v !== ''));
        $allow = (
            $req === '' ||
            $req === 'localhost' ||
            self::hostMatches($req, $mainHost) ||
            self::inHostList($req, $aliases)
        );
        if (!$allow) {
            return null;
        }

        $emkey = (string) Config::get('license_emkey', '');
        $type = (int) Config::get('license_emkey_type', '0');
        $level = self::TYPE_TO_LEVEL[$type] ?? self::LEVEL_NONE;

        return [
            'license_code' => $emkey,
            'level'        => $level,
            'level_label'  => self::levelLabel($level),
            'bound_domain' => $mainHost, // 给老调用方保持字段名
            'alias_hosts'  => $aliases,
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

        // 主授权域名以中心服务归一化后的为准（顶级域名）
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
     * 后台首页 `_action=admin_index_data` 与代理商弹窗（admin/license.php?_popup=agent）
     * 共用这里的输出结构，所以字段名以本方法为准，调用方不要再各自解析原始响应。
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
            defined('EM_VERSION') ? (string) EM_VERSION : ''
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
