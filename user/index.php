<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 用户中心主入口。
 *
 * 负责加载完整页面框架（侧边栏 + 内容区），
 * 子页面通过 $userContentView 注入内容。
 */
userRequireLogin();

$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();

// 余额按访客当前展示币种换算（不带符号；view 里前面会拼 $currencySymbol）
$displayMoney = Currency::displayAmount((int) ($frontUser['money'] ?? 0), null, false);
$currencySymbol = Currency::visitorSymbol();

// 默认内容视图：个人中心首页
if (empty($userContentView)) {
    $userContentView = userViewFile('home');
}

include userViewFile('index');
