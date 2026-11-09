<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 用户中心 - API对接。
 */
userRequireLogin();

$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();
$displayMoney = '0.00';
if (!empty($frontUser['money'])) {
    $displayMoney = bcdiv((string) $frontUser['money'], '1000000', 2);
}
// 接口地址：当前网站链接
$apiUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/';

// APPID = 用户ID
$appId = (string) $frontUser['id'];

// SECRET
$secret = $frontUser['secret'] ?? null;

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
            case 'generate_secret':
                // 生成 SECRET：md5(用户ID + 时间戳 + 随机数) 转大写
                $raw = $userId . microtime(true) . random_int(100000, 999999);
                $newSecret = strtoupper(md5($raw));

                $userModel->update($userId, ['secret' => $newSecret]);

                // 刷新 session
                $frontUser['secret'] = $newSecret;
                $_SESSION['em_front_user'] = $frontUser;

                $csrfToken = Csrf::refresh();
                Response::success('生成成功', [
                    'secret'     => $newSecret,
                    'csrf_token' => $csrfToken,
                ]);
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
    include userViewFile('api');
    echo '</div>';
} else {
    $userContentView = userViewFile('api');
    require __DIR__ . '/index.php';
}
