<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 用户中心 - 我的订单。
 *
 * 支持按状态筛选 + 分页。
 */
userRequireLogin();

$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();
$displayMoney = '0.00';
if (!empty($frontUser['money'])) {
    $displayMoney = bcdiv((string) $frontUser['money'], '1000000', 2);
}
// 筛选与分页
// status 允许值：all / pending / paid / delivered / completed / refunding / refunded
$allowedStatus = ['all', 'pending', 'paid', 'delivering', 'delivered', 'completed', 'refunding', 'refunded', 'cancelled'];
$status = (string) Input::get('status', 'all');
if (!in_array($status, $allowedStatus, true)) {
    $status = 'all';
}
$page = max(1, (int) Input::get('page', 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$prefix = Database::prefix();
$userId = (int) $frontUser['id'];

// 组装 WHERE
$where = ['user_id = ?'];
$params = [$userId];
if ($status !== 'all') {
    $where[] = 'status = ?';
    $params[] = $status;
}
$whereSql = implode(' AND ', $where);

// 总数
$countRow = Database::fetchOne(
    "SELECT COUNT(*) AS cnt FROM {$prefix}order WHERE {$whereSql}",
    $params
);
$total = (int) ($countRow['cnt'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// 列表（简要字段）—— 带展示货币快照字段，供视图按订单冻结币种渲染
$orders = Database::query(
    "SELECT id, order_no, pay_amount, status, payment_name, pay_time, created_at,
            display_currency_code, display_rate
     FROM {$prefix}order
     WHERE {$whereSql}
     ORDER BY id DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
);

// 批量加载订单商品快照
$orderIds = array_column($orders, 'id');
$orderGoodsMap = [];
if (!empty($orderIds)) {
    $in = implode(',', array_fill(0, count($orderIds), '?'));
    $rows = Database::query(
        "SELECT order_id, goods_title, spec_name, cover_image, price, quantity
         FROM {$prefix}order_goods
         WHERE order_id IN ({$in})
         ORDER BY id ASC",
        $orderIds
    );
    foreach ($rows as $r) {
        $orderGoodsMap[(int) $r['order_id']][] = $r;
    }
}

// 状态筛选 tabs 数据
// "待发货"对应 status=paid（已付款尚未发货），文字上更贴近用户视角
// "待收货"对应 status=delivered（已发货尚未完成）
$statusTabs = [
    'all'        => '全部',
    'pending'    => '待付款',
    'paid'       => '待发货',
    'delivered'  => '待收货',
    'completed'  => '已完成',
    'refunded'   => '已退款',
    'cancelled'  => '已取消',
];

// tab 计数：按当前用户分组统计每种状态的订单数，一条 SQL 拿回全部
$statusCounts = array_fill_keys(array_keys($statusTabs), 0);
$rows = Database::query(
    "SELECT status, COUNT(*) AS cnt FROM {$prefix}order WHERE user_id = ? GROUP BY status",
    [$userId]
);
$totalAll = 0;
foreach ($rows as $row) {
    $cnt = (int) $row['cnt'];
    $totalAll += $cnt;
    if (isset($statusCounts[$row['status']])) {
        $statusCounts[$row['status']] = $cnt;
    }
}
$statusCounts['all'] = $totalAll;

// 异步发货轮询：全账号「待队列发货」订单快照（与 order_poll.php pending_snapshot 算法一致）
$pendingSnapRows = Database::query(
    "SELECT `order_no`, `status` FROM `{$prefix}order`
     WHERE `user_id` = ? AND `status` IN ('paid','delivering')
     ORDER BY `id` ASC",
    [$userId]
);
$pendingSnapPairs = [];
foreach ($pendingSnapRows as $r) {
    $pendingSnapPairs[] = (string) ($r['order_no'] ?? '') . '|' . (string) ($r['status'] ?? '');
}
$pendingDeliveryHash = $pendingSnapPairs === [] ? 'empty' : md5(implode("\n", $pendingSnapPairs));

if (Request::isPjax()) {
    echo '<div id="userContent" class="uc-content">';
    include userViewFile('order');
    echo '</div>';
} else {
    $userContentView = userViewFile('order');
    require __DIR__ . '/index.php';
}
