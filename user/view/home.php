<?php
/**
 * 用户中心 - 概览首页（毛玻璃版）
 */
$displayMoney = $displayMoney ?? '0.00';
$currencySymbol = $currencySymbol ?? '¥';

$prefix = Database::prefix();
$userId = (int) ($frontUser['id'] ?? 0);

$stats = [
    'order_total'      => 0,
    'order_pending'    => 0,
    'order_delivering' => 0,
    'order_completed'  => 0,
    'month_spent'      => 0,
    'today_spent'      => 0,
];
$recentOrders = [];

// 游客（user_id = 0）在 order 表里代表「所有游客单」，不是「当前访客」。
// 未登录时不能拿 0 去查订单：否则 WHERE user_id = 0 会把全站游客订单的
// 订单号、金额、状态以及今日/本月成交额汇总暴露给任何匿名访问者。
if ($userId > 0) {
    try {
        $row = Database::fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(status='pending') AS pending,
                    SUM(status IN ('paid','delivering','delivered')) AS delivering,
                    SUM(status='completed') AS completed
               FROM `{$prefix}order` WHERE user_id = ?",
            [$userId]
        );
        if ($row) {
            $stats['order_total']      = (int) $row['total'];
            $stats['order_pending']    = (int) $row['pending'];
            $stats['order_delivering'] = (int) $row['delivering'];
            $stats['order_completed']  = (int) $row['completed'];
        }

        $monthRow = Database::fetchOne(
            "SELECT COALESCE(SUM(pay_amount), 0) AS s
               FROM `{$prefix}order`
              WHERE user_id = ? AND status IN ('paid','delivering','delivered','completed')
                AND DATE_FORMAT(pay_time, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')",
            [$userId]
        );
        $stats['month_spent'] = (int) ($monthRow['s'] ?? 0);

        $todayRow = Database::fetchOne(
            "SELECT COALESCE(SUM(pay_amount), 0) AS s
               FROM `{$prefix}order`
              WHERE user_id = ? AND status IN ('paid','delivering','delivered','completed')
                AND DATE(pay_time) = CURDATE()",
            [$userId]
        );
        $stats['today_spent'] = (int) ($todayRow['s'] ?? 0);
    } catch (Throwable $e) {
    }

    try {
        $recentOrders = Database::query(
            "SELECT id, order_no, pay_amount, status, created_at, display_currency_code, display_rate
               FROM `{$prefix}order`
              WHERE user_id = ?
              ORDER BY id DESC LIMIT 5",
            [$userId]
        );
    } catch (Throwable $e) {}
}

$statusLabels = [
    'pending'    => ['label' => '待付款', 'color' => '#92682a', 'bg' => 'rgba(234, 179, 98, 0.18)'],
    'paid'       => ['label' => '已付款', 'color' => '#4a6278', 'bg' => 'rgba(139, 164, 196, 0.18)'],
    'delivering' => ['label' => '发货中', 'color' => '#556580', 'bg' => 'rgba(154, 168, 196, 0.18)'],
    'delivered'  => ['label' => '已发货', 'color' => '#6a6080', 'bg' => 'rgba(168, 158, 196, 0.16)'],
    'completed'  => ['label' => '已完成', 'color' => '#3d7358', 'bg' => 'rgba(127, 176, 154, 0.18)'],
    'refunding'  => ['label' => '退款中', 'color' => '#92682a', 'bg' => 'rgba(234, 179, 98, 0.18)'],
    'refunded'   => ['label' => '已退款', 'color' => '#64748b', 'bg' => 'rgba(148, 163, 184, 0.16)'],
    'cancelled'  => ['label' => '已取消', 'color' => '#64748b', 'bg' => 'rgba(148, 163, 184, 0.14)'],
    'expired'    => ['label' => '已过期', 'color' => '#64748b', 'bg' => 'rgba(148, 163, 184, 0.14)'],
    'failed'     => ['label' => '失败',   'color' => '#9f4a52', 'bg' => 'rgba(196, 137, 143, 0.16)'],
];

$hour = (int) date('G');
if ($hour < 5)       $greet = '夜深了';
elseif ($hour < 11)  $greet = '早上好';
elseif ($hour < 13)  $greet = '中午好';
elseif ($hour < 18)  $greet = '下午好';
else                 $greet = '晚上好';

$nickname = htmlspecialchars($frontUser['nickname'] ?? $frontUser['username'] ?? '用户');
$fmtMoney = static fn(int $raw): string => Currency::displayAmount($raw);
$balanceDisplay = Currency::displayAmount((int) ($frontUser['money'] ?? 0));
$hasAvatar = !empty($frontUser['avatar']);
?>
<div class="uc-page uc-home">

    <!-- 账户概览 -->
    <section class="uc-home-hero">
        <div class="uc-home-hero__glow" aria-hidden="true">
            <span class="uc-home-hero__orb uc-home-hero__orb--lg"></span>
            <span class="uc-home-hero__orb uc-home-hero__orb--sm"></span>
        </div>

        <div class="uc-home-hero__inner">
            <div class="uc-home-hero__profile">
                <div class="uc-home-hero__avatar">
                    <?php if ($hasAvatar): ?>
                    <img src="<?= htmlspecialchars($frontUser['avatar']) ?>" alt="">
                    <?php else: ?>
                    <i class="fa fa-user"></i>
                    <?php endif; ?>
                </div>
                <div class="uc-home-hero__intro">
                    <p class="uc-home-hero__eyebrow"><?= date('Y年n月j日') ?> · 星期<?= ['日','一','二','三','四','五','六'][(int) date('w')] ?></p>
                    <h1 class="uc-home-hero__title"><?= $greet ?>，<?= $nickname ?></h1>
                    <p class="uc-home-hero__desc">订单、钱包与账户设置都在这里</p>
                </div>
            </div>
            <div class="uc-home-hero__actions">
                <a href="/" class="uc-home-hero__btn uc-home-hero__btn--solid">
                    <i class="fa fa-shopping-bag"></i> 去逛逛
                </a>
                <a href="/user/wallet.php" data-pjax="#userContent" class="uc-home-hero__btn">
                    <i class="fa fa-credit-card"></i> 充值
                </a>
            </div>
        </div>

        <div class="uc-home-hero__stats">
            <div class="uc-home-stat uc-home-stat--primary">
                <span class="uc-home-stat__label">账户余额</span>
                <strong class="uc-home-stat__value"><?= $balanceDisplay ?></strong>
            </div>
            <div class="uc-home-stat">
                <span class="uc-home-stat__label">今日消费</span>
                <strong class="uc-home-stat__value"><?= $fmtMoney((int) $stats['today_spent']) ?></strong>
            </div>
            <div class="uc-home-stat">
                <span class="uc-home-stat__label">本月消费</span>
                <strong class="uc-home-stat__value"><?= $fmtMoney((int) $stats['month_spent']) ?></strong>
            </div>
        </div>
    </section>

    <!-- 订单概览 -->
    <section class="uc-home-metrics">
        <a href="/user/order.php" data-pjax="#userContent" class="uc-home-metric uc-glass-card">
            <span class="uc-home-metric__icon uc-home-metric__icon--indigo"><i class="fa fa-shopping-bag"></i></span>
            <span class="uc-home-metric__body">
                <strong class="uc-home-metric__value"><?= (int) $stats['order_total'] ?></strong>
                <span class="uc-home-metric__label">全部订单</span>
            </span>
            <i class="fa fa-angle-right uc-home-metric__arrow"></i>
        </a>
        <a href="/user/order.php?status=pending" data-pjax="#userContent" class="uc-home-metric uc-glass-card">
            <span class="uc-home-metric__icon uc-home-metric__icon--amber"><i class="fa fa-hourglass-half"></i></span>
            <span class="uc-home-metric__body">
                <strong class="uc-home-metric__value"><?= (int) $stats['order_pending'] ?></strong>
                <span class="uc-home-metric__label">待付款</span>
            </span>
            <i class="fa fa-angle-right uc-home-metric__arrow"></i>
        </a>
        <a href="/user/order.php?status=delivering" data-pjax="#userContent" class="uc-home-metric uc-glass-card">
            <span class="uc-home-metric__icon uc-home-metric__icon--blue"><i class="fa fa-truck"></i></span>
            <span class="uc-home-metric__body">
                <strong class="uc-home-metric__value"><?= (int) $stats['order_delivering'] ?></strong>
                <span class="uc-home-metric__label">待收货</span>
            </span>
            <i class="fa fa-angle-right uc-home-metric__arrow"></i>
        </a>
        <a href="/user/order.php?status=completed" data-pjax="#userContent" class="uc-home-metric uc-glass-card">
            <span class="uc-home-metric__icon uc-home-metric__icon--emerald"><i class="fa fa-check-circle"></i></span>
            <span class="uc-home-metric__body">
                <strong class="uc-home-metric__value"><?= (int) $stats['order_completed'] ?></strong>
                <span class="uc-home-metric__label">已完成</span>
            </span>
            <i class="fa fa-angle-right uc-home-metric__arrow"></i>
        </a>
    </section>

    <!-- 常用功能 + 最近订单 -->
    <section class="uc-home-bento">
        <div class="uc-glass-card uc-home-panel">
            <header class="uc-home-panel__head">
                <h2 class="uc-home-panel__title"><i class="fa fa-th-large"></i> 常用功能</h2>
            </header>
            <div class="uc-home-quick">
                <a href="/user/profile.php" data-pjax="#userContent" class="uc-home-quick__item">
                    <span class="uc-home-quick__icon uc-home-quick__icon--indigo"><i class="fa fa-user"></i></span>
                    <span class="uc-home-quick__text">个人资料</span>
                </a>
                <a href="/user/order.php" data-pjax="#userContent" class="uc-home-quick__item">
                    <span class="uc-home-quick__icon uc-home-quick__icon--blue"><i class="fa fa-list-alt"></i></span>
                    <span class="uc-home-quick__text">我的订单</span>
                </a>
                <a href="/user/wallet.php" data-pjax="#userContent" class="uc-home-quick__item">
                    <span class="uc-home-quick__icon uc-home-quick__icon--emerald"><i class="fa fa-credit-card"></i></span>
                    <span class="uc-home-quick__text">我的钱包</span>
                </a>
                <a href="/user/balance_log.php" data-pjax="#userContent" class="uc-home-quick__item">
                    <span class="uc-home-quick__icon uc-home-quick__icon--teal"><i class="fa fa-exchange"></i></span>
                    <span class="uc-home-quick__text">余额明细</span>
                </a>
                <?php if (shop_coupon_enabled()): ?>
                <a href="/user/coupon.php" data-pjax="#userContent" class="uc-home-quick__item">
                    <span class="uc-home-quick__icon uc-home-quick__icon--rose"><i class="fa fa-ticket"></i></span>
                    <span class="uc-home-quick__text">优惠券</span>
                </a>
                <?php endif; ?>
                <?php if (MerchantContext::currentId() === 0): ?>
                <a href="/user/rebate.php" data-pjax="#userContent" class="uc-home-quick__item">
                    <span class="uc-home-quick__icon uc-home-quick__icon--amber"><i class="fa fa-share-alt"></i></span>
                    <span class="uc-home-quick__text">我的推广</span>
                </a>
                <?php endif; ?>
                <a href="/user/address.php" data-pjax="#userContent" class="uc-home-quick__item">
                    <span class="uc-home-quick__icon uc-home-quick__icon--violet"><i class="fa fa-map-marker"></i></span>
                    <span class="uc-home-quick__text">收货地址</span>
                </a>
                <a href="/user/api.php" data-pjax="#userContent" class="uc-home-quick__item">
                    <span class="uc-home-quick__icon uc-home-quick__icon--slate"><i class="fa fa-plug"></i></span>
                    <span class="uc-home-quick__text">API 对接</span>
                </a>
                <a href="/user/find_order.php" class="uc-home-quick__item">
                    <span class="uc-home-quick__icon uc-home-quick__icon--cyan"><i class="fa fa-search"></i></span>
                    <span class="uc-home-quick__text">订单查询</span>
                </a>
            </div>
        </div>

        <div class="uc-glass-card uc-home-panel">
            <header class="uc-home-panel__head">
                <h2 class="uc-home-panel__title"><i class="fa fa-history"></i> 最近订单</h2>
                <a href="/user/order.php" data-pjax="#userContent" class="uc-home-panel__more">全部 <i class="fa fa-angle-right"></i></a>
            </header>

            <?php if (empty($recentOrders)): ?>
            <div class="uc-home-empty">
                <div class="uc-home-empty__icon"><i class="fa fa-inbox"></i></div>
                <p>暂无订单记录</p>
                <a href="/" class="uc-home-empty__btn">去逛逛</a>
            </div>
            <?php else: ?>
            <div class="uc-home-orders">
                <?php foreach ($recentOrders as $o):
                    $st = $statusLabels[$o['status']] ?? ['label' => $o['status'], 'color' => '#64748b', 'bg' => 'rgba(148,163,184,0.15)'];
                ?>
                <a href="/user/order_detail.php?order_no=<?= urlencode($o['order_no']) ?>" data-pjax="#userContent" class="uc-home-order">
                    <div class="uc-home-order__main">
                        <span class="uc-home-order__no">#<?= htmlspecialchars($o['order_no']) ?></span>
                        <span class="uc-home-order__time"><?= htmlspecialchars($o['created_at']) ?></span>
                    </div>
                    <div class="uc-home-order__meta">
                        <span class="uc-home-order__amount"><?= Currency::displaySnapshot((int) $o['pay_amount'], (string) ($o['display_currency_code'] ?? ''), (int) ($o['display_rate'] ?? 0)) ?></span>
                        <span class="uc-home-order__status" style="color:<?= $st['color'] ?>;background:<?= $st['bg'] ?>;"><?= $st['label'] ?></span>
                        <i class="fa fa-angle-right uc-home-order__chev"></i>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>
</div>
