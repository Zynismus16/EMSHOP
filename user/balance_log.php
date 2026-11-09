<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 用户中心 - 余额明细。
 */
userRequireLogin();

$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();
$displayMoney = '0.00';
if (!empty($frontUser['money'])) {
    $displayMoney = bcdiv((string) $frontUser['money'], '1000000', 2);
}
// 分页参数
$page = max(1, (int) Input::get('page', 1));
$perPage = 5;

// 查询余额变动记录
$balanceLogModel = new UserBalanceLogModel();
$result = $balanceLogModel->getListByUser((int) $frontUser['id'], $page, $perPage);

$logList    = $result['list'];
$total      = $result['total'];
$totalPages = $result['total_pages'];

if (Request::isPjax()) {
    echo '<div id="userContent" class="uc-content">';
    include userViewFile('balance_log');
    echo '</div>';
} else {
    $userContentView = userViewFile('balance_log');
    require __DIR__ . '/index.php';
}
