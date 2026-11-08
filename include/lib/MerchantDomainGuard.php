<?php

declare(strict_types=1);

/**
 * 商户域名校验。
 *
 * 规则：
 *   - 主站域名（site_url / main_domain，含 www 变体）永远不能被商户绑定；
 *   - 自定义域名不能占用其它商户的二级域名 host；
 *   - 二级域名不能占用其它商户的自定义域名 host。
 *
 * 写入侧（商户设置 / 后台开通 / 后台编辑）和运行侧（MerchantContext）共用。
 */
final class MerchantDomainGuard
{
    /**
     * 归一化域名：小写、去协议、路径、端口和通配符前缀。
     */
    public static function normalizeHost(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (strpos($value, '://') !== false) {
            $parsed = parse_url($value, PHP_URL_HOST);
            if (is_string($parsed) && $parsed !== '') {
                $value = $parsed;
            }
        }

        $value = preg_split('/[\/\?#]/', $value, 2)[0] ?? $value;

        $pos = strpos($value, ':');
        if ($pos !== false) {
            $value = substr($value, 0, $pos);
        }

        if (strpos($value, '*.') === 0) {
            $value = substr($value, 2);
        }

        return strtolower(trim($value, ". \t\n\r\0\x0B"));
    }

    /**
     * 主站保留域名列表（site_url / main_domain，含 www 互转）。
     *
     * @return string[]
     */
    public static function mainHosts(): array
    {
        $reserved = [];
        $values = [
            (string) Config::get('site_url', ''),
            (string) (Config::get('main_domain') ?? ''),
        ];

        foreach ($values as $value) {
            $main = self::normalizeHost($value);
            if ($main === '') {
                continue;
            }
            $reserved[$main] = true;
            if (strpos($main, 'www.') === 0) {
                $reserved[substr($main, 4)] = true;
            } else {
                $reserved['www.' . $main] = true;
            }
        }

        return array_keys($reserved);
    }

    /**
     * host 是否属于主站。
     */
    public static function isMainHost(string $host): bool
    {
        $host = self::normalizeHost($host);
        return $host !== '' && in_array($host, self::mainHosts(), true);
    }

    /**
     * 自定义域名冲突检查；返回空串表示可用。
     */
    public static function customDomainError(string $domain, int $excludeId = 0): string
    {
        $host = self::normalizeHost($domain);
        if ($host === '') {
            return '自定义域名格式不合法';
        }
        if (self::isMainHost($host)) {
            return '不能使用主站域名作为自定义域名';
        }

        $main = self::normalizeHost((string) (Config::get('main_domain') ?? ''));
        if ($main !== '') {
            $rows = Database::query(
                'SELECT `subdomain` FROM `' . Database::prefix() . 'merchant`
                  WHERE `id` != ? AND `deleted_at` IS NULL
                    AND `subdomain` IS NOT NULL AND `subdomain` <> \'\'',
                [$excludeId]
            );
            foreach ($rows as $row) {
                $sub = strtolower(trim((string) ($row['subdomain'] ?? '')));
                if ($sub !== '' && $host === $sub . '.' . $main) {
                    return '该域名已被其他分站的二级域名占用';
                }
            }
        }

        return '';
    }

    /**
     * 二级域名冲突检查；返回空串表示可用。
     */
    public static function subdomainError(string $subdomain, int $excludeId = 0): string
    {
        $sub = strtolower(trim($subdomain));
        if ($sub === '') {
            return '';
        }

        $main = self::normalizeHost((string) (Config::get('main_domain') ?? ''));
        if ($main === '') {
            return '';
        }

        $full = $sub . '.' . $main;
        if (self::isMainHost($full)) {
            return '该二级域名与主站域名冲突';
        }

        $rows = Database::query(
            'SELECT `custom_domain` FROM `' . Database::prefix() . 'merchant`
              WHERE `id` != ? AND `deleted_at` IS NULL
                AND `custom_domain` IS NOT NULL AND `custom_domain` <> \'\'',
            [$excludeId]
        );
        foreach ($rows as $row) {
            $custom = self::normalizeHost((string) ($row['custom_domain'] ?? ''));
            if ($custom !== '' && $custom === $full) {
                return '该二级域名已被其他商户的自定义域名占用';
            }
        }

        return '';
    }
}
