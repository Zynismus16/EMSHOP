<?php

declare(strict_types=1);

require dirname(__DIR__) . '/init.php';

/**
 * 用户中心公共文件。
 *
 * 提供登录校验、当前用户信息获取等能力。
 */

/** @var array<string, mixed>|null 当前登录的前台用户 */
$frontUser = null;

/**
 * 前台用户登录校验。
 *
 * 未登录时重定向到登录页。
 */
function userRequireLogin(): void
{
    global $frontUser;

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['em_front_user'])) {
        // PJAX / AJAX 请求返回 JSON
        if (Request::isPjax() || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            Response::error('请先登录');
        }
        header('Location: /?c=login');
        exit;
    }

    $frontUser = $_SESSION['em_front_user'];

    // 从数据库刷新余额等实时数据
    $userModel = new UserListModel();
    $fresh = $userModel->findById((int) $frontUser['id']);
    if ($fresh !== null) {
        $frontUser['money']        = (int) ($fresh['money'] ?? 0);
        $frontUser['nickname']     = (string) ($fresh['nickname'] ?: $fresh['username']);
        $frontUser['avatar']       = (string) $fresh['avatar'];
        $frontUser['email']        = (string) $fresh['email'];
        $frontUser['mobile']       = (string) ($fresh['mobile'] ?? '');
        $frontUser['secret']       = $fresh['secret'] ?? null;
        $frontUser['merchant_id']  = (int) ($fresh['merchant_id'] ?? 0);
        $frontUser['shop_balance'] = (int) ($fresh['shop_balance'] ?? 0);
        $_SESSION['em_front_user'] = $frontUser;
    }
}

/**
 * 解析用户中心视图文件路径。
 *
 * 用户中心是独立入口（不走控制器），原先直接 include user/view/*.php，
 * 主题无法定制。这里补一层「主题可选覆盖」，查找顺序与 View::findTemplate 一致：
 *   1) content/template/{当前主题}/user/{name}.php
 *   2) content/template/default/user/{name}.php
 *   3) user/view/{name}.php（核心兜底）
 *
 * 主题不提供 user/ 目录时，返回结果与旧版完全相同，老主题零改动。
 *
 * @param string $name 视图名（不含 .php），如 index、home、order
 */
function userViewFile(string $name): string
{
    // 兜底防路径穿越；调用方传的都是固定字面量，这里只是多一层保险
    if (preg_match('/^[a-z0-9_]+$/i', $name) !== 1) {
        return EM_ROOT . '/user/view/' . $name . '.php';
    }

    // 主题只算一次：一个页面里 index.php 会连续调用两次
    static $theme = null;
    if ($theme === null) {
        $theme = '';
        if (class_exists('View')) {
            $theme = (string) View::getInstance()->getTheme();
        }

        // 用户中心是独立入口，不经过 Dispatcher，View 里通常还没有主题。
        // 这里按 Dispatcher::getActiveTheme() 的同一规则现算一次（含商户 scope + 设备）。
        if ($theme === '' && class_exists('MerchantContext') && class_exists('TemplateModel')) {
            try {
                $scope = MerchantContext::currentId() > 0
                    ? 'merchant_' . MerchantContext::currentId()
                    : 'main';
                $templateModel = new TemplateModel();
                $theme = (string) $templateModel->getEffectiveTheme(
                    TemplateModel::detectClientFromRequest(),
                    $scope
                );
            } catch (Throwable $e) {
                $theme = '';
            }
        }
    }

    $themes = [];
    if ($theme !== '') {
        $themes[] = $theme;
    }
    $themes[] = 'default';

    foreach (array_unique($themes) as $candidate) {
        $file = EM_ROOT . '/content/template/' . $candidate . '/user/' . $name . '.php';
        if (is_file($file)) {
            return $file;
        }
    }

    return EM_ROOT . '/user/view/' . $name . '.php';
}
