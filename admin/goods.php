<?php
declare(strict_types=1);

require __DIR__ . '/global.php';

// 后台登录校验
adminRequireLogin();

$action = $_GET['_action'] ?? $_POST['_action'] ?? '';

// 非 list 操作统一验证 CSRF
if ($action !== 'list' && $action !== '') {
    $csrf = (string)(($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? ''));
    if (!Csrf::validate($csrf)) {
        Response::error('请求已失效，请刷新页面后重试');
    }
}

// 列表接口（供 layui table 使用，layui table 要求 code=0）
if ($action === 'list') {
    $page = (int)($_POST['page'] ?? 1);
    $limit = (int)($_POST['limit'] ?? 20);
    $keyword = trim($_POST['keyword'] ?? '');
    $category_id = (int)($_POST['category_id'] ?? 0);
    $is_on_sale = isset($_POST['is_on_sale']) && $_POST['is_on_sale'] !== '' ? (int)$_POST['is_on_sale'] : null;
    $goods_type = trim($_POST['goods_type'] ?? '');
    $is_recommended = isset($_POST['is_recommended']) && $_POST['is_recommended'] !== '' ? (int)$_POST['is_recommended'] : null;
    $status = isset($_POST['status']) && $_POST['status'] !== '' ? (int)$_POST['status'] : null;

    $where = [];
    if ($keyword) $where['keyword'] = $keyword;
    if ($category_id) $where['category_id'] = $category_id;
    if ($is_on_sale !== null) $where['is_on_sale'] = $is_on_sale;
    if ($goods_type) $where['goods_type'] = $goods_type;
    if ($is_recommended !== null) $where['is_recommended'] = $is_recommended;
    // 默认只显示正常状态的商品（status=1），排除已删除的（status=0）
    $where['status'] = $status !== null ? $status : 1;

    // 仅显示当前管理员所属 owner_id（主站管理员可看所有，这里简化）
    $where['owner_id'] = 0;

    $result = GoodsModel::getList($where, $page, $limit, 'sort ASC, id DESC');

    // 获取类型注册信息中的默认发货类型
    $registeredTypes = GoodsTypeManager::getTypes();

    // 让插件根据单个商品的 plugin_data 决定实际发货类型；
    // 顺便剔除列表用不到的 content / guide 字段（富文本正文可能很大，传过去白白占带宽）
    foreach ($result['list'] as &$row) {
        $defaultDt = $registeredTypes[$row['goods_type']]['delivery_type'] ?? 'manual';
        $row['delivery_type'] = applyFilter('goods_delivery_type', $defaultDt, $row);
        unset($row['content'], $row['guide']);
    }
    unset($row);

    // 统计各上架状态的商品数量（排除已删除，与列表条件一致）
    $countWhere = ['owner_id' => 0, 'status' => $where['status']];
    if ($keyword) $countWhere['keyword'] = $keyword;
    if ($category_id) $countWhere['category_id'] = $category_id;
    if ($goods_type) $countWhere['goods_type'] = $goods_type;
    if ($is_recommended !== null) $countWhere['is_recommended'] = $is_recommended;

    $countWhereAll = $countWhere;
    $countWhereOn = array_merge($countWhere, ['is_on_sale' => 1]);
    $countWhereOff = array_merge($countWhere, ['is_on_sale' => 0]);

    $tabCounts = [
        'all' => GoodsModel::getList($countWhereAll, 1, 1)['total'],
        'on_sale' => GoodsModel::getList($countWhereOn, 1, 1)['total'],
        'off_sale' => GoodsModel::getList($countWhereOff, 1, 1)['total'],
    ];

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'code' => 0,
        'msg' => '',
        'count' => $result['total'],
        'data' => $result['list'],
        'csrf_token' => Csrf::token(),
        'tab_counts' => $tabCounts
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 切换上架状态（走 GoodsModel 以触发钩子和变更日志）
if ($action === 'toggle_sale') {
    $id = (int)($_POST['id'] ?? 0);
    $goods = GoodsModel::getById($id);
    if (!$goods) {
        Response::error('商品不存在');
    }
    $newStatus = $goods['is_on_sale'] ? 0 : 1;
    $result = GoodsModel::update($id, ['is_on_sale' => $newStatus]);
    if ($result) {
        Response::success('状态已更新', ['csrf_token' => Csrf::token()]);
    } else {
        Response::error('更新失败');
    }
}

// 切换推荐状态
if ($action === 'toggle_recommend') {
    $id = (int)($_POST['id'] ?? 0);
    $goods = GoodsModel::getById($id);
    if (!$goods) {
        Response::error('商品不存在');
    }
    $newStatus = $goods['is_recommended'] ? 0 : 1;
    $result = GoodsModel::update($id, ['is_recommended' => $newStatus]);
    if ($result) {
        Response::success('状态已更新', ['csrf_token' => Csrf::token()]);
    } else {
        Response::error('更新失败');
    }
}

// 删除商品（逻辑删除）
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    $result = GoodsModel::delete($id);
    if ($result) {
        // 自动同步标签计数（软删除后商品被排除，goods_count 需要重算）
        GoodsTagModel::refreshAllCounts();
        Response::success('删除成功', ['csrf_token' => Csrf::token()]);
    } else {
        Response::error('删除失败');
    }
}

// 物理删除商品
if ($action === 'physical_delete') {
    $id = (int)($_POST['id'] ?? 0);
    $result = GoodsModel::forceDelete($id);
    if ($result) {
        GoodsTagModel::refreshAllCounts();
        Response::success('删除成功', ['csrf_token' => Csrf::token()]);
    } else {
        Response::error('删除失败');
    }
}

// 批量操作
if ($action === 'batch') {
    $batchAction = $_POST['batch_action'] ?? '';
    // jQuery AJAX 发送数组时会转为 ids[]=1&ids[]=2，故需兼容数组和 JSON 字符串两种格式
    if (isset($_POST['ids'])) {
        if (is_array($_POST['ids'])) {
            $ids = array_map('intval', $_POST['ids']);
        } else {
            $decoded = json_decode((string) $_POST['ids'], true);
            $ids = is_array($decoded) ? array_map('intval', $decoded) : [];
        }
    } else {
        $ids = [];
    }
    // JSON 解出来的值也可能是字符串：统一转正整数，杜绝把整段字符串当 WHERE
    $ids = array_values(array_filter(array_map('intval', (array) $ids), static fn($v) => $v > 0));
    if (empty($ids)) {
        Response::error('请选择商品');
    }
    // Database::update 返回 affected_rows，值未变化时为 0 但并非失败，
    // 因此用 try/catch 捕获真正的异常，affected_rows=0 视为成功。
    $failed = 0;
    foreach ($ids as $id) {
        try {
            if ($batchAction === 'on_sale') {
                GoodsModel::update($id, ['is_on_sale' => 1]);
            } elseif ($batchAction === 'off_sale') {
                GoodsModel::update($id, ['is_on_sale' => 0]);
            } elseif ($batchAction === 'delete') {
                GoodsModel::delete($id);
            } elseif ($batchAction === 'physical_delete') {
                GoodsModel::forceDelete($id);
            } elseif ($batchAction === 'recommend') {
                GoodsModel::setRecommended($id, 1);
            } elseif ($batchAction === 'unrecommend') {
                GoodsModel::setRecommended($id, 0);
            } elseif ($batchAction === 'top_category') {
                GoodsModel::setTopCategory($id, 1);
            } elseif ($batchAction === 'untop_category') {
                GoodsModel::setTopCategory($id, 0);
            } else {
                $failed++;
                break;
            }
        } catch (\Throwable $e) {
            $failed++;
        }
    }
    // 涉及删除类操作时同步刷新标签计数（排除已删商品的 goods_count 要重算）
    if (in_array($batchAction, ['delete', 'physical_delete'], true)) {
        GoodsTagModel::refreshAllCounts();
    }
    if ($failed === 0) {
        Response::success('操作成功', ['csrf_token' => Csrf::token()]);
    } else {
        Response::error('操作失败');
    }
}

// 克隆商品
if ($action === 'clone') {
    $id = (int)($_POST['id'] ?? 0);
    $newId = GoodsModel::clone($id);
    if ($newId) {
        // 克隆会复制 tag 关联 → goods_count 增加，自动刷新一次
        GoodsTagModel::refreshAllCounts();
        Response::success('克隆成功', ['csrf_token' => Csrf::token()]);
    } else {
        Response::error('克隆失败');
    }
}

// 默认：显示列表页面
$categories = Database::query("SELECT * FROM " . Database::prefix() . "goods_category WHERE status = 1 ORDER BY parent_id ASC, sort ASC");
$goodsTypes = GoodsTypeManager::getTypes();

if (Request::isPjax()) {
    // Pjax 局部加载：返回带 #adminContent 容器的片段
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/goods.php';
    echo '</div>';
} else {
    // 直接访问：加载完整后台框架
    $adminContentView = __DIR__ . '/view/goods.php';
    require __DIR__ . '/index.php';
}
