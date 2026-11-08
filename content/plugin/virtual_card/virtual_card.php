<?php
/**
Plugin Name: 虚拟卡密商品类型
Version: 1.1.7
Plugin URL:
Description: 虚拟商品插件，支持卡密 / 账号 / 邮箱等。既可一键发货（从卡密库自动提取），也可切换为人工发货（管理员手动填写）。
Author: EMSHOP
Author URL:
Category: 商品类型
*/

defined('EM_ROOT') || exit('Access Denied');

// ================================================================
// 辅助函数666：将卡密可用数量同步到对应规格的 stock 字段
// ================================================================

/**
 * 按 spec_id 分组统计可用卡密数量，写入 em_goods_spec.stock，
 * 然后刷新商品的 total_stock 缓存。
 */
function virtualCardSyncCardStock(int $goodsId): void
{
    $prefix = Database::prefix();

    $specs = GoodsModel::getSpecsByGoodsId($goodsId);
    if (empty($specs)) {
        GoodsModel::updatePriceStockCache($goodsId);
        return;
    }

    // 按 spec_id 统计可用卡密数（status=1），忽略 spec_id 为 NULL 的记录
    $counts = Database::query(
        "SELECT spec_id as sid, COUNT(*) as cnt
         FROM {$prefix}goods_virtual_card
         WHERE goods_id = ? AND status = 1 AND spec_id IS NOT NULL
         GROUP BY spec_id",
        [$goodsId]
    );

    $cardMap = [];
    foreach ($counts as $row) {
        $cardMap[(int)$row['sid']] = (int)$row['cnt'];
    }

    // 更新每个规格的 stock = 该规格下可用卡密数
    foreach ($specs as $spec) {
        $specId = (int)$spec['id'];
        $newStock = $cardMap[$specId] ?? 0;
        Database::update('goods_spec', ['stock' => $newStock], $specId);
    }

    GoodsModel::updatePriceStockCache($goodsId);
}

/**
 * 判断当前商品是否自动发货。
 *
 * 规则：
 * - plugin_data 未配置 auto_delivery 时，默认按开启处理（与后台表单默认值一致）
 * - 仅当显式存为 0/'0' 时，视为人工发货
 */
function virtualCardIsAutoDelivery(array $pluginData): bool
{
    if (!array_key_exists('auto_delivery', $pluginData)) {
        return true;
    }
    return (string) $pluginData['auto_delivery'] !== '0';
}

/**
 * 规范化出卡顺序配置值。
 *
 * @param mixed $value
 */
function virtualCardNormalizeIssueOrder($value, bool $allowInherit = false): string
{
    $order = strtolower(trim((string) $value));
    if ($allowInherit && ($order === '' || $order === 'inherit')) {
        return 'inherit';
    }
    $map = [
        'old' => 'old',
        'asc' => 'old',
        'new' => 'new',
        'desc' => 'new',
        'random' => 'random',
        'rand' => 'random',
        'shuffle' => 'random',
    ];
    return $map[$order] ?? ($allowInherit ? 'inherit' : 'old');
}

/**
 * 读取插件全局默认出卡顺序（插件设置页配置）。
 *
 * @param int $merchantId 订单所属商户；0 表示主站。与后台保存插件配置时的 Storage scope 一致。
 */
function virtualCardGetGlobalIssueOrder(int $merchantId = 0): string
{
    try {
        $scope = $merchantId > 0 ? ('merchant_' . $merchantId) : 'main';
        $storage = Storage::getInstanceForScope('virtual_card', $scope);
        return virtualCardNormalizeIssueOrder($storage->getValue('card_issue_order'));
    } catch (Throwable $e) {
        return 'old';
    }
}

/**
 * 解析订单发货实际出卡顺序：商品类型配置优先，其次插件全局配置。
 *
 * @param int $merchantId 订单 em_order.merchant_id，用于读取对应 scope 下的插件全局配置
 */
function virtualCardResolveIssueOrder(array $pluginData, int $merchantId = 0): string
{
    $typeOrder = virtualCardNormalizeIssueOrder($pluginData['card_issue_order'] ?? 'inherit', true);
    if ($typeOrder !== 'inherit') {
        return $typeOrder;
    }
    return virtualCardGetGlobalIssueOrder($merchantId);
}

// ================================================================
// 第一步：注册商品类型
// ================================================================
addAction('goods_type_register', function (&$types) {
    $types['virtual_card'] = [
        'name' => '虚拟卡密',
        'description' => '卡密 / 账号 / 邮箱等虚拟商品，按需切换自动发货或人工发货',
        'icon' => '/content/plugin/virtual_card/icon.png',
        'default' => true,
        'delivery_type' => 'auto', // 默认值，实际按商品 auto_delivery 配置覆盖
    ];
});

// ================================================================
// 注册「发货 worker」—— 本插件自己的常驻进程。
//
// 只领 goods_type = virtual_card 的任务，领到后执行本插件自己的
// goods_type_virtual_card_order_paid 钩子。
//
// 这样做的两个好处：
//   1. **隔离**：这个 worker 里只有本插件的代码。别的插件（比如打上游很慢的
//      ycy_shared）卡住不会拖累卡密发货；本插件更新/启停也只重启这一个进程。
//   2. 更新本插件时，只影响它自己的 worker。
//
// class 用核心提供的通用实现（DeliveryTaskWorker），本插件不用写 worker 类；
// hook 指向下面已有的发货钩子，**发货逻辑一行不用改**。
// ================================================================
addFilter('server_worker_types', function ($types) {
    if (!is_array($types)) {
        $types = [];
    }

    $types[] = [
        'type'   => 'deliver_virtual_card',
        'label'  => '发货（虚拟卡密）',
        'class'  => 'DeliveryTaskWorker',
        'claim'  => ['goods_type' => 'virtual_card'],
        'hook'   => 'goods_type_virtual_card_order_paid',
        'plugin' => 'virtual_card',
    ];

    return $types;
});


// 根据商品实际的 auto_delivery 配置覆盖发货类型
addFilter('goods_delivery_type', function ($deliveryType, $goods) {
    if (($goods['goods_type'] ?? '') !== 'virtual_card') {
        return $deliveryType;
    }
    $pd = json_decode($goods['plugin_data'] ?? '{}', true) ?: [];
    return virtualCardIsAutoDelivery($pd) ? 'auto' : 'manual';
});

// ================================================================
// 第二步：后台创建/编辑表单钩子
// ================================================================
addAction('goods_type_virtual_card_create_form', function ($goods = null) {
    $data = [];
    if ($goods && !empty($goods['plugin_data'])) {
        $data = json_decode($goods['plugin_data'], true) ?: [];
    }

    $goodsId = $goods ? (int)$goods['id'] : 0;
    ?>
    <div class="layui-form-item">
        <label class="layui-form-label">发货内容格式</label>
        <div class="layui-input-block">
            <select name="plugin_data[content_format]" lay-filter="contentFormat">
                <option value="card" <?php echo ($data['content_format'] ?? 'card') === 'card' ? 'selected' : ''; ?>>卡密格式（卡号:密码）</option>
                <option value="card_only" <?php echo ($data['content_format'] ?? '') === 'card_only' ? 'selected' : ''; ?>>仅卡号</option>
                <option value="account" <?php echo ($data['content_format'] ?? '') === 'account' ? 'selected' : ''; ?>>账号:密码格式</option>
                <option value="text" <?php echo ($data['content_format'] ?? '') === 'text' ? 'selected' : ''; ?>>纯文本/链接</option>
            </select>
        </div>
        <div class="layui-form-mid" style="color:#909399;">
            卡密格式：每行一个卡密，支持「卡号:密码」或纯卡号格式<br>
            账号格式：每行一个账号，支持「账号:密码」或纯账号格式
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">自动发货</label>
        <div class="layui-input-block">
            <?php
            // 默认开启：新建时 $data 为空 → 勾上；编辑时按 plugin_data 里显式值
            // 只有已保存过且值为 0/'0'/false 才关闭，其它情况（包括 undefined）都默认开启
            $autoDeliveryOn = array_key_exists('auto_delivery', $data)
                ? (string) $data['auto_delivery'] !== '0'
                : true;
            ?>
            <input type="checkbox" name="plugin_data[auto_delivery]" value="1"
                   lay-skin="switch" lay-text="开启|关闭"
                   <?php echo $autoDeliveryOn ? 'checked' : ''; ?>>
        </div>
        <div class="layui-form-mid" style="color:#909399;">
            开启后，用户付款成功自动从库存中发放卡密/账号
        </div>
    </div>
    <div class="layui-form-item">
        <label class="layui-form-label">出卡顺序</label>
        <div class="layui-input-block">
            <?php $issueOrder = virtualCardNormalizeIssueOrder($data['card_issue_order'] ?? 'inherit', true); ?>
            <select name="plugin_data[card_issue_order]">
                <option value="inherit" <?php echo $issueOrder === 'inherit' ? 'selected' : ''; ?>>继承插件配置（默认）</option>
                <option value="old" <?php echo $issueOrder === 'old' ? 'selected' : ''; ?>>旧卡优先</option>
                <option value="new" <?php echo $issueOrder === 'new' ? 'selected' : ''; ?>>新卡优先</option>
                <option value="random" <?php echo $issueOrder === 'random' ? 'selected' : ''; ?>>随机出卡</option>
            </select>
        </div>
        <div class="layui-form-mid" style="color:#909399;">
            此处优先级高于插件全局配置；选择“继承插件配置”时，使用插件设置中的默认顺序
        </div>
    </div>
    <blockquote class="layui-elem-quote" style="margin:10px 15px;">
        请保存商品后在商品列表操作栏中点击库存按钮进行库存设置
    </blockquote>
    <?php
});


// ================================================================
// 第三步：保存钩子
// ================================================================
addAction('goods_type_virtual_card_save', function ($goodsId, $postData) {
    $pluginData = $postData['plugin_data'] ?? [];

    // 读取旧数据合并（避免覆盖未提交的字段）
    $goods = GoodsModel::getById($goodsId);
    $oldData = [];
    if ($goods && !empty($goods['plugin_data'])) {
        $oldData = json_decode($goods['plugin_data'], true) ?: [];
    }

    $newData = array_merge($oldData, [
        'content_format' => $pluginData['content_format'] ?? 'card',
        'auto_delivery' => !empty($pluginData['auto_delivery']) ? 1 : 0,
        'card_issue_order' => virtualCardNormalizeIssueOrder($pluginData['card_issue_order'] ?? 'inherit', true),
    ]);

    Database::update('goods', [
        'plugin_data' => json_encode($newData, JSON_UNESCAPED_UNICODE),
    ], $goodsId);
});

// ================================================================
// 第四步：前台渲染钩子（商品详情页购买区域）
// ================================================================
addAction('goods_type_virtual_card_render', function ($goods, $spec) {
    $pluginData = $goods && !empty($goods['plugin_data'])
        ? json_decode($goods['plugin_data'], true) ?: []
        : [];

    $format = $pluginData['content_format'] ?? 'card';
    $formatLabels = [
        'card' => '卡密商品',
        'card_only' => '卡密商品',
        'account' => '账号商品',
        'text' => '虚拟商品',
    ];
    ?>
    <style>
    .goods-type-virtual-card { margin: 10px 0; }
    .goods-type-virtual-card .goods-type-badge { display: flex; gap: 8px; flex-wrap: wrap; }
    .goods-type-virtual-card .badge {
        display: inline-flex; align-items: center; gap: 4px;
        padding: 3px 10px; border-radius: 3px; font-size: 12px;
    }
    .goods-type-virtual-card .badge--auto {
        background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff;
    }
    .goods-type-virtual-card .badge--info {
        background: #f6ffed; color: #52c41a; border: 1px solid #b7eb8f;
    }
    .goods-type-virtual-card .goods-type-desc {
        margin-top: 8px; font-size: 13px; color: #666;
    }
    </style>
    <?php $isAuto = virtualCardIsAutoDelivery($pluginData); ?>
    <div class="goods-type-virtual-card">
        <div class="goods-type-badge">
            <span class="badge badge--auto"><i class="fa fa-bolt"></i> <?php echo $formatLabels[$format] ?? '虚拟商品'; ?></span>
            <?php if ($isAuto): ?>
            <span class="badge badge--info"><i class="fa fa-bolt"></i> 自动发货</span>
            <?php else: ?>
            <span class="badge badge--info" style="background:#fff7e6;color:#fa8c16;border-color:#ffd591;"><i class="fa fa-user"></i> 人工发货</span>
            <?php endif; ?>
        </div>
        <div class="goods-type-desc">
            <?php if ($isAuto): ?>
            <i class="fa fa-check-circle" style="color:#52c41a;"></i>
            付款后自动发货，无需等待，查看订单即可获取
            <?php else: ?>
            <i class="fa fa-info-circle" style="color:#fa8c16;"></i>
            付款后由管理员人工审核发货，请留意订单通知
            <?php endif; ?>
        </div>
    </div>
    <?php
});

// ================================================================
// 第五步：订单提交前校验钩子（Filter）
// ================================================================
addFilter('goods_type_virtual_card_order_submit', function ($result, $orderData) {

    $goods = $orderData['goods'] ?? null;
    $spec = $orderData['spec'] ?? null;
    $quantity = (int)($orderData['quantity'] ?? 1);

    if (!$goods || !$spec) {
        return '商品信息不完整';
    }

    $goodsId = (int)$goods['id'];
    $specId = (int)$spec['id'];

    // 读取插件配置，判断是否自动发货
    $pluginData = json_decode($goods['plugin_data'] ?? '{}', true) ?: [];
    $autoDelivery = virtualCardIsAutoDelivery($pluginData);

    if ($autoDelivery) {
        // 自动发货：检查卡密库存
        $specCards = (int)(Database::fetchOne(
            "SELECT COUNT(*) as cnt FROM " . Database::prefix() . "goods_virtual_card WHERE goods_id = ? AND spec_id = ? AND status = 1",
            [$goodsId, $specId]
        )['cnt'] ?? 0);

        if ($specCards < $quantity) {
            return $specCards === 0 ? '该规格暂无库存，请联系客服' : '该规格库存不足，当前仅剩 ' . $specCards . ' 件';
        }
    } else {
        // 人工发货：检查规格表库存
        $stock = (int)($spec['stock'] ?? 0);
        if ($stock >= 0 && $stock < $quantity) {
            return $stock === 0 ? '该规格暂无库存' : '库存不足，当前仅剩 ' . $stock . ' 件';
        }
    }

    return $result;
});

// ================================================================
// 第六步：订单支付成功发货钩子（入队逻辑）
// ================================================================
addAction('goods_type_virtual_card_order_paid', function ($orderId, $orderGoodsId, $pluginData) {
    $prefix = Database::prefix();

    // 幂等短路：这一行已经发过货就直接返回。
    // 队列任务重试、僵尸任务回收都会重跑本钩子，而下面的取卡与写明细都不幂等 ——
    // 少了这道短路，重跑会再消耗一批卡密，并把上一批发给买家的内容冲掉。
    if (OrderModel::hasDeliveryContent((int) $orderGoodsId)) {
        return;
    }

    // 查询订单商品信息
    $og = Database::fetchOne(
        "SELECT id, goods_id, spec_id, quantity FROM {$prefix}order_goods WHERE id = ? LIMIT 1",
        [$orderGoodsId]
    );
    if (!$og) {
        throw new RuntimeException('[virtual_card] 订单商品不存在: #' . $orderGoodsId);
    }

    $goodsId = (int) $og['goods_id'];
    $specId  = (int) $og['spec_id'];
    $qty     = (int) $og['quantity'];

    if ($specId <= 0 || $qty <= 0) {
        throw new RuntimeException('[virtual_card] 规格或数量异常');
    }

    // 读取商品的插件配置，判断是否自动发货（title 供库存不足异常展示商品名）
    $goods = Database::fetchOne("SELECT title, plugin_data FROM {$prefix}goods WHERE id = ? LIMIT 1", [$goodsId]);
    $goodsPluginData = json_decode($goods['plugin_data'] ?? '{}', true) ?: [];
    $autoDelivery = virtualCardIsAutoDelivery($goodsPluginData);

    $orderRow = Database::fetchOne("SELECT merchant_id FROM {$prefix}order WHERE id = ? LIMIT 1", [$orderId]);
    $merchantId = (int) ($orderRow['merchant_id'] ?? 0);
    $issueOrder = virtualCardResolveIssueOrder($goodsPluginData, $merchantId);

    // 整个钩子体放在一个事务里。
    // 队列只有在 runTask 返回 true 时才把任务标记 success，失败会标 retry 并重跑 ——
    // 因此「每个失败的尝试被完整回滚」就是幂等的关键：成功那次不会再被重跑。
    // Database::begin() 已支持嵌套（内层降级为 SAVEPOINT），调用方即使已开事务也安全。
    Database::begin();
    try {
        if ($autoDelivery) {
            // ===== 自动发货：从卡密库取卡密 =====
            $orderBySql = 'id ASC';
            if ($issueOrder === 'new') {
                $orderBySql = 'id DESC';
            } elseif ($issueOrder === 'random') {
                $orderBySql = 'RAND()';
            }
            $cards = Database::query(
                "SELECT id, card_no, card_pwd FROM {$prefix}goods_virtual_card
                 WHERE goods_id = ? AND spec_id = ? AND status = 1
                 ORDER BY {$orderBySql} LIMIT {$qty}",
                [$goodsId, $specId]
            );

            if (count($cards) < $qty) {
                // 用 StockShortageException 而非普通异常：卡密不足是**永久失败**，
                // 让队列直接进终态，不要白白退避重试 max_attempts 轮
                throw new StockShortageException((string) ($goods['title'] ?? ''), count($cards));
            }

            // 标记卡密为已售（分批 UPDATE，避免单次 IN 过多导致 SQL 过大、解析慢、长时间锁行）
            $cardIds = array_column($cards, 'id');
            foreach (array_chunk($cardIds, 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                // AND status = 1 是承重的，三个作用都不可省：
                //   ① 挡住并发领取 —— 否则同一张卡会发给两张订单
                //   ② 挡住把已作废的卡（status=2）复活成已售
                //   ③ 保证 status 一定发生变化，让 affected_rows 的语义稳定
                // 注意：若将来改成「值本来就不变」的 UPDATE，这个断言会失效
                $affected = Database::execute(
                    "UPDATE {$prefix}goods_virtual_card
                        SET status = 0, order_id = ?, order_goods_id = ?, sold_at = NOW()
                      WHERE id IN ({$placeholders}) AND status = 1",
                    array_merge([$orderId, (int) $orderGoodsId], $chunk)
                );
                if ($affected !== count($chunk)) {
                    throw new RuntimeException('[virtual_card] 卡密被并发领取，本次发货已回滚，请重试');
                }
            }

            // 拼接发货内容
            $deliveryLines = [];
            foreach ($cards as $card) {
                $line = $card['card_no'];
                if (!empty($card['card_pwd'])) {
                    $line .= '  密码: ' . $card['card_pwd'];
                }
                $deliveryLines[] = $line;
            }
            $deliveryContent = implode("\n", $deliveryLines);

            // 写入发货内容（仅明细表）
            $stored = OrderModel::persistDeliveryContent((int) $orderGoodsId, $deliveryContent);
            if (!$stored) {
                throw new RuntimeException('[virtual_card] 发货内容写入明细表失败，请先执行升级迁移');
            }
            Database::execute(
                "UPDATE {$prefix}order_goods SET delivery_at = NOW() WHERE id = ?",
                [$orderGoodsId]
            );
        } else {
            // ===== 人工发货：仅扣减规格库存，不写 delivery_content =====
            // delivery_content 由管理员后续手动填写，所以 hasDeliveryContent 短路
            // 对本路径无效，幂等完全依赖「失败即整体回滚」这个事务边界。
            Database::execute(
                "UPDATE {$prefix}goods_spec SET stock = GREATEST(stock - ?, 0) WHERE id = ?",
                [$qty, $specId]
            );
        }

        // 递增规格已售数量（放进事务，与取卡 / 扣库存同生共死）
        GoodsModel::incrementSoldCount($specId, $qty);

        Database::commit();
    } catch (Throwable $e) {
        // 漏掉这里的回滚会让连接带着未结束的事务返回 runTask，
        // 随后它写 delivery_queue 的那条 UPDATE 就会落进这个悬空事务里
        Database::rollBack();
        throw $e;
    }

    // 缓存回写放在事务之外：它们是衍生数据，失败不该把已完成的发货回滚掉
    if ($autoDelivery) {
        // 同步卡密库存到规格的 stock 字段
        virtualCardSyncCardStock($goodsId);
    } else {
        // 与自动发货路径 virtualCardSyncCardStock 一致：刷新 goods 表 total_stock / 价格区间缓存
        GoodsModel::updatePriceStockCache($goodsId);
    }
});

// ================================================================
// 第七步：后台订单详情钩子
// ================================================================
addAction('goods_type_virtual_card_admin_order_detail', function ($orderId) {
    $orderGoods = Database::query(
        "SELECT og.*, g.goods_type FROM " . Database::prefix() . "order_goods og
         LEFT JOIN " . Database::prefix() . "goods g ON og.goods_id = g.id
         WHERE og.order_id = ? AND g.goods_type = 'virtual_card' LIMIT 1",
        [$orderId]
    );

    if (empty($orderGoods)) {
        return;
    }

    $og = $orderGoods[0];
    $deliveryContent = OrderModel::getDeliveryContent((int) ($og['id'] ?? 0), (string) ($og['delivery_content'] ?? ''));
    $pluginDataJson = $og['plugin_data'] ?? '{}';
    $pluginData = json_decode($pluginDataJson, true) ?: [];
    ?>
    <div class="layui-form-item" style="border-top:1px dashed #e2e2e2;padding-top:10px;margin-top:10px;">
        <label class="layui-form-label" style="width:120px;"><i class="fa fa-key"></i> 卡密信息</label>
        <div class="layui-input-inline" style="width:calc(100% - 130px);">
            <?php if (!empty($deliveryContent)): ?>
                <div style="background:#f6ffed;border:1px solid #b7eb8f;border-radius:4px;padding:10px;margin-bottom:8px;">
                    <div style="font-size:12px;color:#52c41a;margin-bottom:6px;">
                        <i class="fa fa-check-circle"></i> 已发货
                    </div>
                    <div style="font-family:Consolas,'Courier New',monospace;word-break:break-all;font-size:13px;line-height:1.8;">
                        <?php echo nl2br(htmlspecialchars($deliveryContent)); ?>
                    </div>
                    <?php if (!empty($og['delivery_at'])): ?>
                    <div style="font-size:11px;color:#999;margin-top:6px;">
                        发货时间：<?php echo $og['delivery_at']; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($pluginData['card_ids'])): ?>
                <div style="font-size:11px;color:#999;">
                    卡密ID：<?php echo htmlspecialchars(implode(', ', $pluginData['card_ids'])); ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div style="background:#fffbe6;border:1px solid #ffe58f;border-radius:4px;padding:10px;">
                    <div style="font-size:12px;color:#faad14;margin-bottom:6px;">
                        <i class="fa fa-clock-o"></i> 待发货
                    </div>
                    <div style="font-size:12px;color:#999;">
                        系统将在用户付款后自动发放卡密
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
});

// ================================================================
// 第八步：订单取消钩子（归还卡密）
// ================================================================
addAction('goods_type_virtual_card_order_cancel', function ($orderId) {
    // 查询该订单关联的卡密ID和商品ID
    $orderGoods = Database::query(
        "SELECT goods_id, plugin_data FROM " . Database::prefix() . "order_goods WHERE order_id = ?",
        [$orderId]
    );

    $affectedGoods = [];
    foreach ($orderGoods as $og) {
        if (empty($og['plugin_data'])) continue;
        $pluginData = json_decode($og['plugin_data'], true) ?: [];
        $cardIds = $pluginData['card_ids'] ?? [];

        if (!empty($cardIds)) {
            $placeholders = implode(',', array_fill(0, count($cardIds), '?'));
            Database::execute(
                "UPDATE " . Database::prefix() . "goods_virtual_card SET status = 1, order_id = NULL WHERE id IN ({$placeholders})",
                $cardIds
            );
            $affectedGoods[] = (int)$og['goods_id'];
        }
    }

    // 卡密恢复可用后，同步规格库存
    foreach (array_unique($affectedGoods) as $gid) {
        virtualCardSyncCardStock($gid);
    }
});

// ================================================================
// 第九步：订单退款钩子（作废卡密）
// ================================================================
addAction('goods_type_virtual_card_order_refund', function ($orderId) {
    // 退款后卡密保持已售状态（status=0）：卡密已交付用户，不可回收
    // 退款金额由订单系统处理，卡密库存不受影响
    if (function_exists('log_message')) {
        log_message('info', "[virtual_card] order_refund: order #{$orderId}, cards remain as sold");
    }
});

// ================================================================
// 第十步：类型切换警告钩子
// ================================================================
addAction('goods_type_virtual_card_switch_warning', function (&$warnings, $goodsId, $oldType, $newType) {
    $warnings[] = [
        'type' => 'warning',
        'message' => '切换离开「虚拟商品」后，该商品关联的卡密库存将保留，但不会自动发放。如需清空库存，请手动处理。',
    ];
});

// ================================================================
// 第十一步：类型切换——从本类型切出时的清理钩子
// ================================================================
addAction('goods_type_virtual_card_switch_from', function ($goodsId) {
    // 仅记录日志，不清理卡密数据（保留以便切回时可恢复）
    if (function_exists('log_message')) {
        log_message('info', "[virtual_card] switched from: goods_id={$goodsId}, cards preserved");
    }
});

// ================================================================
// 第十二步：类型切换——切换到本类型时的初始化钩子
// ================================================================
// 库存管理弹窗中直接渲染卡密库存管理界面（完全接管库存管理）
addAction('goods_type_virtual_card_stock_form', function ($goods, $specs) {
    $goodsId = (int)$goods['id'];
    $csrfToken = Csrf::token();
    $pluginData = !empty($goods['plugin_data']) ? json_decode($goods['plugin_data'], true) ?: [] : [];
    $isAutoDelivery = virtualCardIsAutoDelivery($pluginData);

    if (!$isAutoDelivery) {
        // 非自动发货：与实物商品相同的数量管理
        include __DIR__ . '/stock_form_manual.php';
        return;
    }

    // 自动发货：卡密库存管理
    $totalCards = (int)(Database::fetchOne(
        "SELECT COUNT(*) as cnt FROM " . Database::prefix() . "goods_virtual_card WHERE goods_id = ?",
        [$goodsId]
    )['cnt'] ?? 0);
    $availableCards = (int)(Database::fetchOne(
        "SELECT COUNT(*) as cnt FROM " . Database::prefix() . "goods_virtual_card WHERE goods_id = ? AND status = 1",
        [$goodsId]
    )['cnt'] ?? 0);
    $soldCards = $totalCards - $availableCards;

    // 构建 spec_id => name 映射（供前端显示规格名称）
    $specMap = [];
    foreach ($specs as $s) {
        $specMap[(int)$s['id']] = $s['name'];
    }

    // 每个规格的可用卡密数（供库存概览卡片展示）
    $specStockMap = [];
    $specCardRows = Database::query(
        "SELECT spec_id, COUNT(*) as cnt FROM " . Database::prefix() . "goods_virtual_card WHERE goods_id = ? AND status = 1 AND spec_id IS NOT NULL GROUP BY spec_id",
        [$goodsId]
    );
    foreach ($specCardRows as $r) {
        $specStockMap[(int)$r['spec_id']] = (int)$r['cnt'];
    }

    include __DIR__ . '/inventory.php';
});

// ================================================================
// 物理删除商品时清理卡密库存
// ================================================================
addAction('goods_before_force_delete', function ($goodsId) {
    $prefix = Database::prefix();
    Database::execute("DELETE FROM {$prefix}goods_virtual_card WHERE goods_id = ?", [$goodsId]);
});

// ================================================================
// 后台 AJAX 路由注册（卡密增删查导出，通过 admin_plugin_action 钩子分发）
// order_export_cards：按订单 ID 导出订单里所有 virtual_card 商品的发货内容为 txt
// ================================================================
foreach (['card_list', 'card_deleted_orders', 'card_import', 'card_import_page', 'card_delete', 'card_clear_available', 'card_export', 'card_save', 'card_priority', 'card_mark_sold', 'card_manager', 'order_export_cards'] as $_cardAction) {
    addAction('admin_plugin_action_' . $_cardAction, function () {
        require __DIR__ . '/card_actions.php';
        exit;
    });
}

// ================================================================
// 前台（个人中心 / 游客查单）订单详情 · 卡密发货内容渲染
//   - 每条卡密独立小卡，带单条复制
//   - <=5 条：全部展示 + 一键复制
//   - >5 条：只显示前 5 条（防几万条卡密卡死浏览器）+ 一键复制前 5 + 导出全部 TXT
//   - 页面顶部说明告知用户"浏览器仅展示前 5 条，点击导出查看全部"
// 核心通过 applyFilter('frontend_order_goods_delivery_html', '', $og) 调用
// ================================================================
addFilter('frontend_order_goods_delivery_html', function ($html, $og) {
    if (($og['goods_type'] ?? '') !== 'virtual_card') {
        return $html;
    }
    $content = (string) ($og['delivery_content'] ?? '');
    if ($content === '') {
        return $html;
    }

    // 按行拆，去空行
    $lines = preg_split("/\r\n|\r|\n/", $content);
    $lines = array_values(array_filter($lines, static fn($s) => trim((string) $s) !== ''));
    $total = count($lines);
    $SHOW_LIMIT = 5;
    $truncated = $total > $SHOW_LIMIT;
    $visible = $truncated ? array_slice($lines, 0, $SHOW_LIMIT) : $lines;

    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $orderId = (int) ($og['order_id'] ?? 0);
    $exportUrl = '/?plugin=virtual_card&action=export_order_cards&order_id=' . $orderId;

    // 一键复制的文本：前 5 条用换行拼起来，放 data 属性里
    $copyAllText = implode("\n", $visible);

    ob_start();
    ?>
    <div class="vc-delivery">
        <div class="vc-delivery__head">
            <span class="vc-delivery__title"><i class="fa fa-key"></i> 发货内容</span>
            <span class="vc-delivery__count">共 <?= $total ?> 条<?= $truncated ? '，仅展示前 ' . $SHOW_LIMIT . ' 条' : '' ?></span>
        </div>

        <?php if ($truncated): ?>
        <div class="vc-delivery__info">
            <i class="fa fa-info-circle"></i>
            为避免浏览器卡顿，当前仅展示前 <?= $SHOW_LIMIT ?> 条，完整内容请点击"导出全部"下载。
        </div>
        <?php endif; ?>

        <div class="vc-delivery__list">
            <?php foreach ($visible as $idx => $line): ?>
            <div class="vc-delivery__item">
                <div class="vc-delivery__item-bar">
                    <span class="vc-delivery__idx">#<?= $idx + 1 ?></span>
                    <button type="button" class="vc-delivery__btn vc-delivery__btn--copy"
                            data-vc-copy="<?= $esc($line) ?>" title="复制本条">
                        <i class="fa fa-copy"></i>复制
                    </button>
                </div>
                <pre class="vc-delivery__code"><?= $esc($line) ?></pre>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="vc-delivery__actions">
            <button type="button" class="vc-delivery__btn vc-delivery__btn--primary"
                    data-vc-copy="<?= $esc($copyAllText) ?>" title="复制展示中的 <?= count($visible) ?> 条">
                <i class="fa fa-files-o"></i>一键复制<?= $truncated ? '（前 ' . $SHOW_LIMIT . ' 条）' : '全部' ?>
            </button>
            <?php if ($truncated): ?>
            <a href="<?= $esc($exportUrl) ?>" class="vc-delivery__btn vc-delivery__btn--export" target="_blank">
                <i class="fa fa-download"></i>导出全部（TXT）
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php /* 样式 & 脚本只输出一次，避免同订单多商品时重复注入 */
    if (!defined('VC_DELIVERY_ASSET_PRINTED')) {
        define('VC_DELIVERY_ASSET_PRINTED', true);
    ?>
    <style>
    .vc-delivery {
        margin: 0;
        border: 1px solid #e5e7eb; border-radius: 10px;
        background: #fff; overflow: hidden;
    }
    .vc-delivery__head {
        display: flex; align-items: center; justify-content: space-between; gap: 10px;
        padding: 10px 14px; background: #f9fafb; border-bottom: 1px solid #f0f2f5;
        font-size: 13px; color: #111827;
    }
    .vc-delivery__title { font-weight: 600; }
    .vc-delivery__title .fa { color: #4f46e5; margin-right: 4px; }
    .vc-delivery__count { color: #6b7280; font-size: 12px; white-space: nowrap; }
    .vc-delivery__info {
        padding: 8px 14px; background: #fef3c7; color: #92400e;
        font-size: 12px; line-height: 1.6;
        border-bottom: 1px solid #fde68a;
    }
    .vc-delivery__info .fa { color: #d97706; margin-right: 4px; }
    .vc-delivery__list { padding: 10px 12px; }
    /* 顶栏（序号+复制）+ 下方全宽卡密，手机多行/长串都不会被挤成竖条 */
    .vc-delivery__item {
        padding: 10px 12px;
        margin-bottom: 8px;
        background: #f9fafb;
        border: 1px solid #f0f2f5;
        border-radius: 8px;
    }
    .vc-delivery__item:last-child { margin-bottom: 0; }
    .vc-delivery__item-bar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 8px;
    }
    .vc-delivery__idx {
        color: #9ca3af; font-size: 11px;
        font-family: ui-monospace, Menlo, Consolas, monospace;
    }
    .vc-delivery__code {
        display: block;
        width: 100%;
        max-width: 100%;
        box-sizing: border-box;
        margin: 0;
        font-family: ui-monospace, Menlo, Consolas, monospace;
        font-size: 13px; color: #111827;
        word-break: break-word;
        overflow-wrap: anywhere;
        white-space: pre-wrap;
        line-height: 1.65;
        padding: 10px 12px;
        background: #fff;
        border: 1px solid #eef2f7;
        border-radius: 6px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .vc-delivery__actions {
        display: flex; gap: 8px; flex-wrap: wrap;
        padding: 10px 12px; background: #fafbfc; border-top: 1px solid #f0f2f5;
    }
    .vc-delivery__btn {
        display: inline-flex; align-items: center; justify-content: center; gap: 5px;
        padding: 8px 12px; border-radius: 6px;
        border: 1px solid #d1d5db; background: #fff; color: #374151;
        font-size: 12.5px; cursor: pointer; text-decoration: none;
        transition: all 0.15s ease;
        -webkit-tap-highlight-color: transparent;
    }
    .vc-delivery__btn:hover { background: #f3f4f6; border-color: #9ca3af; }
    .vc-delivery__btn--copy {
        padding: 5px 10px; font-size: 12px; flex-shrink: 0;
    }
    .vc-delivery__btn--primary {
        flex: 1 1 auto;
        min-width: 0;
        background: #4f46e5; color: #fff; border-color: #4f46e5;
    }
    .vc-delivery__btn--primary:hover { background: #4338ca; border-color: #4338ca; color: #fff; }
    .vc-delivery__btn--export {
        flex: 1 1 auto;
        background: #10b981; color: #fff; border-color: #10b981;
    }
    .vc-delivery__btn--export:hover { background: #059669; border-color: #059669; color: #fff; }
    .vc-delivery__btn.is-copied { background: #10b981 !important; color: #fff !important; border-color: #10b981 !important; }

    @media (min-width: 640px) {
        .vc-delivery__list { padding: 10px 14px; }
        .vc-delivery__actions { padding: 10px 14px; }
        .vc-delivery__btn--primary,
        .vc-delivery__btn--export { flex: 0 0 auto; }
    }
    </style>
    <script>
    (function () {
        if (window.__vcDeliveryBound) return;
        window.__vcDeliveryBound = true;

        function writeClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            // 老浏览器回退：临时 textarea + execCommand
            return new Promise(function (resolve, reject) {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.style.position = 'fixed';
                ta.style.left = '-9999px';
                document.body.appendChild(ta);
                ta.select();
                try {
                    var ok = document.execCommand('copy');
                    document.body.removeChild(ta);
                    ok ? resolve() : reject();
                } catch (e) {
                    document.body.removeChild(ta);
                    reject(e);
                }
            });
        }

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-vc-copy]');
            if (!btn) return;
            e.preventDefault();
            var text = btn.getAttribute('data-vc-copy') || '';
            if (!text) return;
            writeClipboard(text).then(function () {
                var originalHtml = btn.innerHTML;
                btn.classList.add('is-copied');
                btn.innerHTML = '<i class="fa fa-check"></i>已复制';
                setTimeout(function () {
                    btn.classList.remove('is-copied');
                    btn.innerHTML = originalHtml;
                }, 1500);
            }).catch(function () {
                alert('复制失败，请手动选择文本复制');
            });
        });
    })();
    </script>
    <?php } ?>
    <?php
    return (string) ob_get_clean();
});

// ================================================================
// 后台手动发货：textarea 填卡密文本
//   - auto_delivery=0 的商品支付后一直等管理员手动填卡密
//   - auto_delivery=1 但自动发货失败的订单（delivery_failed）也能走此处补救
//   - 规格库存已在 order_paid 扣过，这里只写 delivery_content
// ================================================================
addFilter('goods_type_virtual_card_manual_delivery_form', function ($html, $og) {
    ob_start();
    ?>
    <div>
        <label style="display:block;font-size:12px;color:#6b7280;margin-bottom:5px;">
            卡密内容 <span style="color:#94a3b8;">（每行一条，展示给买家）</span>
        </label>
        <textarea name="card_content" required rows="6" maxlength="10000"
                  placeholder="示例：&#10;ABC123  密码: 8888&#10;DEF456  密码: 9999"
                  style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #d1d5db;border-radius:5px;font-family:Menlo,Consolas,monospace;font-size:12.5px;line-height:1.7;resize:vertical;min-height:120px;"></textarea>
    </div>
    <?php
    return (string) ob_get_clean();
});

addFilter('goods_type_virtual_card_manual_delivery_submit', function ($_, $args) {
    $post = $args['post'] ?? [];
    $content = trim((string) ($post['card_content'] ?? ''));
    if ($content === '') {
        return '请填写卡密内容';
    }
    if (mb_strlen($content) > 10000) {
        return '卡密内容过长';
    }
    // 不操作 plugin_data（没有额外字段要存）；规格库存已在 order_paid 扣过
    return [
        'delivery_content' => $content,
        'plugin_data' => null,
    ];
});

// ================================================================
// 友好展示名：订单详情里的 goods_type 徽章用这个转成中文
// 核心通过 applyFilter('goods_type_label', $slug, $og) 调用
// ================================================================
addFilter('goods_type_label', function ($label, $og) {
    if (($og['goods_type'] ?? '') === 'virtual_card') {
        return '虚拟卡密';
    }
    return $label;
});

// ================================================================
// 订单详情 · 卡密发货内容渲染（核心 applyFilter 接管点）
//   - 超过 5 条：只显示前 5 条 + 折叠提示 + "导出全部" 按钮
//   - <= 5 条：完整展示，不需要导出
//   - 非 virtual_card 类型：返回空串让核心走默认渲染
// ================================================================
addFilter('admin_order_goods_delivery_html', function ($html, $og) {
    if (($og['goods_type'] ?? '') !== 'virtual_card') {
        return $html;
    }
    $content = (string) ($og['delivery_content'] ?? '');
    if ($content === '') {
        return $html;
    }

    // 按行切分，去掉空行尾（不影响原字符串）
    $lines = preg_split("/\r\n|\r|\n/", $content);
    $lines = array_values(array_filter($lines, static fn($s) => trim((string) $s) !== ''));
    $total = count($lines);
    $showLimit = 5;
    $truncated = $total > $showLimit;
    $visible = $truncated ? array_slice($lines, 0, $showLimit) : $lines;

    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $orderId = (int) ($og['order_id'] ?? 0);
    // 走 admin/index.php 的插件 action 分发（由 admin_plugin_action_{action} 钩子接管）
    $exportUrl = '/admin/index.php?_action=order_export_cards&order_id=' . $orderId
        . '&csrf_token=' . urlencode(Csrf::token());

    $body = $esc(implode("\n", $visible));

    $out  = '<div class="od-delivery">';
    $out .= '<div class="od-delivery__title">';
    $out .= '发货内容 <span style="color:#94a3b8;font-weight:400;margin-left:4px;">共 ' . $total . ' 条' . ($truncated ? '，仅显示前 ' . $showLimit . ' 条' : '') . '</span>';
    if ($truncated) {
        $out .= '<a href="' . $esc($exportUrl) . '" target="_blank" class="em-btn em-sm-btn em-save-btn" style="float:right;margin-left:8px;">'
              . '<i class="fa fa-download"></i>导出全部 (TXT)</a>';
    }
    $out .= '</div>';
    $out .= '<div class="od-delivery__body">' . $body . '</div>';
    $out .= '</div>';

    return $out;
});

addAction('goods_type_virtual_card_switch_to', function ($goodsId) {
    // 确保 plugin_data 中有默认值
    $goods = GoodsModel::getById($goodsId);
    if ($goods) {
        $data = [];
        if (!empty($goods['plugin_data'])) {
            $data = json_decode($goods['plugin_data'], true) ?: [];
        }
        $defaults = [
            'content_format' => $data['content_format'] ?? 'card',
            'auto_delivery' => $data['auto_delivery'] ?? 1,
            'card_issue_order' => virtualCardNormalizeIssueOrder($data['card_issue_order'] ?? 'inherit', true),
        ];
        Database::update('goods', [
            'plugin_data' => json_encode($defaults, JSON_UNESCAPED_UNICODE),
        ], $goodsId);
    }
});
