<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 用户中心 - 个人资料。
 */
userRequireLogin();

$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();
$displayMoney = '0.00';
if (!empty($frontUser['money'])) {
    $displayMoney = bcdiv((string) $frontUser['money'], '1000000', 2);
}
// 处理 POST 提交
if (Request::isPost()) {
    try {
        $action = (string) Input::post('action', '');
        $csrf = (string) Input::post('csrf_token', '');

        if (!Csrf::validate($csrf)) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $userModel = new UserListModel();
        $userId = (int) $frontUser['id'];

        switch ($action) {
            case 'profile':
                $nickname = trim(Input::post('nickname', ''));
                $nicknameErr = DisplayName::validate($nickname, '昵称');
                if ($nicknameErr !== null) {
                    Response::error($nicknameErr);
                }
                $email    = trim(Input::post('email', ''));
                $mobile   = trim(Input::post('mobile', ''));

                if ($nickname === '') {
                    Response::error('昵称不能为空');
                }
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Response::error('请输入有效的邮箱地址');
                }
                if ($userModel->existsEmail($email, $userId)) {
                    Response::error('该邮箱已被其他用户使用');
                }
                if ($mobile !== '' && $userModel->existsMobile($mobile, $userId)) {
                    Response::error('该手机号已被其他用户使用');
                }

                $userModel->update($userId, [
                    'nickname' => $nickname,
                    'email'    => $email,
                    'mobile'   => $mobile,
                ]);

                // 刷新 session
                $frontUser['nickname'] = $nickname;
                $frontUser['email']    = $email;
                $frontUser['mobile']   = $mobile;
                $_SESSION['em_front_user'] = $frontUser;

                $csrfToken = Csrf::refresh();
                Response::success('保存成功', ['csrf_token' => $csrfToken]);
                break;

            default:
                Response::error('未知操作');
        }
    } catch (Throwable $e) {
        Response::error('操作失败：' . $e->getMessage());
    }
}

if (Request::isPjax()) {
    echo '<div id="userContent" class="uc-content">';
    include __DIR__ . '/view/profile.php';
    echo '</div>';
} else {
    $userContentView = __DIR__ . '/view/profile.php';
    require __DIR__ . '/index.php';
}
