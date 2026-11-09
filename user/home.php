<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 用户中心 - 概览首页。
 */
userRequireLogin();

$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();
$displayMoney = '0.00';
if (!empty($frontUser['money'])) {
    $displayMoney = bcdiv((string) $frontUser['money'], '1000000', 2);
}

if (Request::isPjax()) {
    echo '<div id="userContent" class="uc-content">';
    include userViewFile('home');
    echo '</div>';
} else {
    $userContentView = userViewFile('home');
    require __DIR__ . '/index.php';
}
