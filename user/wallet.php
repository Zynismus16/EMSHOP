<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 用户中心 - 我的钱包。
 *
 * 展示：余额、充值/提现入口、最近 5 条余额变动。
 * 充值/提现流程暂未实装，入口预留。
 */
userRequireLogin();

$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();

// 余额 / 限额全部换算到访客当前展示币种（数值，不带符号；view 里自行拼 $currencySymbol）
$displayMoney = Currency::displayAmount((int) ($frontUser['money'] ?? 0), null, false);
$currencySymbol = Currency::visitorSymbol();

// 后台配置的充值/提现限额（×1000000 存储）；不带符号，交给 view 拼
$cfgMinRecharge = (int) Config::get('shop_min_recharge', '1000000');      // 1.00
$cfgMaxRecharge = (int) Config::get('shop_max_recharge', '1000000000000'); // 很大
$cfgMinWithdraw = (int) Config::get('shop_withdraw_min', '10000000');      // 10.00
$cfgMaxWithdraw = (int) Config::get('shop_withdraw_max', '5000000000');    // 5000
$displayMinRecharge = Currency::displayAmount($cfgMinRecharge, null, false);
$displayMaxRecharge = Currency::displayAmount($cfgMaxRecharge, null, false);
$displayMinWithdraw = Currency::displayAmount($cfgMinWithdraw, null, false);
$displayMaxWithdraw = Currency::displayAmount($cfgMaxWithdraw, null, false);

// 最近 5 条余额变动记录
$balanceLogModel = new UserBalanceLogModel();
$recentLogsResult = $balanceLogModel->getListByUser((int) $frontUser['id'], 1, 5);
$recentLogs = $recentLogsResult['list'];

// 充值可用的支付方式（排除"余额支付"，不能自己给自己充值）
$paymentMethods = array_values(array_filter(
    PaymentService::getMethods(),
    static fn(array $m): bool => ($m['code'] ?? '') !== 'balance'
));

if (Request::isPjax()) {
    echo '<div id="userContent" class="uc-content">';
    include userViewFile('wallet');
    echo '</div>';
} else {
    $userContentView = userViewFile('wallet');
    require __DIR__ . '/index.php';
}
