<?php

declare(strict_types=1);

/**
 * CSRF 防护类。
 *
 * 设计原则：
 * - 主验证基于 Session token（稳定，会话期间不变）
 * - 备援验证基于 Cookie（双重提交模式兜底，session 异常时仍可正常工作）
 * - validate() 支持"宽限期"机制：同时接受当前 token 和上一次 refresh 产生的 token
 * - refresh() 仅在明确需要更换 token 时调用（如登录后）
 *
 * 统一改进点：
 * - Cookie 双重提交兜底：即使 session 启动失败，cookie 验证仍可工作
 * - 宽限期延长至 30 分钟：减少开发过程中因 token 时序问题导致的失败
 * - Token 同时写入 session 和 cookie，确保验证链路稳定
 */
final class Csrf
{
    /** Cookie 名 */
    private const COOKIE_NAME = 'em_csrf_token';

    /** Cookie 有效期（秒），与宽限期保持一致 */
    private const COOKIE_MAX_AGE = 1800; // 30 分钟

    /** 上一次有效 token 的 session key（宽限期内有效） */
    private const PREV_TOKEN_KEY = 'em_csrf_prev';

    /**
     * 获取当前 CSRF token。
     * token 同时写入 session 和 cookie，确保双重验证链路的稳定性。
     */
    public static function token(): string
    {
        self::ensureSession();
        $key = self::sessionKey();

        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }

        $token = (string) $_SESSION[$key];

        // 同时写入 cookie（HttpOnly=false，允许 JS 读取，用于双重提交验证）
        self::setCookie($token);

        return $token;
    }

    /**
     * 校验 token 是否合法。
     *
     * 验证顺序：
     * 1. Session 当前 token 匹配
     * 2. Session 宽限期内的旧 token（支持多标签页场景）
     * 3. Cookie 双重提交匹配（session 异常时的兜底验证）
     *
     * @param string $token 待校验的 token
     * @return bool
     */
    public static function validate(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        self::ensureSession();
        $key = self::sessionKey();

        // 1. 当前 session token 严格匹配
        $sessionToken = (string) ($_SESSION[$key] ?? '');
        if ($sessionToken !== '' && hash_equals($sessionToken, $token)) {
            return true;
        }

        // 2. 宽限期内的旧 token（支持多标签页、并发请求场景）
        $prevData = $_SESSION[self::PREV_TOKEN_KEY] ?? null;
        if ($prevData && is_array($prevData)) {
            $prevToken = $prevData['token'] ?? '';
            $expiresAt = (int) ($prevData['expires_at'] ?? 0);
            if ($prevToken !== '' && $expiresAt > time() && hash_equals($prevToken, $token)) {
                return true;
            }
        }

        // 3. Cookie 双重提交兜底（session 异常或跨域场景下的备援验证）
        if (self::validateFromCookie($token)) {
            return true;
        }

        return false;
    }

    /**
     * 校验 token；旧主题没有 token 时退化为「仅允许同源请求」。
     *
     * 用于前台下单 / 提现 / 注册这类既要有 CSRF 防护、又要兼容旧主题的入口：
     *   - 有 token：走正常校验；
     *   - 没 token：只接受同源请求。商户二级域名等「同站不同源」页面会被拒绝，
     *     跨站页面同样拒绝。
     */
    public static function validateOrSameOrigin(string $token): bool
    {
        if ($token !== '' && self::validate($token)) {
            return true;
        }

        return self::isSameOriginRequest();
    }

    /**
     * 当前请求是否同源。
     *
     * 优先看 Sec-Fetch-Site（浏览器强制写入，页面 JS 改不了）：
     *   same-origin / none = 用户自己在同源页面发起 → 允许；
     *   same-site / cross-site = 同站子域或外站 → 拒绝。
     * 老浏览器没有这个头时，退回比较 Origin / Referer 与当前 Host。
     */
    private static function isSameOriginRequest(): bool
    {
        $site = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($site === 'same-origin' || $site === 'none') {
            return true;
        }
        if ($site !== '') {
            return false;
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $requestHost = (string) parse_url('http://' . $host, PHP_URL_HOST);
        if ($requestHost === '') {
            return false;
        }

        $source = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($source === '') {
            $source = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        }
        if ($source === '') {
            return false;
        }

        $sourceHost = strtolower((string) parse_url($source, PHP_URL_HOST));
        return $sourceHost !== '' && $sourceHost === $requestHost;
    }

    /**
     * 刷新 token（生成新 token，旧 token 进入宽限期）。
     *
     * @param int $graceSeconds 宽限期秒数，默认 1800 秒（30 分钟）
     * @return string 新的 token
     */
    public static function refresh(int $graceSeconds = 1800): string
    {
        self::ensureSession();
        $key = self::sessionKey();

        // 将当前 token 存入"上一次 token"供宽限期验证
        $currentToken = (string) ($_SESSION[$key] ?? '');
        if ($currentToken !== '') {
            $_SESSION[self::PREV_TOKEN_KEY] = [
                'token' => $currentToken,
                'expires_at' => time() + $graceSeconds,
            ];
        }

        // 生成新 token
        $_SESSION[$key] = bin2hex(random_bytes(32));
        $newToken = (string) $_SESSION[$key];

        // 同时更新 cookie
        self::setCookie($newToken);

        return $newToken;
    }

    /**
     * 清除宽限期内的旧 token（主动废弃历史 token）。
     */
    public static function clearGrace(): void
    {
        self::ensureSession();
        unset($_SESSION[self::PREV_TOKEN_KEY]);
    }

    // ====================== 私有方法 ======================

    /**
     * 获取 session 中存储 token 的 key。
     */
    private static function sessionKey(): string
    {
        return EM_CONFIG['auth']['csrf_key'] ?? 'csrf_token';
    }

    /**
     * 确保 session 已启动。
     */
    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * 设置 CSRF token cookie。
     */
    private static function setCookie(string $token): void
    {
        $secret = self::getCookieSecret();
        if ($secret === null) {
            return;
        }

        $hmac = hash_hmac('sha256', $token, $secret);
        $cookieValue = $token . '.' . $hmac;

        @setcookie(
            self::COOKIE_NAME,
            $cookieValue,
            [
                'expires' => time() + self::COOKIE_MAX_AGE,
                'path' => '/',
                'domain' => '',
                'secure' => false, // 开发环境允许 HTTP
                'httponly' => false, // 允许 JS 读取（双重提交验证需要）
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * 从 cookie 中验证 token（双重提交验证的 cookie 端）。
     */
    private static function validateFromCookie(string $token): bool
    {
        $cookieValue = $_COOKIE[self::COOKIE_NAME] ?? '';

        if ($cookieValue === '') {
            return false;
        }

        $parts = explode('.', $cookieValue, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$cookieToken, $providedHmac] = $parts;

        // HMAC 签名验证（防止 cookie 被篡改）
        $secret = self::getCookieSecret();
        if ($secret === null) {
            return false;
        }

        $expectedHmac = hash_hmac('sha256', $cookieToken, $secret);
        if (!hash_equals($expectedHmac, $providedHmac)) {
            return false;
        }

        // Cookie 中的 token 需与提交的 token 匹配（双重提交验证）
        return hash_equals($cookieToken, $token);
    }

    /**
     * 获取 cookie 签名密钥（基于配置派生）。
     *
     * 没有密钥时返回 null，调用方据此停用 cookie 兜底；
     * 老站的密钥由 CsrfSecret 在后台首次访问时写入 config.php。
     */
    private static function getCookieSecret(): ?string
    {
        $secret = CsrfSecret::get();
        if ($secret === '') {
            return null;
        }

        // 使用配置的密钥派生，确保 cookie 签名不可伪造
        $base = EM_CONFIG['auth']['csrf_key'] ?? 'default_csrf_key';

        return hash('sha256', $base . $secret, true);
    }
}
