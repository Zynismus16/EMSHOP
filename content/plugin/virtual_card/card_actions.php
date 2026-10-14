<?php
/**
 * 虚拟商品（自动发货）插件 — 卡密库存 AJAX 接口
 *
 * 通过 admin_plugin_action 钩子由插件自行注册路由，不放在核心代码中。
 * 商户端由 /user/merchant/goods.php 直接 require 本文件，并设置
 * $virtualCardRequireOwnerId 限制只能操作自有商品。
 *
 * 支持的 action：card_list / card_import / card_delete / card_clear_available / card_export / card_manager
 */
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

require_once __DIR__ . '/inc_export_filename.php';

$action = (string)($_GET['_action'] ?? '');

/**
 * 商户端调用时入口会设置 $virtualCardRequireOwnerId；主站后台不设置则跳过。
 */
$virtualCardAssertGoodsAccess = static function (int $goodsId, bool $asPage = false) use (&$virtualCardRequireOwnerId): void {
    if (!isset($virtualCardRequireOwnerId)) {
        return;
    }
    $goods = GoodsModel::getById($goodsId, false);
    if ($goods === null || (int) ($goods['owner_id'] ?? -1) !== (int) $virtualCardRequireOwnerId) {
        if ($asPage) {
            exit('商品不存在或无权限');
        }
        Response::error('商品不存在或无权限');
    }
};

// ================================================================
// 卡密列表（供 AJAX 分页查询）
// ================================================================
if ($action === 'card_list') {
    $goodsId = (int)($_POST['goods_id'] ?? 0);
    if (!$goodsId) {
        Response::error('商品ID不能为空');
    }
    $virtualCardAssertGoodsAccess($goodsId);

    $page = max(1, (int)($_POST['page'] ?? 1));
    $limit = min(100, max(10, (int)($_POST['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $status = $_POST['status'] ?? '';
    $keyword = trim($_POST['keyword'] ?? '');

    $conditions = ['goods_id = ?'];
    $params = [$goodsId];

    if ($status !== '' && $status !== 'all') {
        $conditions[] = 'status = ?';
        $params[] = (int)$status;
    }

    if ($keyword !== '') {
        $conditions[] = '(card_no LIKE ? OR remark LIKE ?)';
        $kw = '%' . $keyword . '%';
        $params[] = $kw;
        $params[] = $kw;
    }

    $specIdFilter = (int)($_POST['spec_id'] ?? 0);
    if ($specIdFilter > 0) {
        $conditions[] = 'spec_id = ?';
        $params[] = $specIdFilter;
    }

    // 订单关联状态筛选。订单被后台删除时卡密不跟着删（order_id 仍留着），
    // 所以「订单已删除」= 有 order_id 但 em_order 里已查不到对应行。
    // order_id > 0 同时把未售卡密（order_id 为 NULL）排除掉。
    $orderState = (string)($_POST['order_state'] ?? '');
    if ($orderState === 'missing' || $orderState === 'exists') {
        $prefix = Database::prefix();
        $conditions[] = 'order_id > 0 AND ' . ($orderState === 'missing' ? 'NOT EXISTS' : 'EXISTS')
            . ' (SELECT 1 FROM `' . $prefix . 'order` o'
            . ' WHERE o.id = `' . $prefix . 'goods_virtual_card`.`order_id`)';
    }

    $whereSql = 'WHERE ' . implode(' AND ', $conditions);

    $total = Database::query(
        "SELECT COUNT(*) as cnt FROM " . Database::prefix() . "goods_virtual_card {$whereSql}",
        $params
    );
    $totalCount = (int)($total[0]['cnt'] ?? 0);

    $cards = Database::query(
        "SELECT * FROM " . Database::prefix() . "goods_virtual_card {$whereSql} ORDER BY id ASC LIMIT {$offset}, {$limit}",
        $params
    );
    // 全局序号（跨页连续，序号 1 = 最早入库），与导出区间一一对应
    foreach ($cards as $i => $c) {
        $cards[$i]['seq'] = $offset + $i + 1;
    }

    // 统计各状态数量（供前端选项卡和规格库存卡片刷新）
    $statsRows = Database::query(
        "SELECT status, COUNT(*) as cnt FROM " . Database::prefix() . "goods_virtual_card WHERE goods_id = ? GROUP BY status",
        [$goodsId]
    );
    $statsMap = ['available' => 0, 'sold' => 0, 'marked' => 0, 'total' => 0];
    foreach ($statsRows as $sr) {
        $cnt = (int)$sr['cnt'];
        $statsMap['total'] += $cnt;
        switch ((int)$sr['status']) {
            case 1: $statsMap['available'] = $cnt; break;
            case 0: $statsMap['sold'] = $cnt; break;
            case 2: $statsMap['marked'] = $cnt; break;
        }
    }

    // 每个规格的可用卡密数（供前端规格库存概览卡片刷新）
    $specStatsRows = Database::query(
        "SELECT spec_id, COUNT(*) as cnt FROM " . Database::prefix() . "goods_virtual_card WHERE goods_id = ? AND status = 1 AND spec_id IS NOT NULL GROUP BY spec_id",
        [$goodsId]
    );
    $specStats = [];
    foreach ($specStatsRows as $ssr) {
        $specStats[] = ['id' => (int)$ssr['spec_id'], 'available' => (int)$ssr['cnt']];
    }
    $statsMap['specs'] = $specStats;

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 0,
        'msg' => '',
        'count' => $totalCount,
        'data' => $cards,
        'stats' => $statsMap,
        'csrf_token' => Csrf::token(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ================================================================
// 导入卡密弹窗页面
// ================================================================
if ($action === 'card_import_page') {
    $goodsId = (int)($_GET['goods_id'] ?? 0);
    if (!$goodsId) {
        exit('商品ID不能为空');
    }
    $virtualCardAssertGoodsAccess($goodsId, true);
    $goods = GoodsModel::getById($goodsId);
    if (!$goods) {
        exit('商品不存在');
    }
    $specs = GoodsModel::getSpecsByGoodsId($goodsId);
    $csrfToken = Csrf::token();
    $pageTitle = '导入卡密';
    // 若入口未预设（主站），保持 header 默认 /admin/index.php
    if (!isset($popupCardActionBase)) {
        $popupCardActionBase = '/admin/index.php';
    }

    include EM_ROOT . '/admin/view/popup/header.php';
    include __DIR__ . '/card_import_page.php';
    include EM_ROOT . '/admin/view/popup/footer.php';
    exit;
}

// ================================================================
// 导入卡密（高性能批量导入）
// ================================================================
if ($action === 'card_import') {
    $goodsId = (int)($_POST['goods_id'] ?? 0);
    if (!$goodsId) {
        Response::error('商品ID不能为空');
    }
    $virtualCardAssertGoodsAccess($goodsId);
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }

    $specId = (int)($_POST['spec_id'] ?? 0);
    if (!$specId) {
        Response::error('请选择规格');
    }

    $content = trim($_POST['cards'] ?? '');
    if ($content === '') {
        Response::error('请输入卡密内容');
    }

    $goods = GoodsModel::getById($goodsId);
    if (!$goods) {
        Response::error('商品不存在');
    }

    $order = $_POST['order'] ?? 'asc';    // asc / desc / shuffle
    $dedup = !empty($_POST['dedup']);       // 是否去重
    $remark = trim($_POST['remark'] ?? '');
    $prefix = Database::prefix();
    $now = date('Y-m-d H:i:s');

    // 1. 解析所有行：一行就是一条完整卡密，不做分割
    $lines = explode("\n", $content);
    $parsed = []; // [{card_no}, ...]
    $cardNos = []; // 用于去重查询
    foreach ($lines as $line) {
        $raw = trim($line);
        if ($raw === '') continue;

        $cardNo = $raw;
        $cardNo = trim($cardNo);

        if ($cardNo === '') continue;

        // 输入内去重（仅勾选去重时，相同卡号取第一条）
        if ($dedup) {
            if (isset($cardNos[$cardNo])) continue;
            $cardNos[$cardNo] = true;
        }

        $parsed[] = ['card_no' => $cardNo];
    }

    if (empty($parsed)) {
        Response::error('未检测到有效的卡密内容');
    }

    // 2. 排序处理
    if ($order === 'desc') {
        $parsed = array_reverse($parsed);
    } elseif ($order === 'shuffle') {
        shuffle($parsed);
    }

    // 3. 数据库去重：批量查询已存在的卡号（分批查，每批 5000 条）
    $existingSet = [];
    if ($dedup) {
        $allCardNos = array_column($parsed, 'card_no');
        $chunks = array_chunk($allCardNos, 5000);
        foreach ($chunks as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $rows = Database::query(
                "SELECT card_no FROM {$prefix}goods_virtual_card WHERE goods_id = ? AND card_no IN ({$placeholders})",
                array_merge([$goodsId], $chunk)
            );
            foreach ($rows as $r) {
                $existingSet[$r['card_no']] = true;
            }
        }
    }

    // 4. 过滤已存在的卡密
    $toInsert = [];
    $skipped = 0;
    foreach ($parsed as $item) {
        if ($dedup && isset($existingSet[$item['card_no']])) {
            $skipped++;
            continue;
        }
        $toInsert[] = $item;
    }

    if (empty($toInsert)) {
        Response::success("导入完成：成功 0 条，跳过 {$skipped} 条（全部重复）", [
            'imported' => 0,
            'skipped' => $skipped,
            'csrf_token' => Csrf::token(),
        ]);
    }

    // 5. 批量插入（每批 1000 条，拼接 VALUES 多行 INSERT）
    $imported = 0;
    $batchSize = 1000;
    $batches = array_chunk($toInsert, $batchSize);

    foreach ($batches as $batch) {
        $valuesParts = [];
        $params = [];
        foreach ($batch as $item) {
            $valuesParts[] = '(?, ?, ?, ?, 1, ?, ?)';
            $params[] = $goodsId;
            $params[] = $specId;
            $params[] = $item['card_no'];
            $params[] = null;
            $params[] = $remark !== '' ? $remark : null;
            $params[] = $now;
        }
        $sql = "INSERT INTO {$prefix}goods_virtual_card (goods_id, spec_id, card_no, card_pwd, status, remark, created_at) VALUES "
             . implode(',', $valuesParts);
        Database::execute($sql, $params);
        $imported += count($batch);
    }

    // 6. 同步卡密数量到规格库存
    virtualCardSyncCardStock($goodsId);

    $msg = "导入完成：成功 {$imported} 条";
    if ($skipped > 0) {
        $msg .= "，跳过重复 {$skipped} 条";
    }
    Response::success($msg, [
        'imported' => $imported,
        'skipped' => $skipped,
        'csrf_token' => Csrf::token(),
    ]);
}

// ================================================================
// 删除卡密
// ================================================================
if ($action === 'card_delete') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }
    $ids = $_POST['ids'] ?? [];
    if (empty($ids)) {
        Response::error('请选择要删除的卡密');
    }

    if (!is_array($ids)) {
        $ids = [(int)$ids];
    }
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids);

    if (empty($ids)) {
        Response::error('无效的ID');
    }

    // 删除前先获取 goods_id，用于后续同步库存 + 商户归属校验
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $goodsIds = Database::query(
        "SELECT DISTINCT goods_id FROM " . Database::prefix() . "goods_virtual_card WHERE id IN ({$placeholders})",
        $ids
    );
    foreach ($goodsIds as $row) {
        $virtualCardAssertGoodsAccess((int) $row['goods_id']);
    }

    $result = Database::execute(
        "DELETE FROM " . Database::prefix() . "goods_virtual_card WHERE id IN ({$placeholders})",
        $ids
    );

    // 同步卡密数量到规格库存
    foreach ($goodsIds as $row) {
        virtualCardSyncCardStock((int)$row['goods_id']);
    }

    Response::success("已删除 {$result} 条卡密", [
        'deleted' => $result,
        'csrf_token' => Csrf::token(),
    ]);
}

// ================================================================
// 清空库存：删除本商品全部「未售」卡密（status=1），已售 / 标记售出保留
// ================================================================
if ($action === 'card_clear_available') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }
    $goodsId = (int) ($_POST['goods_id'] ?? 0);
    if ($goodsId <= 0) {
        Response::error('商品ID不能为空');
    }
    $virtualCardAssertGoodsAccess($goodsId);

    $goods = GoodsModel::getById($goodsId);
    if (!$goods) {
        Response::error('商品不存在');
    }
    if (($goods['goods_type'] ?? '') !== 'virtual_card') {
        Response::error('非虚拟卡密商品');
    }

    $prefix = Database::prefix();
    $deleted = Database::execute(
        "DELETE FROM {$prefix}goods_virtual_card WHERE goods_id = ? AND status = 1",
        [$goodsId]
    );

    virtualCardSyncCardStock($goodsId);

    Response::success($deleted > 0 ? "已清空 {$deleted} 条未售卡密" : '当前没有未售卡密', [
        'deleted'  => $deleted,
        'csrf_token' => Csrf::token(),
    ]);
}

// ================================================================
// 导出卡密
// ================================================================
if ($action === 'card_export') {
    $goodsId = (int)($_GET['goods_id'] ?? 0);
    if (!$goodsId) {
        exit('商品ID不能为空');
    }
    $virtualCardAssertGoodsAccess($goodsId, true);

    $goods = GoodsModel::getById($goodsId);
    if (!$goods) {
        exit('商品不存在');
    }

    // 筛选口径与 card_list 一致：状态 / 关键字 / 规格 / 订单关联状态
    $status  = (string)($_GET['status'] ?? '');
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $specId  = (int)($_GET['spec_id'] ?? 0);
    $orderState = (string)($_GET['order_state'] ?? '');
    $orderIdFilter = max(0, (int)($_GET['order_id'] ?? 0));
    // 序号区间（1 起，与列表「序号」列对应；0 表示该端不限制）
    $from = max(0, (int)($_GET['from'] ?? 0));
    $to   = max(0, (int)($_GET['to'] ?? 0));

    $conditions = ['goods_id = ?'];
    $params = [$goodsId];

    if ($status !== '' && $status !== 'all') {
        $conditions[] = 'status = ?';
        $params[] = (int)$status;
    }
    if ($keyword !== '') {
        $conditions[] = '(card_no LIKE ? OR remark LIKE ?)';
        $kw = '%' . $keyword . '%';
        $params[] = $kw;
        $params[] = $kw;
    }
    if ($specId > 0) {
        $conditions[] = 'spec_id = ?';
        $params[] = $specId;
    }
    if ($orderState === 'missing' || $orderState === 'exists') {
        $prefix = Database::prefix();
        $conditions[] = 'order_id > 0 AND ' . ($orderState === 'missing' ? 'NOT EXISTS' : 'EXISTS')
            . ' (SELECT 1 FROM `' . $prefix . 'order` o'
            . ' WHERE o.id = `' . $prefix . 'goods_virtual_card`.`order_id`)';
    }

    // 按订单导出：订单ID 一旦指定就只按「商品 + 订单ID」取数，把上面按列表筛选条件
    // 拼出来的条件（状态 / 关键字 / 规格 / 订单关联状态）和序号区间全部丢掉。
    // 否则默认停在「未售」选项卡上时，已售卡密（status=0）永远筛不出来。
    if ($orderIdFilter > 0) {
        $conditions = ['goods_id = ?', 'order_id = ?'];
        $params = [$goodsId, $orderIdFilter];
        $from = 0;
        $to = 0;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $conditions);

    // 序号区间 → LIMIT offset, count（序号 1 = 最早入库，与列表页排序一致）
    $limitSql = '';
    if ($from > 0 || $to > 0) {
        $offset = $from > 0 ? $from - 1 : 0;
        $limitCount = null;
        if ($to > 0) {
            $start = $from > 0 ? $from : 1;
            $limitCount = max(0, $to - $start + 1);
        }
        $limitSql = ' LIMIT ' . $offset . ', ' . ($limitCount !== null ? $limitCount : '18446744073709551615');
    }

    $prefix = Database::prefix();
    $cards = Database::query(
        "SELECT card_no FROM {$prefix}goods_virtual_card {$whereSql} ORDER BY id ASC{$limitSql}",
        $params
    );

    // 一行一个卡密
    $lines = [];
    foreach ($cards as $card) {
        $no = trim((string)($card['card_no'] ?? ''));
        if ($no === '') {
            continue;
        }
        $lines[] = $no;
    }
    $output = implode("\r\n", $lines);
    $count = count($lines);

    $title = (string) ($goods['title'] ?? '');
    $placeYmd = virtual_card_datetime_to_ymd((string) ($goods['created_at'] ?? ''));
    $filename = virtual_card_build_export_filename($title, $count, $placeYmd);

    // 清空输出缓冲，避免前面 PHP 误输出污染下载内容
    while (ob_get_level() > 0) { ob_end_clean(); }

    $body = "\xEF\xBB\xBF" . $output; // 带 BOM，Windows 记事本正确识别 UTF-8
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: ' . virtual_card_export_disposition($filename));
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');

    echo $body;
    exit;
}

// ================================================================
// 保存卡密（编辑单条卡密）
// ================================================================
if ($action === 'card_save') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }
    $id = (int)($_POST['id'] ?? 0);
    $goodsId = (int)($_POST['goods_id'] ?? 0);
    if (!$id || !$goodsId) {
        Response::error('参数不完整');
    }
    $virtualCardAssertGoodsAccess($goodsId);

    $cardNo = trim($_POST['card_no'] ?? '');
    if ($cardNo === '') {
        Response::error('卡号不能为空');
    }

    $cardPwd = trim($_POST['card_pwd'] ?? '');
    $specId  = (int)($_POST['spec_id'] ?? 0);
    $remark  = trim($_POST['remark'] ?? '');

    // 仅允许编辑未售卡密
    $card = Database::fetchOne(
        "SELECT id, status FROM " . Database::prefix() . "goods_virtual_card WHERE id = ? AND goods_id = ?",
        [$id, $goodsId]
    );
    if (!$card) {
        Response::error('卡密不存在');
    }
    if ((int)$card['status'] !== 1) {
        Response::error('仅未售卡密可编辑');
    }

    Database::update('goods_virtual_card', [
        'card_no'  => $cardNo,
        'card_pwd' => $cardPwd !== '' ? $cardPwd : null,
        'spec_id'  => $specId > 0 ? $specId : null,
        'remark'   => $remark !== '' ? $remark : null,
    ], $id);

    // 规格变更可能影响库存统计
    virtualCardSyncCardStock($goodsId);

    Response::success('保存成功', ['csrf_token' => Csrf::token()]);
}

// ================================================================
// 优先销售（切换销售优先级）
// ================================================================
if ($action === 'card_priority') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }
    $id = (int)($_POST['id'] ?? 0);
    $goodsId = (int)($_POST['goods_id'] ?? 0);
    if (!$id || !$goodsId) {
        Response::error('参数不完整');
    }
    $virtualCardAssertGoodsAccess($goodsId);

    $card = Database::fetchOne(
        "SELECT id, sell_priority FROM " . Database::prefix() . "goods_virtual_card WHERE id = ? AND goods_id = ? AND status = 1",
        [$id, $goodsId]
    );
    if (!$card) {
        Response::error('卡密不存在或不可操作');
    }

    // 切换优先状态：已设优先则取消，未设则设置为当前时间戳
    $newPriority = ((int)($card['sell_priority'] ?? 0) > 0) ? 0 : time();
    Database::update('goods_virtual_card', ['sell_priority' => $newPriority], $id);

    $msg = $newPriority > 0 ? '已设为优先销售' : '已取消优先销售';
    Response::success($msg, ['csrf_token' => Csrf::token()]);
}

// ================================================================
// 标记售出（手动将卡密标记为已售出）
// ================================================================
if ($action === 'card_mark_sold') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }
    $id = (int)($_POST['id'] ?? 0);
    $goodsId = (int)($_POST['goods_id'] ?? 0);
    if (!$id || !$goodsId) {
        Response::error('参数不完整');
    }
    $virtualCardAssertGoodsAccess($goodsId);

    $card = Database::fetchOne(
        "SELECT id, status FROM " . Database::prefix() . "goods_virtual_card WHERE id = ? AND goods_id = ?",
        [$id, $goodsId]
    );
    if (!$card) {
        Response::error('卡密不存在');
    }
    if ((int)$card['status'] !== 1) {
        Response::error('仅未售卡密可标记为售出');
    }

    Database::update('goods_virtual_card', [
        'status'  => 2,
        'sold_at' => date('Y-m-d H:i:s'),
    ], $id);

    // 标记售出后同步规格库存
    virtualCardSyncCardStock($goodsId);

    Response::success('已标记为售出', ['csrf_token' => Csrf::token()]);
}

// ================================================================
// 卡密管理弹窗页面（独立弹窗方式访问时使用）
// ================================================================
if ($action === 'card_manager') {
    $goodsId = (int)($_GET['goods_id'] ?? 0);
    if (!$goodsId) {
        exit('商品ID不能为空');
    }
    $virtualCardAssertGoodsAccess($goodsId, true);

    $goods = GoodsModel::getById($goodsId);
    if (!$goods) {
        exit('商品不存在');
    }

    $specs = GoodsModel::getSpecsByGoodsId($goodsId);
    $csrfToken = Csrf::token();
    $pageTitle = '卡密管理';
    if (!isset($popupCardActionBase)) {
        $popupCardActionBase = '/admin/index.php';
    }

    // 构建 spec_id => name 映射
    $specMap = [];
    foreach ($specs as $s) {
        $specMap[(int)$s['id']] = $s['name'];
    }

    // 统计信息
    $totalCards = (int)(Database::fetchOne(
        "SELECT COUNT(*) as cnt FROM " . Database::prefix() . "goods_virtual_card WHERE goods_id = ?",
        [$goodsId]
    )['cnt'] ?? 0);
    $availableCards = (int)(Database::fetchOne(
        "SELECT COUNT(*) as cnt FROM " . Database::prefix() . "goods_virtual_card WHERE goods_id = ? AND status = 1",
        [$goodsId]
    )['cnt'] ?? 0);
    $soldCards = $totalCards - $availableCards;

    // 每个规格的可用卡密数（供库存概览卡片展示）
    $specStockMap = [];
    $specCardRows = Database::query(
        "SELECT spec_id, COUNT(*) as cnt FROM " . Database::prefix() . "goods_virtual_card WHERE goods_id = ? AND status = 1 AND spec_id IS NOT NULL GROUP BY spec_id",
        [$goodsId]
    );
    foreach ($specCardRows as $r) {
        $specStockMap[(int)$r['spec_id']] = (int)$r['cnt'];
    }

    include EM_ROOT . '/admin/view/popup/header.php';
    include __DIR__ . '/inventory.php';
    include EM_ROOT . '/admin/view/popup/footer.php';
    exit;
}

// ================================================================
// 按订单 ID 导出该订单里所有 virtual_card 商品的发货内容为 txt
// URL：/admin/index.php?_action=order_export_cards&order_id=X
// 订单详情 popup 的"导出全部 (TXT)"按钮调用
// ================================================================
if ($action === 'order_export_cards') {
    $orderId = (int) ($_GET['order_id'] ?? 0);
    if ($orderId <= 0) {
        http_response_code(400);
        exit('订单ID不能为空');
    }

    // 订单号、下单时间（文件名用）
    $order = Database::fetchOne(
        "SELECT order_no, created_at FROM " . Database::prefix() . "order WHERE id = ?",
        [$orderId]
    );
    if (!$order) {
        http_response_code(404);
        exit('订单不存在');
    }

    // 用于文件名：尽量取订单里 virtual_card 商品标题
    $rows = Database::query(
        "SELECT id, goods_title, delivery_content
         FROM " . Database::prefix() . "order_goods
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
         FROM " . Database::prefix() . "goods_virtual_card
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
        // 回退：按旧逻辑从订单发货内容展开
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

    // 清空可能的 output buffer，避免前面 PHP 误输出污染文件
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: ' . virtual_card_export_disposition($filename));
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    echo $body;
    exit;
}
