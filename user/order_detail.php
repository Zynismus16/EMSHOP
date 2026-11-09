<?php

declare(strict_types=1);

require_once __DIR__ . '/global_public.php';

/**
 * 订单详情页。
 * 支持登录用户和游客访问（通过 guest_token 校验权限）。
 */

$siteName = Config::get('sitename', 'EMSHOP');

// 获取订单
$orderNo = (string) Input::get('order_no', '');
$order = null;
$orderGoods = [];

if ($orderNo !== '') {
    $order = OrderModel::getByOrderNo($orderNo);

    if ($order) {
        // 权限校验
        $isOwner = false;
        if ($frontUser && (int) $order['user_id'] === (int) $frontUser['id']) {
            $isOwner = true;
        } elseif (empty($frontUser) && GuestToken::hasValidCookie()
            && (string) ($order['guest_token'] ?? '') !== ''
            && hash_equals((string) $order['guest_token'], GuestToken::get())) {
            $isOwner = true;
        } elseif (empty($frontUser)) {
            // 凭据查单换来的 60 秒 grant 也允许查看（导出时才消耗 grant）
            if (session_status() === PHP_SESSION_NONE) @session_start();
            $grant = (int) ($_SESSION['em_card_grant'][(int) ($order['id'] ?? 0)] ?? 0);
            if ($grant >= time()) {
                $isOwner = true;
            }
        }

        if (!$isOwner) {
            $order = null;
        } else {
            $orderGoods = OrderModel::getOrderGoods((int) $order['id']);
            if (OrderModel::isPurchasedStatus((string) $order['status'])) {
                $orderGoods = OrderModel::attachGoodsGuides($orderGoods);
            }
        }
    }
}

if (Request::isPjax()) {
    echo '<div id="userContent" class="uc-content">';
    include userViewFile('order_detail');
    echo '</div>';
} else {
    $userContentView = userViewFile('order_detail');
    require __DIR__ . '/index_public.php';
}
