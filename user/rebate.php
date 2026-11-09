<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 用户中心 - 我的推广。
 *
 * 推广返佣只在主站启用；商户子域名（MerchantContext::currentId() > 0）访问时
 * 直接跳回个人中心首页，避免误以为商户站也支持邀请赚佣金。
 */
userRequireLogin();

if (MerchantContext::currentId() > 0) {
    header('Location: /user/home.php');
    exit;
}

$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();
$displayMoney = '0.00';
if (!empty($frontUser['money'])) {
    $displayMoney = bcdiv((string) $frontUser['money'], '1000000', 2);
}
$userId = (int) $frontUser['id'];

// 懒执行：把到期 frozen 转为 available
(new CommissionLogModel())->promoteMatured($userId);

// 最新用户数据
$row = Database::find('user', $userId);
$frozen = (int) ($row['commission_frozen'] ?? 0);
$available = (int) ($row['commission_available'] ?? 0);
$inviteCode = trim((string) ($row['invite_code'] ?? ''));
if ($inviteCode === '') {
    $inviteCode = InviteToken::generateUniqueCode();
    Database::update('user', ['invite_code' => $inviteCode], $userId);
}

// 统计
$prefix = Database::prefix();
$directCount = Database::fetchOne(
    "SELECT COUNT(*) AS cnt FROM {$prefix}user WHERE inviter_l1 = ?", [$userId]
);
$teamCount = Database::fetchOne(
    "SELECT COUNT(*) AS cnt FROM {$prefix}user WHERE inviter_l1 = ? OR inviter_l2 = ?",
    [$userId, $userId]
);
$totalEarned = Database::fetchOne(
    "SELECT COALESCE(SUM(amount), 0) AS total FROM {$prefix}commission_log
     WHERE user_id = ? AND status IN ('frozen', 'available', 'withdrawn')",
    [$userId]
);

// 推广链接
$buildInviteLink = function (string $code): string {
    if ($code === '') return '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . '/?' . InviteToken::QUERY_PARAM . '=' . rawurlencode($code);
};
$inviteLink = $buildInviteLink($inviteCode);
// 佣金按访客当前展示币种渲染（带符号完整字符串）；数据库里都是主货币 BIGINT ×1000000
$commissionFrozenDisplay    = Currency::displayAmount((int) $frozen);
$commissionAvailableDisplay = Currency::displayAmount((int) $available);
$totalEarnedDisplay         = Currency::displayAmount((int) ($totalEarned['total'] ?? 0));
$rebateEnabled              = RebateService::isEnabled();
$freezeDays                 = RebateService::freezeDays();
$directCountNum             = (int) ($directCount['cnt'] ?? 0);
$teamCountNum               = (int) ($teamCount['cnt'] ?? 0);

if (Request::isPjax()) {
    echo '<div id="userContent" class="uc-content">';
    include userViewFile('rebate');
    echo '</div>';
} else {
    $userContentView = userViewFile('rebate');
    require __DIR__ . '/index.php';
}
