<?php
defined('EM_ROOT') || exit('access denied!');

/**
 * 虚拟卡密插件 · 前台展示入口
 *
 * 访问路径：/?plugin=virtual_card[&action=...]
 *   - action=export_order_cards  按订单 ID 导出已发卡密为 txt
 *   - 不带 action                展示占位说明（本插件不对外暴露主页面）
 *
 * 权限检查：
 *   - 登录用户：订单 user_id 匹配 em_front_user.id
 *   - 游客：v2 签名 Cookie 的 guest_token 匹配订单 guest_token
 *   - 凭据查单（订单密码/联系方式）换来的 60 秒一次性 grant
 *   满足任一即放行；都不满足返回 403
 */

require_once __DIR__ . '/../../../user/global_public.php';
require_once __DIR__ . '/inc_export_filename.php';

$action = (string) Input::get('action', '');

// ================================================================
// 导出订单卡密 txt
// ================================================================
if ($action === 'export_order_cards') {
    $orderId = (int) Input::get('order_id', 0);
    if ($orderId <= 0) {
        http_response_code(400);
        exit('订单ID不能为空');
    }

    $prefix = Database::prefix();
    $order = Database::fetchOne(
        "SELECT id, order_no, user_id, guest_token, created_at FROM {$prefix}order WHERE id = ?",
        [$orderId]
    );
    if (!$order) {
        http_response_code(404);
        exit('订单不存在');
    }

    // 权限校验：
    //   1) 登录用户本人；
    //   2) v2 签名 Cookie 与订单 guest_token 一致；
    //   3) 凭据查单（订单密码/联系方式）换来的 60 秒一次性 grant，用完即焚。
    // 不再接受「查单 session 白名单」：订单号 + 算术验证码不再等于卡密导出权。
    $isOwner = false;
    if (!empty($frontUser) && (int) $order['user_id'] === (int) $frontUser['id']) {
        $isOwner = true;
    } elseif (empty($frontUser) && GuestToken::hasValidCookie()
        && (string) ($order['guest_token'] ?? '') !== ''
        && hash_equals((string) $order['guest_token'], GuestToken::get())) {
        $isOwner = true;
    } else {
        if (session_status() === PHP_SESSION_NONE) @session_start();
        $grant = (int) ($_SESSION['em_card_grant'][$orderId] ?? 0);
        if ($grant >= time()) {
            unset($_SESSION['em_card_grant'][$orderId]); // 一次性，用后立即失效
            $isOwner = true;
        }
    }
    if (!$isOwner) {
        http_response_code(403);
        exit('无权访问该订单');
    }

    // 取出该订单所有 virtual_card 行（goods_type 是 order_goods 的快照字段）
    $rows = Database::query(
        "SELECT id, goods_title, delivery_content
         FROM {$prefix}order_goods
         WHERE order_id = ? AND goods_type = 'virtual_card'
         ORDER BY id",
        [$orderId]
    );

    // 兼容历史数据：
    // 若卡密被拆分存储为 card_no + card_pwd，则导出时拼回 card_no:card_pwd。
    // 优先走 goods_virtual_card（按 order_id 关联）；查不到再回退 delivery_content。
    $lines = [];
    $titleSet = [];
    $cardRows = Database::query(
        "SELECT card_no, card_pwd
         FROM {$prefix}goods_virtual_card
         WHERE order_id = ?
         ORDER BY id",
        [$orderId]
    );
    foreach ($rows as $row) {
        $gt = trim((string) ($row['goods_title'] ?? ''));
        if ($gt !== '') {
            $titleSet[$gt] = true;
        }
    }
    if (!empty($cardRows)) {
        foreach ($cardRows as $card) {
            $no = trim((string) ($card['card_no'] ?? ''));
            if ($no === '') {
                continue;
            }
            $pwd = trim((string) ($card['card_pwd'] ?? ''));
            $lines[] = $pwd !== '' ? ($no . ':' . $pwd) : $no;
        }
    } else {
        foreach ($rows as $row) {
            $content = trim(OrderModel::getDeliveryContent((int) ($row['id'] ?? 0), (string) ($row['delivery_content'] ?? '')));
            if ($content === '') {
                continue;
            }
            foreach (preg_split("/\r\n|\r|\n/", $content) as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }
    }
    $body = implode("\n", $lines);
    $count = count($lines);
    if (count($titleSet) === 1) {
        $nameBase = array_key_first($titleSet);
    } else {
        $nameBase = '订单' . (string) $order['order_no'];
    }
    $placeYmd = virtual_card_datetime_to_ymd((string) ($order['created_at'] ?? ''));
    $filename = virtual_card_build_export_filename($nameBase, $count, $placeYmd);

    // 清空可能的 output buffer 防止污染
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: ' . virtual_card_export_disposition($filename));
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    echo $body;
    exit;
}

// 默认：插件不对外暴露主页面，给一个最简提示
http_response_code(404);
echo '此插件无独立展示页';
