<?php

declare(strict_types=1);

/**
 * 后台认证服务。
 *
 * 当前服务专门用于后台管理员登录。
 * 管理员账号未来不能用于前台登录，前台登录需单独实现用户认证逻辑。
 */
final class AuthService
{
    /**
     * @var array<string, mixed>
     */
    private $config;

    /**
     * @var UserModel
     */
    private $users;

    /**
     * @var PasswordHash
     */
    private $hasher;

    public function __construct()
    {
        $this->config = EM_CONFIG['auth'];
        $this->users = new UserModel();
        $this->hasher = new PasswordHash(8, true);
    }

    /**
     * 启动 session。
     */
    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * 检查当前管理员是否已登录。
     */
    public function check(): bool
    {
        $this->startSession();

        if (!empty($_SESSION[$this->config['session_key']])) {
            return $this->validateSession();
        }

        $token = isset($_COOKIE[$this->config['remember_cookie']]) ? (string) $_COOKIE[$this->config['remember_cookie']] : '';
        if ($token === '') {
            return false;
        }

        $user = $this->users->findAdminByRememberToken($token);
        if ($user === null) {
            $this->forgetRememberCookie();
            return false;
        }

        $_SESSION[$this->config['session_key']] = $this->sessionPayload($user);
        session_regenerate_id(true);
        return true;
    }

    /**
     * 校验 session 中的管理员是否仍然有效。
     *
     * 每次后台请求回查一次数据库：账号被禁用、删除、降权，或密码被修改后，
     * 旧 session 立即失效，不再等到 session 自然过期。
     */
    private function validateSession(): bool
    {
        $payload = $_SESSION[$this->config['session_key']] ?? null;
        $userId = is_array($payload) ? (int) ($payload['id'] ?? 0) : 0;

        if ($userId > 0) {
            $user = $this->users->findAdminById($userId);
            if ($user !== null && hash_equals(
                (string) ($payload['pwd'] ?? ''),
                $this->passwordFingerprint((string) ($user['password'] ?? ''))
            )) {
                return true;
            }
        }

        // 账号失效或密码已改：清掉登录态，并作废记住我 cookie
        unset($_SESSION[$this->config['session_key']]);
        $this->forgetRememberCookie();

        if (session_id() !== '') {
            session_regenerate_id(true);
        }

        return false;
    }

    /**
     * 密码指纹：存进 session，用于判断密码是否已被修改。
     */
    private function passwordFingerprint(string $hash): string
    {
        return hash('sha256', $hash);
    }

    /**
     * 返回当前已登录管理员信息。
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        $this->startSession();
        return isset($_SESSION[$this->config['session_key']]) ? $_SESSION[$this->config['session_key']] : null;
    }

    /**
     * 刷新 session 中的用户信息（修改资料后同步）。
     *
     * @param array<string, mixed> $user 数据库中最新的用户记录
     */
    public function refreshSession(array $user): void
    {
        $this->startSession();
        $_SESSION[$this->config['session_key']] = $this->sessionPayload($user);
    }

    /**
     * 执行后台管理员登录。
     *
     * @return array<string, mixed>
     */
    public function attemptAdminLogin(string $account, string $password, bool $remember): array
    {
        $account = trim($account);

        // 登录前置拦截：登录频率限制等插件在此计数 / 判断封禁，返回非空字符串即拦截。
        // 传入 $account 才能挡住针对某个账号的定向爆破；纯 IP 维度挡住换账号名喷洒。
        // 作用域传 'admin'，与前台登录分开计数。
        $blocked = (string) applyFilter('login_before_attempt', '', 'admin', $account);
        if ($blocked !== '') {
            throw new RuntimeException($blocked);
        }

        if ($account === '' || $password === '') {
            throw new InvalidArgumentException('账号和密码不能为空');
        }

        $user = $this->users->findAdminByAccount($account);
        if ($user === null || !$this->hasher->CheckPassword($password, (string) $user['password'])) {
            doAction('login_attempt_failed', 'admin', $account);
            throw new RuntimeException('账号或密码错误');
        }

        doAction('login_attempt_succeeded', 'admin', $account);
        $this->startSession();
        session_regenerate_id(true);
        $_SESSION[$this->config['session_key']] = $this->sessionPayload($user);

        $rememberToken = null;
        $days = $remember ? (int) $this->config['remember_days_checked'] : (int) $this->config['remember_days_default'];
        if ($days > 0) {
            $rememberToken = bin2hex(random_bytes(32));
            $this->queueRememberCookie($rememberToken, $days);
        }

        $this->users->updateLoginMeta((int) $user['id'], $this->clientIp(), $rememberToken);

        return $this->sessionPayload($user);
    }

    /**
     * 退出登录。
     */
    public function logout(): void
    {
        $this->startSession();

        // 退出时连**数据库里**的「记住我」令牌一并作废。
        // 只删浏览器 cookie 是不够的：cookie 只是令牌的副本，库里那份不清掉，
        // 任何拿到过该值的人在退出后仍然能直接登进来（最长可达 remember_days_checked 天）。
        $userId = (int) ($_SESSION[$this->config['session_key']]['id'] ?? 0);
        if ($userId > 0) {
            $this->users->clearRememberToken($userId);
        }

        // 只清后台自己的键。**不要**写成 $_SESSION = []：前后台共用同一个 PHP session
        // 容器，整表清空会把前台登录态（em_front_user）以及前台专属的
        // em_find_order_visible / em_last_merchant 一起清掉，还会连累前后台共用的
        // CSRF 键（em_admin_csrf）与验证码键。后台专属键只有 em_admin_auth 这一个。
        unset($_SESSION[$this->config['session_key']]);
        $this->forgetRememberCookie();

        if (session_id() !== '') {
            session_regenerate_id(true);
        }
    }

    /**
     * 生成 session 中存储的管理员信息。
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function sessionPayload(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'nickname' => (string) $user['nickname'],
            'email' => (string) $user['email'],
            'mobile' => (string) ($user['mobile'] ?? ''),
            'avatar' => (string) $user['avatar'],
            'role' => (string) $user['role'],
            // 密码指纹：密码变了，其它终端上的旧会话会在下次请求时失效
            'pwd' => $this->passwordFingerprint((string) ($user['password'] ?? '')),
        ];
    }

    /**
     * 写入记住登录 cookie。
     */
    private function queueRememberCookie(string $token, int $days): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        setcookie(
            $this->config['remember_cookie'],
            $token,
            [
                'expires' => time() + ($days * 86400),
                'path' => '/',
                'httponly' => true,
                'secure' => $secure,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * 清理记住登录 cookie。
     */
    private function forgetRememberCookie(): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        setcookie(
            $this->config['remember_cookie'],
            '',
            [
                'expires' => time() - 3600,
                'path' => '/',
                'httponly' => true,
                'secure' => $secure,
                'samesite' => 'Lax',
            ]
        );
    }

    /**
     * 获取客户端 IP。
     */
    private function clientIp(): string
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    }
}
