<?php

declare(strict_types=1);

/**
 * 前台注册控制器。
 *
 * GET  ?c=register   显示注册表单
 * POST ?c=register   处理注册请求（AJAX JSON）
 *
 * 页面动作名是 `display()`（不是 `_index`），入口登记在 Dispatcher::DEFAULT_ACTIONS。
 * 注册视图是**公共视图**，放在 user/view/ 全站共用，不跟着主题走，所以它自己不依赖
 * 主题 module.php 取数（令牌、必填项都在视图里从核心读）。
 *
 * display() 只留「发 POST 就转交处理器」和两个必须在输出前完成的判断（已登录跳转、
 * 功能未开启）。
 */
class RegisterController extends BaseController
{
    /**
     * 注册页：GET 显示表单，POST 转交注册处理。
     */
    public function display(): void
    {
        if (Request::isPost()) {
            $this->handleRegister();
            return;
        }

        // 已登录则跳转到用户中心（重定向必须在任何输出之前，所以留在控制器）
        if (!empty($_SESSION['em_front_user'])) {
            header('Location: ?c=user');
            exit;
        }

        // 未开放注册时直接提示
        if ((string) Config::get('user_register', '0') !== '1') {
            Response::error('当前站点已关闭注册功能');
        }

        $this->view->setTitle('注册');
        // 独立页：不套主题 header/footer（见 View::renderStandalone）
        $this->view->renderStandalone('register');
    }

    /**
     * 处理注册表单提交。
     */
    private function handleRegister(): void
    {
        

        if (!Csrf::validateOrSameOrigin((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $registerEnabled = Config::get('user_register', '0');
        if ($registerEnabled == 0) {
            Response::error('当前站点已关闭注册功能');
        }

        $username = trim(Input::post('username', ''));
        $mobile   = trim(Input::post('mobile', ''));
        $email    = trim(Input::post('email', ''));
        $password = (string) Input::post('password', '');
        $confirm  = (string) Input::post('password_confirm', '');

        $regFieldsRaw = (string) Config::get('user_register_fields', '');
        $regFields = array_filter(array_map('trim', explode(',', $regFieldsRaw)));
        $requireMobile = in_array('mobile', $regFields, true);
        $requireEmail = in_array('email', $regFields, true);

        // 未开启的字段忽略提交值，避免绕过配置写入
        if (!$requireMobile) {
            $mobile = '';
        }
        if (!$requireEmail) {
            $email = '';
        }

        // 基础校验
        if ($username === '') {
            Response::error('请输入账号');
        }
        if (mb_strlen($username) < 3 || mb_strlen($username) > 20) {
            Response::error('账号长度为 3-20 个字符');
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            Response::error('账号只能包含字母、数字和下划线，不能包含中文');
        }
        if ($requireMobile) {
            if ($mobile === '') {
                Response::error('请输入手机号码');
            }
            if (!preg_match('/^1[3-9]\d{9}$/', $mobile)) {
                Response::error('手机号码格式不正确');
            }
        }
        if ($requireEmail) {
            if ($email === '') {
                Response::error('请输入邮箱');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('邮箱格式不正确');
            }
        }
        if ($password === '') {
            Response::error('请输入密码');
        }
        if (mb_strlen($password) < 6) {
            Response::error('密码长度不能少于 6 位');
        }
        if ($password !== $confirm) {
            Response::error('两次输入的密码不一致');
        }

        // 注册前置校验：邮箱验证等插件在此拦截（返回非空字符串即视为拦截，文案直接回给用户）。
        // 放在唯一性检查之前：验证码不过就没必要再查库。
        $registerBlocked = (string) applyFilter('register_before_submit', '', $email, $username);
        if ($registerBlocked !== '') {
            Response::error($registerBlocked);
        }

        // 唯一性检查
        $userModel = new UserListModel();
        if ($userModel->existsUsername($username)) {
            Response::error('该账号已被注册');
        }
        if ($requireMobile && $userModel->existsMobile($mobile)) {
            Response::error('该手机号已被注册');
        }
        if ($requireEmail && $userModel->existsEmail($email)) {
            Response::error('该邮箱已被注册');
        }

        // 创建用户
        $hasher = new PasswordHash(8, true);
        $hash = $hasher->HashPassword($password);

        // —— 返佣归因：把 Cookie 里的 invite_code 转成 inviter_l1/l2 祖链（仅 2 级）
        // 自邀（code 指向即将被注册的这个用户）不可能发生（注册前还没有 id）
        $inviterCode = InviteToken::getCode();
        $inviterL1 = $inviterCode !== '' ? InviteToken::resolveInviterId($inviterCode) : 0;
        $inviterL2 = 0;
        if ($inviterL1 > 0) {
            $upper = Database::find('user', $inviterL1);
            if ($upper) {
                $inviterL2 = (int) ($upper['inviter_l1'] ?? 0);
            }
        }

        $userId = $userModel->create([
            'username'    => $username,
            'email'       => $email,
            'mobile'      => $mobile,
            'password'    => $hash,
            'nickname'    => $username,
            'status'      => 1,
            'invite_code' => InviteToken::generateUniqueCode(),
            'inviter_l1'  => $inviterL1 ?: null,
            'inviter_l2'  => $inviterL2 ?: null,
        ]);

        if ($userId <= 0) {
            Response::error('注册失败，请稍后重试');
        }

        // 注册成功：插件可在此收尾（例如作废已用掉的邮箱验证码，防重放）
        doAction('register_succeeded', $email, $username);

        try {
            UserExperienceService::applyRegisterBonus($userId);
        } catch (Throwable $e) {
            // 初始经验发放失败不阻断注册
        }

        // 注册成功后自动登录
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_regenerate_id(true);

        $_SESSION['em_front_user'] = [
            'id'       => $userId,
            'username' => $username,
            'nickname' => $username,
            'email'    => $email,
            'mobile'   => $mobile,
            'avatar'   => '',
            'money'    => 0,
        ];

        Csrf::refresh();

        Response::success('注册成功');
    }
}
