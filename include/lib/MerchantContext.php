<?php

declare(strict_types=1);

/**
 * 商户（分站）上下文识别器。
 *
 * 在 init.php 中调用 resolve() 一次，后续代码通过静态方法读取当前商户。
 *
 * 识别优先级：
 *   1. 自定义顶级域名   —— 需商户等级 allow_custom_domain=1
 *   2. 二级域名         —— 需商户等级 allow_subdomain=1
 *   3. 以上都不命中 → 主站（currentId()=0）
 *
 * 约定：任何数据查询都通过 MerchantContext::currentId() 拿当前商户 id，
 * 不要自己从 $_GET / $_SERVER 解析。
 */
final class MerchantContext
{
    /** @var array<string, mixed>|null 当前商户行（含 level_* 字段），null=主站 */
    private static $current = null;

    /** @var bool 是否已执行过 resolve */
    private static $resolved = false;

    /**
     * 等级门控拦截原因：'' = 未拦截，'subdomain' / 'custom_domain' = 对应通道被等级禁用。
     * 仅在"host 命中一个真实商户但商户等级不允许该入口方式"时被置位。
     * Dispatcher 会据此渲染"店铺暂未开放"页，避免静默降级到主站内容给访客。
     */
    private static $blockedReason = '';

    /**
     * 识别当前请求的商户上下文。
     * 幂等：重复调用只执行一次。
     */
    public static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;

        if (php_sapi_name() === 'cli') {
            return;
        }

        // 总开关未开则全部视为主站（保留兼容旧 substation_enabled）
        $enabled = Config::get('substation_enabled');
        if ($enabled !== null && $enabled !== '' && !self::truthy($enabled)) {
            return;
        }

        $host = self::currentHost();
        if ($host === '') return;

        // 主站域名永远按主站处理：即使 em_merchant 里存在 custom_domain 等于主站域名的
        // 脏数据（历史遗留或直接改库），也不能让商户接管主站。
        if (MerchantDomainGuard::isMainHost($host)) {
            return;
        }

        // 1. 自定义顶级域名 —— 命中后再做等级门控，命中但被拦也要明确告知（blockedReason）
        $merchant = self::findByCustomDomain($host);
        if ($merchant !== null) {
            if ((int) ($merchant['level_allow_custom_domain'] ?? 0) !== 1) {
                self::$blockedReason = 'custom_domain';
                return;
            }
            self::$current = $merchant;
            self::rememberLastMerchant($merchant);
            return;
        }

        // 2. 二级域名 —— 同上
        $merchant = self::findBySubdomain($host);
        if ($merchant !== null) {
            if ((int) ($merchant['level_allow_subdomain'] ?? 0) !== 1) {
                self::$blockedReason = 'subdomain';
                return;
            }
            self::$current = $merchant;
            self::rememberLastMerchant($merchant);
            return;
        }

        // 其它 host → 主站
    }

    /**
     * 当前 host 命中了一个真实商户但商户等级不允许该入口方式时，返回拦截原因字符串。
     * 未拦截 / 已成功识别 → 空串。
     * 典型值：'subdomain' / 'custom_domain'
     */
    public static function blockedReason(): string
    {
        return self::$blockedReason;
    }

    /**
     * 当前商户 id；主站返回 0。
     */
    public static function currentId(): int
    {
        return self::$current === null ? 0 : (int) self::$current['id'];
    }

    /**
     * 当前商户主 user_id；主站返回 0。
     * 用于 owner_id 过滤。
     */
    public static function currentOwnerId(): int
    {
        return self::$current === null ? 0 : (int) self::$current['user_id'];
    }

    /**
     * 当前商户完整行（含内嵌 level_* 字段）；主站返回 null。
     *
     * @return array<string, mixed>|null
     */
    public static function current(): ?array
    {
        return self::$current;
    }

    /**
     * 是否当前请求在主站上下文。
     */
    public static function isMaster(): bool
    {
        return self::$current === null;
    }

    /**
     * 判断某条商品记录是否对"当前请求的作用域"可见。
     *
     * 规则：
     *   - 主站上下文：仅 owner_id = 0（主站自营）可见
     *   - 商户上下文：owner_id = 0（主站引用）或 owner_id = 当前商户主用户 id（本店自建）可见
     *     —— 商户不能越权访问别家商户的自建商品
     *
     * 用于 GoodsController::displayContent / OrderController 的下单校验等，
     * 避免"漏到主站前台"或"跨商户越权下单"。
     *
     * @param array<string, mixed> $goods em_goods 行（至少含 owner_id 字段）
     */
    public static function isGoodsVisibleToCurrentScope(array $goods): bool
    {
        $ownerId = (int) ($goods['owner_id'] ?? 0);
        $merchantId = self::currentId();
        if ($merchantId <= 0) {
            return $ownerId === 0; // 主站：只看主站自营
        }
        return $ownerId === 0 || $ownerId === self::currentOwnerId();
    }

    /**
     * 测试场景手动设置当前商户（生产代码不要用）。
     *
     * @param array<string, mixed>|null $merchant
     */
    public static function setCurrent(?array $merchant): void
    {
        self::$current = $merchant;
        self::$resolved = true;
    }

    /**
     * 计算商户的店铺前台 URL：
     *   1. 已绑定自定义顶级域名     → https://{custom_domain}/
     *   2. 二级域名 + 主站域名已配   → https://{subdomain}.{main_domain}/
     *   3. 都没配                   → "" （店铺无法访问）
     *
     * @param array<string, mixed> $merchant
     */
    public static function storefrontUrl(array $merchant): string
    {
        $custom = trim((string) ($merchant['custom_domain'] ?? ''));
        if ($custom !== '') {
            return 'http://' . $custom . '/';
        }
        $sub = trim((string) ($merchant['subdomain'] ?? ''));
        $main = self::normalizeHost((string) (Config::get('main_domain') ?? ''));
        if ($sub !== '' && $main !== '') {
            return 'http://' . $sub . '.' . $main . '/';
        }
        return '';
    }

    /**
     * 把"最近访问的店铺"写入 session，供个人中心顶部显示"返回 xxx 店铺"按钮。
     * 仅最小字段（id/name/url），避免敏感字段写进 session。
     *
     * @param array<string, mixed> $merchant
     */
    public static function rememberLastMerchant(array $merchant): void
    {
        if (php_sapi_name() === 'cli') return;
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $_SESSION['em_last_merchant'] = [
            'id'   => (int) ($merchant['id'] ?? 0),
            'name' => (string) ($merchant['name'] ?? ''),
            'url'  => self::storefrontUrl($merchant),
        ];
    }

    /**
     * 读取 session 里最近访问过的店铺。未曾访问 → null。
     *
     * @return array{id:int, name:string, url:string}|null
     */
    public static function lastMerchant(): ?array
    {
        if (php_sapi_name() === 'cli') return null;
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $m = $_SESSION['em_last_merchant'] ?? null;
        if (!is_array($m) || (int) ($m['id'] ?? 0) <= 0) return null;
        return [
            'id'   => (int) $m['id'],
            'name' => (string) ($m['name'] ?? ''),
            'url'  => (string) ($m['url'] ?? ''),
        ];
    }

    // ---------------------------------------------------------------
    // 内部实现
    // ---------------------------------------------------------------

    /**
     * 取当前请求 Host（去掉端口）。
     */
    private static function currentHost(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '') {
            return '';
        }
        return self::normalizeHost($host);
    }

    /**
     * 按自定义顶级域名匹配。
     *
     * 只负责查找"是否有商户绑定了这个 host"，等级门控（level_allow_custom_domain）交给
     * resolve() 处理 —— 这样能区分"根本没这个商户"（返回 null）和"有但等级禁用"（命中行但
     * 上层拒收并设置 blockedReason）两种情况。
     */
    private static function findByCustomDomain(string $host): ?array
    {
        $sql = 'SELECT m.*, l.allow_custom_domain AS level_allow_custom_domain,
                       l.allow_subdomain AS level_allow_subdomain,
                       l.allow_self_goods AS level_allow_self_goods,
                       l.self_goods_fee_rate AS level_self_goods_fee_rate,
                       l.withdraw_fee_rate AS level_withdraw_fee_rate,
                       l.name AS level_name
                  FROM `' . Database::prefix() . 'merchant` m
             LEFT JOIN `' . Database::prefix() . 'merchant_level` l ON l.id = m.level_id
                 WHERE m.custom_domain = ?
                   AND m.status = 1
                   AND m.deleted_at IS NULL
                 LIMIT 1';
        return self::safeFetch($sql, [$host]);
    }

    /**
     * 按二级域名匹配。
     * 主站域名从 Config::get('main_domain') 读；未配置则跳过。
     */
    private static function findBySubdomain(string $host): ?array
    {
        $mainDomain = self::normalizeHost((string) (Config::get('main_domain') ?? ''));
        if ($mainDomain === '') {
            // 兼容：主域名未配置时，退化为按 host 首段子域名匹配。
            // 例：1022.em.cc -> subdomain=1022。
            return self::findBySubdomainLoose($host);
        }
        $suffix = '.' . $mainDomain;
        if (!str_ends_with($host, $suffix)) {
            return null;
        }
        $subdomain = substr($host, 0, strlen($host) - strlen($suffix));
        if ($subdomain === '' || $subdomain === 'www') {
            return null;
        }
        // 禁止多层（shop1.a 可以，shop1.a.b 不行）
        if (strpos($subdomain, '.') !== false) {
            return null;
        }

        $sql = 'SELECT m.*, l.allow_custom_domain AS level_allow_custom_domain,
                       l.allow_subdomain AS level_allow_subdomain,
                       l.allow_self_goods AS level_allow_self_goods,
                       l.self_goods_fee_rate AS level_self_goods_fee_rate,
                       l.withdraw_fee_rate AS level_withdraw_fee_rate,
                       l.name AS level_name
                  FROM `' . Database::prefix() . 'merchant` m
             LEFT JOIN `' . Database::prefix() . 'merchant_level` l ON l.id = m.level_id
                 WHERE m.subdomain = ?
                   AND m.status = 1
                   AND m.deleted_at IS NULL
                 LIMIT 1';
        // 等级门控（level_allow_subdomain）交给 resolve() 处理，便于区分"没这个商户"和
        // "有但等级禁用"两种状况 —— 后者要明确 403 提示访客，而不是静默降级到主站
        return self::safeFetch($sql, [$subdomain]);
    }

    /**
     * 在未配置 main_domain 时，按 host 第一段尝试匹配 subdomain。
     *
     * 约束：
     * - host 至少两段（a.b）
     * - 首段不能是 www
     * - 首段只允许字母/数字/短横线
     */
    private static function findBySubdomainLoose(string $host): ?array
    {
        $parts = array_values(array_filter(explode('.', strtolower($host))));
        if (count($parts) < 2) {
            return null;
        }
        $subdomain = (string) ($parts[0] ?? '');
        if ($subdomain === '' || $subdomain === 'www') {
            return null;
        }
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,62}[a-z0-9])?$/', $subdomain)) {
            return null;
        }

        $sql = 'SELECT m.*, l.allow_custom_domain AS level_allow_custom_domain,
                       l.allow_subdomain AS level_allow_subdomain,
                       l.allow_self_goods AS level_allow_self_goods,
                       l.self_goods_fee_rate AS level_self_goods_fee_rate,
                       l.withdraw_fee_rate AS level_withdraw_fee_rate,
                       l.name AS level_name
                  FROM `' . Database::prefix() . 'merchant` m
             LEFT JOIN `' . Database::prefix() . 'merchant_level` l ON l.id = m.level_id
                 WHERE m.subdomain = ?
                   AND m.status = 1
                   AND m.deleted_at IS NULL
                 LIMIT 1';
        return self::safeFetch($sql, [$subdomain]);
    }

    /**
     * 查询包容安装未完成 / 表不存在的场景，返回 null 不抛异常。
     *
     * @param array<int, mixed> $params
     * @return array<string, mixed>|null
     */
    private static function safeFetch(string $sql, array $params): ?array
    {
        try {
            $row = Database::fetchOne($sql, $params);
            return $row !== null ? $row : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * 归一化 host 字符串（兼容把 main_domain 配成 https://x:port/path 的场景）。
     */
    private static function normalizeHost(string $value): string
    {
        return MerchantDomainGuard::normalizeHost($value);
    }

    /**
     * 把配置值解析为布尔（兼容 "1"/"0"/"y"/"n"/"true"/"false"）。
     *
     * @param mixed $v
     */
    private static function truthy($v): bool
    {
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v !== 0;
        $s = strtolower(trim((string) $v));
        return $s === '1' || $s === 'y' || $s === 'yes' || $s === 'true' || $s === 'on';
    }
}
