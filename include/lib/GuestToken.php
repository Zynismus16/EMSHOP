<?php

declare(strict_types=1);

/**
 * 游客身份标识（v2：签名 Cookie）。
 *
 * 旧版是裸随机串（em_guest_token），知道值即身份；一旦访问日志 / 备份泄露，
 * 里面的 token 可以被永久冒用。v2 改为：
 *
 *   em_guest_token_v2 = v2|{token}|{issued_at}|{hmac}
 *   hmac = hash_hmac('sha256', token . '|' . issued_at, secret)
 *
 * - 数据库只存 token 本身（em_order.guest_token），签名只存在 Cookie；
 * - secret 首次使用时生成并写入 em_config 的 guest_token_secret；
 * - 旧 Cookie em_guest_token 不再读取，已泄露的旧 token 全部失效；
 * - 旧 token 没有 secret，签不出 v2，无法再用于查单列表 / 导出。
 */
final class GuestToken
{
    private const COOKIE_NAME = 'em_guest_token_v2';
    private const COOKIE_LIFETIME = 86400 * 365 * 10; // 10年
    private const SECRET_CONFIG_KEY = 'guest_token_secret';

    /** @var string|null */
    private static $secret = null;

    /**
     * 获取当前访客的 guest_token。
     * Cookie 缺失 / 签名无效时自动签发新的 v2 身份。
     */
    public static function get(): string
    {
        $token = self::parseAndVerify((string) ($_COOKIE[self::COOKIE_NAME] ?? ''));
        if ($token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(16));
        self::setCookie($token);
        return $token;
    }

    /**
     * 当前请求是否携带有效的 v2 签名身份。
     * 不签发新 Cookie，供「只允许已验证身份」的敏感操作判断。
     */
    public static function hasValidCookie(): bool
    {
        return self::parseAndVerify((string) ($_COOKIE[self::COOKIE_NAME] ?? '')) !== '';
    }

    /**
     * 解析并验签；合法返回 token，否则返回空串。
     */
    private static function parseAndVerify(string $cookie): string
    {
        if ($cookie === '') {
            return '';
        }

        $parts = explode('|', $cookie);
        if (count($parts) !== 4 || $parts[0] !== 'v2') {
            return '';
        }

        [, $token, $issuedAt, $sig] = $parts;
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return '';
        }
        if (!ctype_digit($issuedAt) || (int) $issuedAt <= 0) {
            return '';
        }

        $expected = hash_hmac('sha256', $token . '|' . $issuedAt, self::secret());
        return hash_equals($expected, $sig) ? $token : '';
    }

    /**
     * 写入 v2 Cookie。
     */
    private static function setCookie(string $token): void
    {
        $issuedAt = (string) time();
        $sig = hash_hmac('sha256', $token . '|' . $issuedAt, self::secret());
        $value = 'v2|' . $token . '|' . $issuedAt . '|' . $sig;

        $_COOKIE[self::COOKIE_NAME] = $value;
        @setcookie(self::COOKIE_NAME, $value, [
            'expires'  => time() + self::COOKIE_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /**
     * 签名密钥：首次使用时生成并写入系统配置，之后保持稳定。
     */
    private static function secret(): string
    {
        if (self::$secret !== null) {
            return self::$secret;
        }

        $secret = (string) Config::get(self::SECRET_CONFIG_KEY, '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            try {
                if (!Config::set(self::SECRET_CONFIG_KEY, $secret)) {
                    $secret = self::fallbackSecret();
                }
            } catch (Throwable $e) {
                $secret = self::fallbackSecret();
            }
        }

        self::$secret = $secret;
        return $secret;
    }

    /**
     * DB 写配置失败时的兜底密钥：由本地配置派生，保证同一站点稳定，
     * 否则每次请求换 secret 会让所有 Cookie 立即失效。
     */
    private static function fallbackSecret(): string
    {
        return hash(
            'sha256',
            'guest_token|'
            . (string) (EM_CONFIG['db']['password'] ?? '')
            . '|' . (string) (EM_CONFIG['db']['prefix'] ?? '')
        );
    }
}
