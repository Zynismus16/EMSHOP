<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 用户中心 - 我的优惠券。
 *
 * 4 个 tab：未使用 / 已使用 / 已过期 / 已失效
 * 已过期/已失效不落表，通过 join em_coupon 动态判断。
 */
userRequireLogin();

if (!shop_coupon_enabled()) {
    if (Request::isPjax() || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        Response::error('优惠券功能未启用');
    }
    header('Location: /user/home.php');
    exit;
}

$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();
$displayMoney = '0.00';
if (!empty($frontUser['money'])) {
    $displayMoney = bcdiv((string) $frontUser['money'], '1000000', 2);
}
// tab 白名单
$allowed = [
    UserCouponModel::VIEW_UNUSED,
    UserCouponModel::VIEW_USED,
    UserCouponModel::VIEW_EXPIRED,
    UserCouponModel::VIEW_INVALID,
];
$view = (string) Input::get('view', UserCouponModel::VIEW_UNUSED);
if (!in_array($view, $allowed, true)) $view = UserCouponModel::VIEW_UNUSED;

$userCouponModel = new UserCouponModel();
$coupons = $userCouponModel->listByView((int) $frontUser['id'], $view, 200);
$counts  = $userCouponModel->countByViews((int) $frontUser['id']);

$viewTabs = [
    UserCouponModel::VIEW_UNUSED  => '未使用',
    UserCouponModel::VIEW_USED    => '已使用',
    UserCouponModel::VIEW_EXPIRED => '已过期',
    UserCouponModel::VIEW_INVALID => '已失效',
];

if (Request::isPjax()) {
    echo '<div id="userContent" class="uc-content">';
    include userViewFile('coupon');
    echo '</div>';
} else {
    $userContentView = userViewFile('coupon');
    require __DIR__ . '/index.php';
}
