<?php

declare(strict_types=1);

/**
 * 找回密码业务：发重置邮件、校验令牌、更新密码。
 */
final class PasswordResetService
{
    /** 重置链接有效时长（秒） */
    private const TOKEN_TTL = 3600;

    /** IP 维度：每窗口最多请求次数 */
    private const IP_MAX_ATTEMPTS = 5;

    /** IP 限流窗口（秒）：1 分钟内最多 5 次 */
    private const IP_WINDOW = 60;

    /** 邮箱维度：每窗口最多请求次数 */
    private const EMAIL_MAX_ATTEMPTS = 3;

    /** 邮箱限流窗口（秒）：1 分钟内最多 3 次 */
    private const EMAIL_WINDOW = 60;

    /** 重置提交 IP 限流：1 分钟内最多 10 次 */
    private const RESET_IP_MAX_ATTEMPTS = 10;

    private const RESET_IP_WINDOW = 60;

    private PasswordResetModel $resetModel;

    private UserListModel $userModel;

    public function __construct()
    {
        $this->resetModel = new PasswordResetModel();
        $this->userModel = new UserListModel();
    }

    /**
     * 申请发送重置邮件。
     *
     * @return array{ok: bool, msg: string, captcha_expr?: string}
     */
    public function requestReset(string $email, string $captchaInput): array
    {
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'msg' => '请输入有效的邮箱地址'];
        }

        $ipKey = 'forgot_pwd_ip:v2:' . RateLimit::clientIp();
        if (RateLimit::tooManyAttempts($ipKey, self::IP_MAX_ATTEMPTS)) {
            $wait = RateLimit::availableIn($ipKey);
            return ['ok' => false, 'msg' => "请求过于频繁，请 {$wait} 秒后再试", 'captcha_expr' => Captcha::issue('forgot_password')];
        }
        RateLimit::hit($ipKey, self::IP_WINDOW);

        if (!Captcha::verify($captchaInput, 'forgot_password')) {
            return ['ok' => false, 'msg' => '验证码错误，请重试', 'captcha_expr' => Captcha::issue('forgot_password')];
        }

        $emailKey = 'forgot_pwd_email:v2:' . hash('sha256', $email);
        if (RateLimit::tooManyAttempts($emailKey, self::EMAIL_MAX_ATTEMPTS)) {
            $wait = RateLimit::availableIn($emailKey);
            return ['ok' => false, 'msg' => "该邮箱请求过于频繁，请 {$wait} 秒后再试"];
        }
        RateLimit::hit($emailKey, self::EMAIL_WINDOW);

        // 对外统一响应：**不区分**「邮箱未注册」「账号已被禁用」「已发送成功」。
        //
        // 原实现分别返回「该邮箱未注册」和「账号已被禁用，请联系管理员」，
        // 这等于提供了一个免登录的账号枚举接口 —— 攻击者可以逐个邮箱试出哪些已注册、
        // 哪些被封禁（后者还能反推出违规账号）。找回密码接口一律只给同一句提示。
        $uniformOk = ['ok' => true, 'msg' => '如果该邮箱已注册，重置链接已发送，请查收'];

        // 邮件配置检查必须放在**查用户之前**：它对任何邮箱都返回同一结果，
        // 不会泄露账号是否存在。若放在查询之后，「未注册」会拿到统一提示、
        // 「已注册」却报「邮件服务未配置」—— 差异本身又成了一个枚举信号。
        if (!$this->isMailConfigured()) {
            return ['ok' => false, 'msg' => '邮件服务未配置，请联系管理员'];
        }

        $user = $this->findUserByEmail($email);
        if ($user === null || (int) ($user['status'] ?? 0) !== 1) {
            // 不存在或已禁用：不创建令牌、不发送邮件，但对外表现与成功完全一致
            return $uniformOk;
        }

        // 没有配置站点地址时无法生成安全链接（绝不能用 HTTP_HOST 拼），
        // 按统一提示返回，不创建令牌也不发信。
        if ($this->resetBaseUrl() === '') {
            $this->logResetUrlMissing($email);
            return $uniformOk;
        }

        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_TTL);

        $this->resetModel->invalidatePendingForUser((int) $user['id']);
        $this->resetModel->create((int) $user['id'], $email, $tokenHash, $expiresAt);

        $resetUrl = $this->buildResetUrl($rawToken);
        $siteName = (string) Config::get('sitename', 'EMSHOP');
        $subject = $siteName . ' - 重置登录密码';
        $html = $this->buildResetEmailHtml($siteName, $resetUrl, self::TOKEN_TTL / 60);

        if (!Mailer::send($email, $subject, $html)) {
            // 发信失败也返回**统一提示**：若这里单独报「发送失败」，
            // 攻击者就能用「未注册 → 统一提示」与「已注册 → 发送失败」的差异继续枚举账号。
            // 真实原因写系统日志，供站长排查（账号信息只记哈希前缀，不落明文邮箱）。
            $this->logSendFailure($email);
            return $uniformOk;
        }

        return $uniformOk;
    }

    /**
     * 校验重置令牌是否有效。
     *
     * @return array<string, mixed>|null 令牌行
     */
    public function validateToken(string $rawToken): ?array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            return null;
        }

        $row = $this->resetModel->findValidByTokenHash(hash('sha256', $rawToken));
        if ($row === null) {
            return null;
        }

        // 历史遗留或直接构造的管理员令牌：一律无效
        $user = $this->userModel->findById((int) ($row['user_id'] ?? 0));
        if ($user === null
            || (int) ($user['status'] ?? 0) !== 1
            || (string) ($user['role'] ?? '') === 'admin'
        ) {
            return null;
        }

        return $row;
    }

    /**
     * 提交新密码。
     *
     * @return array{ok: bool, msg: string}
     */
    public function resetPassword(string $rawToken, string $password, string $confirm): array
    {
        $ipKey = 'reset_pwd_ip:v2:' . RateLimit::clientIp();
        if (RateLimit::tooManyAttempts($ipKey, self::RESET_IP_MAX_ATTEMPTS)) {
            $wait = RateLimit::availableIn($ipKey);
            return ['ok' => false, 'msg' => "请求过于频繁，请 {$wait} 秒后再试"];
        }
        RateLimit::hit($ipKey, self::RESET_IP_WINDOW);

        $row = $this->validateToken($rawToken);
        if ($row === null) {
            return ['ok' => false, 'msg' => '重置链接无效或已过期，请重新申请'];
        }

        if ($password === '') {
            return ['ok' => false, 'msg' => '请输入新密码'];
        }
        if (mb_strlen($password) < 6) {
            return ['ok' => false, 'msg' => '密码长度不能少于 6 位'];
        }
        if ($password !== $confirm) {
            return ['ok' => false, 'msg' => '两次输入的密码不一致'];
        }

        $hasher = new PasswordHash(8, true);
        $hash = $hasher->HashPassword($password);

        $userId = (int) ($row['user_id'] ?? 0);
        if (!$this->userModel->update($userId, ['password' => $hash])) {
            return ['ok' => false, 'msg' => '密码更新失败，请稍后重试'];
        }

        $this->resetModel->markUsed((int) $row['id']);
        $this->resetModel->invalidatePendingForUser($userId);

        return ['ok' => true, 'msg' => '密码已重置，请使用新密码登录'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findUserByEmail(string $email): ?array
    {
        $table = Database::prefix() . 'user';
        // 只允许普通用户走前台找回密码。管理账号只能通过 PHP 工具箱重置，
        // 不能走这条免登录的邮箱重置链路。
        // ORDER BY id ASC 让存量重复邮箱的取值确定。
        $sql = sprintf(
            'SELECT `id`, `email`, `status` FROM `%s` WHERE `email` = ? AND `role` = ? ORDER BY `id` ASC LIMIT 1',
            $table
        );

        return Database::fetchOne($sql, [$email, 'user']);
    }

    private function isMailConfigured(): bool
    {
        $from = trim((string) Config::get('mail_from_address', ''));
        $host = trim((string) Config::get('mail_host', ''));
        $password = (string) Config::get('mail_password', '');
        $port = (int) (Config::get('mail_port', '465') ?: 465);

        return $from !== '' && $host !== '' && $password !== '' && $port > 0;
    }

    /**
     * 记录发信失败。
     *
     * 对外必须保持统一提示（否则「未注册」与「已注册但发信失败」的差异会被用来枚举账号），
     * 所以真实原因只写系统日志。邮箱只记哈希前缀，避免日志里出现明文地址。
     */
    private function logSendFailure(string $email): void
    {
        try {
            if (class_exists('SystemLogModel')) {
                (new SystemLogModel())->error(
                    'system',
                    '重置密码邮件发送失败',
                    'Mailer::send 返回 false，用户未收到重置邮件（对外仍返回统一提示，避免账号枚举）',
                    ['email_hash' => substr(hash('sha256', strtolower(trim($email))), 0, 12)]
                );
            }
        } catch (Throwable $ignore) {
            // 日志失败不影响主流程
        }
    }

    /**
     * 生成重置密码绝对链接。
     *
     * 优先使用后台配置的「站点地址」作为基址，**不能**用 Request::baseUrl()：
     * 后者取的是 HTTP_HOST，而 Host 头完全由请求方控制。攻击者只要用伪造的 Host
     * 触发一次找回密码，受害者收到的邮件里链接就指向攻击者域名 —— 受害者一点，
     * 重置令牌就落到攻击者手里，账号随即被接管。
     * site_url 来自后台配置，不受请求头影响。
     */
    private function buildResetUrl(string $rawToken): string
    {
        $base = $this->resetBaseUrl();
        if ($base === '') {
            return '';
        }

        $query = http_build_query([
            'c' => 'login',
            'a' => 'reset',
            'token' => $rawToken,
        ]);

        return $base . '/?' . $query;
    }

    /**
     * 重置链接的基址：只使用后台配置（site_url / main_domain），绝不使用 HTTP_HOST。
     */
    private function resetBaseUrl(): string
    {
        $configured = rtrim(trim((string) Config::get('site_url', '')), '/');
        if ($configured !== '') {
            return $configured;
        }

        $main = trim((string) Config::get('main_domain', ''));
        if ($main === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $main)) {
            $main = 'https://' . ltrim($main, '/');
        }

        return rtrim($main, '/');
    }

    /**
     * 站点地址未配置导致无法生成重置链接时的日志（对外仍返回统一提示）。
     */
    private function logResetUrlMissing(string $email): void
    {
        try {
            if (class_exists('SystemLogModel')) {
                (new SystemLogModel())->error(
                    'system',
                    '重置密码链接无法生成',
                    'site_url / main_domain 均未配置，已跳过发送',
                    ['email_hash' => substr(hash('sha256', strtolower(trim($email))), 0, 12)]
                );
            }
        } catch (Throwable $ignore) {
            // 日志失败不影响主流程
        }
    }

    private function buildResetEmailHtml(string $siteName, string $resetUrl, int $validMinutes): string
    {
        $siteNameEsc = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
        $resetUrlEsc = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

        return '<div style="font-family:sans-serif;line-height:1.6;color:#333;max-width:560px;margin:0 auto;">'
            . '<h2 style="color:#4e6ef2;margin-bottom:16px;">' . $siteNameEsc . '</h2>'
            . '<p>您好，我们收到了重置登录密码的请求。请点击下方按钮设置新密码：</p>'
            . '<p style="margin:24px 0;"><a href="' . $resetUrlEsc . '" '
            . 'style="display:inline-block;padding:12px 24px;background:#4e6ef2;color:#fff;text-decoration:none;border-radius:6px;">'
            . '重置密码</a></p>'
            . '<p style="font-size:13px;color:#888;">或复制以下链接到浏览器打开：<br>'
            . '<span style="word-break:break-all;">' . $resetUrlEsc . '</span></p>'
            . '<p style="font-size:13px;color:#888;">链接 ' . (int) $validMinutes . ' 分钟内有效，仅可使用一次。'
            . '如非本人操作，请忽略此邮件。</p>'
            . '</div>';
    }
}
