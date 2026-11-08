<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * 后台个人信息页。
 *
 * 支持修改昵称、邮箱、密码和上传头像。
 */
adminRequireLogin();
$user = $adminUser;
$siteName = Config::get('sitename', 'EMSHOP');

if (Request::isPost()) {
    try {
        $action = (string) Input::post('action', '');
        $csrf = (string) Input::post('csrf_token', '');

        if (!Csrf::validate($csrf)) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $userModel = new UserModel();
        $userId = (int) $user['id'];

        switch ($action) {
            case 'profile':
                $username = (string) Input::post('username', '');
                $nickname = (string) Input::post('nickname', '');
                $email = (string) Input::post('email', '');
                $mobile = trim((string) Input::post('mobile', ''));

                if ($username === '') {
                    Response::error('账号不能为空');
                }

                if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
                    Response::error('账号只能包含字母、数字和下划线，长度3-30个字符');
                }

                if ($userModel->isUsernameTaken($username, $userId)) {
                    Response::error('该账号已被其他用户使用');
                }

                $nicknameErr = DisplayName::validate($nickname, '昵称');
                if ($nicknameErr !== null) {
                    Response::error($nicknameErr);
                }

                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Response::error('请输入有效的邮箱地址');
                }

                if ($userModel->isEmailTaken($email, $userId)) {
                    Response::error('该邮箱已被其他用户使用');
                }

                if ($userModel->isMobileTaken($mobile, $userId)) {
                    Response::error('该手机号已被其他用户使用');
                }

                $userModel->updateProfile($userId, [
                    'username' => $username,
                    'nickname' => $nickname,
                    'email' => $email,
                    'mobile' => $mobile,
                ]);

                $freshUser = $userModel->findById($userId);
                if ($freshUser !== null) {
                    $auth->refreshSession($freshUser);
                }

                $csrfToken = Csrf::refresh();
                Response::success('资料更新成功', [
                    'csrf_token' => $csrfToken,
                    'user' => [
                        'username' => $freshUser !== null ? $freshUser['username'] : $username,
                        'nickname' => $freshUser !== null ? $freshUser['nickname'] : $nickname,
                        'email' => $freshUser !== null ? $freshUser['email'] : $email,
                        'mobile' => $freshUser !== null ? ($freshUser['mobile'] ?? '') : $mobile,
                        'avatar' => $freshUser !== null ? $freshUser['avatar'] : null,
                    ],
                ]);
                break;

            case 'avatar':

                if (empty($_FILES['avatar_file'])) {
                    Response::error('请选择头像文件');
                }

                $uploader = new UploadService();
                $result = $uploader->upload($_FILES['avatar_file'], ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'avatar');

                $userModel->updateProfile($userId, [
                    'avatar' => $result['url'],
                ]);

                $freshUser = $userModel->findById($userId);
                if ($freshUser !== null) {
                    $auth->refreshSession($freshUser);
                }

                $csrfToken = Csrf::refresh();
                Response::success('头像更新成功', [
                    'csrf_token' => $csrfToken,
                    'avatar' => $result['url'],
                ]);
                break;

            case 'reset_avatar':
                $userModel->updateProfile($userId, [
                    'avatar' => null,
                ]);

                $freshUser = $userModel->findById($userId);
                if ($freshUser !== null) {
                    $auth->refreshSession($freshUser);
                }

                $csrfToken = Csrf::refresh();
                $defaultAvatar = EM_CONFIG['avatar'] ?? '/content/static/img/avatar.jpeg';
                Response::success('已恢复默认头像', [
                    'csrf_token' => $csrfToken,
                    'avatar' => $defaultAvatar,
                ]);
                break;

            case 'avatar_pick':
                $avatarUrl = (string) Input::post('avatar_url', '');
                if ($avatarUrl === '') {
                    Response::error('请选择头像地址');
                }

                $userModel->updateProfile($userId, [
                    'avatar' => $avatarUrl,
                ]);

                $freshUser = $userModel->findById($userId);
                if ($freshUser !== null) {
                    $auth->refreshSession($freshUser);
                }

                $csrfToken = Csrf::refresh();
                Response::success('头像已更新', [
                    'csrf_token' => $csrfToken,
                    'avatar' => $avatarUrl,
                ]);
                break;

            default:
                Response::error('未知操作');
        }
    } catch (RuntimeException $e) {
        Response::error($e->getMessage());
    } catch (Throwable $e) {
        Response::error('系统繁忙，请稍后再试');
    }
}

// 渲染页面前重新获取最新用户数据
$userModel = new UserModel();
$userFull = $userModel->findById((int) $user['id']);
$csrfToken = Csrf::token();

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/profile.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/profile.php';
    require __DIR__ . '/index.php';
}
