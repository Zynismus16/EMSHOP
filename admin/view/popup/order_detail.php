<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$esc = function (?string $str): string {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
};
$cs = $currencySymbol ?? '¥';

// 发货队列状态 → 中文 + 色值
$taskStatusMap = ['pending' => '等待', 'processing' => '处理中', 'success' => '成功', 'failed' => '失败', 'retry' => '重试中'];
$taskColorMap  = ['pending' => '#f59e0b', 'processing' => '#4e6ef2', 'success' => '#10b981', 'failed' => '#ef4444', 'retry' => '#d97706'];

// 订单状态 → em-tag 颜色变体
$statusTagMap = [
    'pending' => 'em-tag--amber', 'paid' => 'em-tag--blue', 'delivering' => 'em-tag--purple',
    'delivered' => 'em-tag--blue', 'completed' => 'em-tag--on',
    'expired' => 'em-tag--muted', 'cancelled' => 'em-tag--muted', 'refunded' => 'em-tag--muted',
    'delivery_failed' => 'em-tag--red', 'refunding' => 'em-tag--amber', 'failed' => 'em-tag--red',
];
$statusVariant = $statusTagMap[$order['status']] ?? 'em-tag--muted';

// 状态左侧装饰色（hero 顶部色带）
$heroAccentMap = [
    'pending' => '#f59e0b', 'paid' => '#3b82f6', 'delivering' => '#8b5cf6', 'delivered' => '#3b82f6',
    'completed' => '#10b981', 'expired' => '#9ca3af', 'cancelled' => '#9ca3af',
    'delivery_failed' => '#ef4444', 'refunding' => '#f59e0b', 'refunded' => '#9ca3af', 'failed' => '#ef4444',
];
$heroAccent = $heroAccentMap[$order['status']] ?? '#9ca3af';

include __DIR__ . '/header.php';
?>

<style>
/* ============================================================
 * 订单详情 popup — 作用域样式
 * 设计基调：白底卡片 + 柔和阴影 + 彩色左装饰线，弱化分割线、强化内容
 *
 * 滚动容器是 .popup-inner（popup.css 里已设 overflow-y:auto），
 * 所以只给它加 padding / 背景；不要设 max-width / margin，否则滚动条
 * 会被夹在居中容器右侧、离开弹窗边缘。
 * ============================================================ */
.popup-body { background: #f5f7fa; }
.popup-body .popup-inner { padding: 20px; }

/* ---------- Hero：订单概览卡 ---------- */
.od-hero {
    background: #fff;
    border-radius: 14px;
    padding: 22px 26px;
    margin-bottom: 16px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04), 0 1px 3px rgba(0, 0, 0, 0.06);
}

.od-hero__row { display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap; }
.od-hero__left { flex: 1; min-width: 0; }
.od-hero__no {
    font-family: Menlo,Consolas,monospace;
    font-size: 14px; font-weight: 600; color: #0f172a;
    letter-spacing: 0.3px;
}
.od-hero__meta {
    display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap;
    font-size: 12.5px; color: #64748b;
}
.od-hero__meta i { color: #94a3b8; }
.od-hero__right { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
.od-hero__amount { text-align: right; line-height: 1; }
.od-hero__amount-label { font-size: 11.5px; color: #94a3b8; letter-spacing: 1px; margin-bottom: 6px; }
.od-ship-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border: 0; border-radius: 6px;
    background: linear-gradient(135deg, #10b981, #059669); color: #fff;
    font-size: 13px; font-weight: 500; cursor: pointer;
    box-shadow: 0 2px 8px rgba(16, 185, 129, 0.2);
    transition: all 0.15s;
}
.od-ship-btn:hover { filter: brightness(1.05); transform: translateY(-1px); }
.od-ship-btn:active { transform: translateY(0); }
.od-paid-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border: 0; border-radius: 6px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff;
    font-size: 13px; font-weight: 500; cursor: pointer;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.22);
    transition: all 0.15s;
}
.od-paid-btn:hover { filter: brightness(1.05); transform: translateY(-1px); }
.od-paid-btn:active { transform: translateY(0); }
.od-paid-btn[disabled] { opacity: 0.6; cursor: not-allowed; transform: none; }
.od-hero__amount-value {
    font-size: 28px; font-weight: 700; color: <?= $heroAccent ?>;
    font-feature-settings: 'tnum';
}
.od-hero__amount-value small { font-size: 15px; font-weight: 500; margin-right: 2px; }

/* Tabs 样式完全复用全局 .em-tabs（含 .em-tabs__count 激活态：白底紫字 + 阴影） */

/* ---------- Panel 通用容器 ---------- */
.od-panel {
    display: none;
    background: #fff; border-radius: 14px; padding: 22px 26px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04), 0 1px 3px rgba(0, 0, 0, 0.06);
    animation: odFade 0.25s ease;
}
.od-panel.is-active { display: block; }
@keyframes odFade {
    from { opacity: 0; transform: translateY(4px); }
    to { opacity: 1; transform: translateY(0); }
}

.od-section-title {
    display: flex; align-items: center; gap: 8px;
    font-size: 13px; font-weight: 600; color: #0f172a;
    padding-bottom: 10px; margin-bottom: 14px;
    border-bottom: 1px solid #f1f5f9;
}
.od-section-title i { color: #6366f1; font-size: 13px; }
.od-section:not(:first-child) { margin-top: 22px; }

/* ---------- KV 网格（订单信息 / 买家信息） ---------- */
.od-kv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 32px; }
.od-kv { display: flex; align-items: baseline; gap: 12px; font-size: 13px; line-height: 1.7; }
.od-kv__label {
    flex: 0 0 78px; color: #94a3b8; font-size: 12px;
    position: relative; top: 1px;
}
.od-kv__value { flex: 1; color: #0f172a; word-break: break-all; min-width: 0; }
.od-kv__value code {
    font-family: Menlo,Consolas,monospace; font-size: 12px;
    background: #f1f5f9; color: #475569;
    padding: 2px 7px; border-radius: 4px;
}
.od-kv__value--money { color: #ef4444; font-weight: 600; }
.od-kv__value--muted { color: #94a3b8; }

/* ---------- 商品卡片 ---------- */
.od-goods-card {
    display: flex; gap: 14px; padding: 14px;
    border: 1px solid #f1f5f9; border-radius: 10px;
    background: #fff; margin-bottom: 10px;
    transition: all 0.18s;
}
.od-goods-card:hover { border-color: #e2e8f0; box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04); }
.od-goods-card:last-of-type { margin-bottom: 0; }
.od-goods-card__cover {
    width: 60px; height: 60px; border-radius: 8px;
    object-fit: cover; background: #f1f5f9; flex: 0 0 60px;
}
.od-goods-card__body { flex: 1; min-width: 0; display: flex; flex-direction: column; justify-content: center; }
.od-goods-card__title { font-size: 13.5px; font-weight: 500; color: #0f172a; line-height: 1.4; margin-bottom: 5px; }
.od-goods-card__meta { font-size: 12px; color: #94a3b8; }
.od-goods-card__price { text-align: right; min-width: 85px; align-self: center; }
.od-goods-card__price-val { font-size: 15px; font-weight: 700; color: #ef4444; font-feature-settings: 'tnum'; }

.od-delivery {
    position: relative;
    background: #f0fdf4;
    border-radius: 10px; padding: 12px 16px 12px 38px;
    margin: -6px 0 12px; font-size: 13px;
}
.od-delivery::before {
    content: '\f00c'; font-family: FontAwesome;
    position: absolute; left: 14px; top: 12px;
    width: 18px; height: 18px; border-radius: 50%;
    background: #10b981; color: #fff; font-size: 10px;
    display: flex; align-items: center; justify-content: center;
}
.od-delivery__title { font-size: 12px; color: #10b981; font-weight: 600; margin-bottom: 4px; }
.od-delivery__body { color: #0f172a; word-break: break-all; white-space: pre-wrap; line-height: 1.6; }

/* ---------- 买家信息 ---------- */
.od-buyer {
    display: flex; align-items: center; gap: 16px;
    padding: 18px; margin-bottom: 16px;
    background: linear-gradient(135deg, #eef2ff 0%, #f5f3ff 100%);
    border-radius: 12px;
}
.od-buyer__avatar {
    width: 64px; height: 64px; border-radius: 50%;
    object-fit: cover; background: #fff;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.15);
    border: 3px solid #fff;
}
.od-buyer__name { font-size: 16px; font-weight: 600; color: #0f172a; margin-bottom: 4px; }
.od-buyer__sub { font-size: 12.5px; color: #64748b; }
.od-buyer__sub code { background: rgba(255, 255, 255, 0.6); padding: 2px 7px; border-radius: 4px; font-size: 11.5px; margin-left: 4px; }

/* ---------- 队列 ---------- */
.od-queue-card {
    border: 1px solid #f1f5f9; border-radius: 10px;
    padding: 12px 16px; margin-bottom: 10px;
    background: #fff;
}
.od-queue-card:last-child { margin-bottom: 0; }
.od-queue-card__head {
    display: flex; align-items: center; gap: 12px;
    font-size: 13px;
}
.od-queue-card__id {
    font-family: Menlo,Consolas,monospace; color: #64748b;
    background: #f1f5f9; padding: 2px 7px; border-radius: 4px; font-size: 11.5px;
}
.od-queue-card__type { color: #0f172a; flex: 1; min-width: 0; }
.od-queue-card__status {
    display: inline-flex; align-items: center; gap: 5px;
    font-weight: 600; font-size: 12px;
}
.od-queue-card__status::before {
    content: ''; width: 8px; height: 8px; border-radius: 50%;
    background: currentColor;
}
.od-queue-card__attempts { color: #94a3b8; font-size: 11.5px; }
.od-queue-card__err {
    margin-top: 8px; padding: 8px 12px;
    background: #fef2f2; border-radius: 6px;
    color: #b91c1c; font-size: 12px; line-height: 1.6;
}

/* ---------- 空状态 ---------- */
.od-empty {
    padding: 60px 20px; text-align: center;
    color: #94a3b8; font-size: 13px;
}
.od-empty i { font-size: 40px; color: #cbd5e1; display: block; margin-bottom: 14px; }

/* ---------- 响应式 ---------- */
@media (max-width: 640px) {
    .popup-body .popup-inner { padding: 12px; }
    .od-hero { padding: 16px 18px; }
    .od-hero__amount-value { font-size: 22px; }
    .od-panel { padding: 16px 18px; }
    .od-kv-grid { grid-template-columns: 1fr; gap: 8px; }
    .em-tabs { flex-wrap: nowrap; overflow-x: auto; }
}
</style>

<?php
// 是否可手动发货：订单状态为 paid/delivering/delivery_failed，且至少一条 order_goods 未发货
$canManualShip = in_array((string) $order['status'], ['paid', 'delivering', 'delivery_failed'], true);
$hasUnshipped = false;
if ($canManualShip) {
    foreach ($orderGoods as $_og) {
        if (empty($_og['delivery_content'])) { $hasUnshipped = true; break; }
    }
}
$showShipBtn = $canManualShip && $hasUnshipped;
$showMarkPaidBtn = in_array((string) $order['status'], ['pending', 'expired'], true);
?>
<div class="popup-inner">
    <!-- ======== Hero：订单概览 ======== -->
    <div class="od-hero">
        <div class="od-hero__row">
            <div class="od-hero__left">
                <div class="od-hero__no"><?= $esc($order['order_no']) ?></div>
                <div class="od-hero__meta">
                    <span class="em-tag <?= $statusVariant ?>"><?= $esc($order['status_name']) ?></span>
                    <span><i class="fa fa-clock-o"></i> <?= $esc(substr((string) ($order['created_at'] ?? ''), 0, 19)) ?></span>
                    <?php if (!empty($order['payment_name'])): ?>
                        <span><i class="fa fa-credit-card"></i> <?= $esc($order['payment_name']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="od-hero__right">
                <div class="od-hero__amount">
                    <div class="od-hero__amount-label">实付金额</div>
                    <div class="od-hero__amount-value"><small><?= $esc($cs) ?></small><?= $esc($order['pay_amount_fmt']) ?></div>
                </div>
                <?php if ($showMarkPaidBtn): ?>
                    <button type="button" class="od-paid-btn" id="odMarkPaidBtn" data-order-id="<?= (int) $order['id'] ?>">
                        <i class="fa fa-check-circle"></i>标记已支付
                    </button>
                <?php endif; ?>
                <?php if ($showShipBtn): ?>
                    <button type="button" class="od-ship-btn" id="odShipBtn" data-order-id="<?= (int) $order['id'] ?>">
                        <i class="fa fa-paper-plane"></i>立即发货
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ======== Tabs（复用全局 em-tabs 样式） ======== -->
    <div class="em-tabs" id="orderDetailTabs">
        <a class="em-tabs__item is-active" data-idx="0" href="javascript:;"><i class="fa fa-file-text-o"></i><span>订单信息</span></a>
        <a class="em-tabs__item" data-idx="1" href="javascript:;"><i class="fa fa-cube"></i><span>商品<?php if ($orderGoods): ?> <span class="em-tabs__count"><?= count($orderGoods) ?></span><?php endif; ?></span></a>
        <a class="em-tabs__item" data-idx="2" href="javascript:;"><i class="fa fa-user"></i><span>买家</span></a>
        <a class="em-tabs__item" data-idx="3" href="javascript:;"><i class="fa fa-tasks"></i><span>发货队列<?php if ($queueTasks): ?> <span class="em-tabs__count"><?= count($queueTasks) ?></span><?php endif; ?></span></a>
    </div>

    <!-- ======== Panel 1: 订单信息 ======== -->
    <div class="od-panel is-active" data-panel="0">
        <!-- 金额明细 -->
        <div class="od-section">
            <div class="od-section-title"><i class="fa fa-yen"></i>金额明细</div>
            <div class="od-kv-grid">
                <div class="od-kv"><span class="od-kv__label">商品金额</span><span class="od-kv__value"><?= $esc($cs) ?><?= $esc($order['goods_amount_fmt']) ?></span></div>
                <div class="od-kv"><span class="od-kv__label">实付金额</span><span class="od-kv__value od-kv__value--money"><?= $esc($cs) ?><?= $esc($order['pay_amount_fmt']) ?></span></div>
                <div class="od-kv"><span class="od-kv__label">支付方式</span><span class="od-kv__value">
                    <?= $esc($order['payment_name'] ?: '-') ?>
                    <?php if (!empty($order['payment_plugin_name'])): ?>
                        <span class="od-kv__value--muted">(<?= $esc($order['payment_plugin_name']) ?>)</span>
                    <?php endif; ?>
                </span></div>
                <div class="od-kv"><span class="od-kv__label">下单 IP</span><span class="od-kv__value"><code><?= $esc($order['ip'] ?: '-') ?></code></span></div>
            </div>
        </div>

        <?php
        // 收货地址快照（实物订单才有）
        $adminShipAddr = null;
        if (!empty($order['shipping_address_snapshot'])) {
            $adminShipAddr = json_decode((string) $order['shipping_address_snapshot'], true);
        }
        ?>
        <?php if (is_array($adminShipAddr) && !empty($adminShipAddr['recipient'])): ?>
        <!-- 收货地址 -->
        <div class="od-section">
            <div class="od-section-title"><i class="fa fa-map-marker"></i>收货地址</div>
            <div class="od-kv-grid">
                <div class="od-kv"><span class="od-kv__label">收件人</span><span class="od-kv__value"><?= $esc((string) $adminShipAddr['recipient']) ?></span></div>
                <div class="od-kv"><span class="od-kv__label">手机</span><span class="od-kv__value"><?= $esc((string) ($adminShipAddr['mobile'] ?? '')) ?></span></div>
                <div class="od-kv" style="grid-column: 1 / -1;">
                    <span class="od-kv__label">地址</span>
                    <span class="od-kv__value">
                        <?= $esc(trim(($adminShipAddr['province'] ?? '') . ' ' . ($adminShipAddr['city'] ?? '') . ' ' . ($adminShipAddr['district'] ?? ''))) ?>
                        <?= $esc((string) ($adminShipAddr['detail'] ?? '')) ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- 时间信息 -->
        <div class="od-section">
            <div class="od-section-title"><i class="fa fa-clock-o"></i>时间信息</div>
            <div class="od-kv-grid">
                <div class="od-kv"><span class="od-kv__label">下单时间</span><span class="od-kv__value"><?= $esc($order['created_at'] ?: '-') ?></span></div>
                <div class="od-kv"><span class="od-kv__label">支付时间</span><span class="od-kv__value<?= empty($order['pay_time']) ? ' od-kv__value--muted' : '' ?>"><?= !empty($order['pay_time']) ? $esc($order['pay_time']) : '未支付' ?></span></div>
                <div class="od-kv"><span class="od-kv__label">发货时间</span><span class="od-kv__value<?= empty($order['delivery_time']) ? ' od-kv__value--muted' : '' ?>"><?= !empty($order['delivery_time']) ? $esc($order['delivery_time']) : '未发货' ?></span></div>
                <div class="od-kv"><span class="od-kv__label">完成时间</span><span class="od-kv__value<?= empty($order['complete_time']) ? ' od-kv__value--muted' : '' ?>"><?= !empty($order['complete_time']) ? $esc($order['complete_time']) : '未完成' ?></span></div>
            </div>
        </div>
        <?php
        $buyerFields = OrderModel::parseBuyerContactFields($order);
        $adminGuestContact = $buyerFields['guest_contact'];
        $adminExtraPairs = $buyerFields['extra_pairs'];
        $adminOrderPassword = $buyerFields['order_password'];
        ?>
        <?php if ($adminGuestContact !== '' || $adminOrderPassword !== '' || $adminExtraPairs !== []): ?>
        <div class="od-section">
            <div class="od-section-title"><i class="fa fa-address-card-o"></i>买家填写信息</div>
            <div class="od-kv-grid">
                <?php if ($adminGuestContact !== ''): ?>
                <div class="od-kv"><span class="od-kv__label">游客联系方式</span><span class="od-kv__value"><?= $esc($adminGuestContact) ?></span></div>
                <?php endif; ?>
                <?php if ($adminOrderPassword !== ''): ?>
                <div class="od-kv"><span class="od-kv__label">订单密码</span><span class="od-kv__value"><code><?= $esc($adminOrderPassword) ?></code></span></div>
                <?php endif; ?>
                <?php foreach ($adminExtraPairs as $efKey => $efVal): ?>
                <div class="od-kv"><span class="od-kv__label"><?= $esc((string) $efKey) ?></span><span class="od-kv__value"><?= $esc((string) $efVal) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ======== Panel 2: 商品信息 ======== -->
    <div class="od-panel" data-panel="1">
        <?php if (empty($orderGoods)): ?>
            <div class="od-empty"><i class="fa fa-inbox"></i>暂无商品数据</div>
        <?php else: ?>
            <?php foreach ($orderGoods as $g):
                // 商品类型友好名：插件通过 addFilter('goods_type_label', fn, 10, 2) 返回中文名
                // 没插件接管时回退到 slug，至少不是空白
                $typeLabel = !empty($g['goods_type']) ? applyFilter('goods_type_label', $g['goods_type'], $g) : '';
            ?>
            <div class="od-goods-card">
                <img class="od-goods-card__cover" src="<?= $esc($g['cover_image'] ?? '') ?>" onerror="this.style.visibility='hidden'">
                <div class="od-goods-card__body">
                    <div class="od-goods-card__title"><?= $esc($g['goods_title']) ?></div>
                    <div class="od-goods-card__meta">
                        <?php if (!empty($g['spec_name'])): ?><?= $esc($g['spec_name']) ?> · <?php endif; ?>× <?= (int) $g['quantity'] ?>
                        <?php if ($typeLabel !== ''): ?>
                            <span class="em-tag em-tag--muted" style="margin-left:6px;font-size:11px;"><?= $esc((string) $typeLabel) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="od-goods-card__price">
                    <div class="od-goods-card__price-val"><?= $esc($cs) ?><?= $esc($g['price_fmt']) ?></div>
                </div>
            </div>
            <?php
            // 发货内容渲染交给插件：卡密要截断+导出按钮、普通商品保持纯文本
            // 插件通过 addFilter('admin_order_goods_delivery_html', fn, 10, 2) 接管 HTML
            // 返回空字符串表示不接管 → 核心走默认 pre-wrap 渲染
            $pluginDeliveryHtml = (string) applyFilter('admin_order_goods_delivery_html', '', $g);
            ?>
            <?php if ($pluginDeliveryHtml !== ''): ?>
                <?= $pluginDeliveryHtml /* 插件已自行 escape，核心直接输出 */ ?>
            <?php elseif (!empty($g['delivery_content'])): ?>
            <div class="od-delivery">
                <div class="od-delivery__title">发货内容</div>
                <div class="od-delivery__body"><?= $esc($g['delivery_content']) ?></div>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ======== Panel 3: 买家信息 ======== -->
    <div class="od-panel" data-panel="2">
        <?php if ($buyer): ?>
            <?php
            $avatar = !empty($buyer['avatar']) ? $buyer['avatar'] : (EM_CONFIG['placeholder_img'] ?? '');
            $buyerMoney = number_format((float) bcdiv((string) ($buyer['money'] ?? 0), '1000000', 2), 2, '.', ',');
            ?>
            <div class="od-buyer">
                <img class="od-buyer__avatar" src="<?= $esc($avatar) ?>" onerror="this.style.visibility='hidden'">
                <div>
                    <div class="od-buyer__name"><?= $esc($buyer['nickname'] ?: $buyer['username']) ?></div>
                    <div class="od-buyer__sub">ID <code><?= (int) $buyer['id'] ?></code> · @<?= $esc($buyer['username']) ?></div>
                </div>
            </div>
            <div class="od-kv-grid">
                <div class="od-kv"><span class="od-kv__label">邮箱</span><span class="od-kv__value"><?= $esc($buyer['email'] ?: '-') ?></span></div>
                <div class="od-kv"><span class="od-kv__label">手机</span><span class="od-kv__value"><?= $esc($buyer['mobile'] ?? '') ?: '-' ?></span></div>
                <div class="od-kv"><span class="od-kv__label">账户余额</span><span class="od-kv__value od-kv__value--money"><?= $esc($cs) ?><?= $esc($buyerMoney) ?></span></div>
                <div class="od-kv"><span class="od-kv__label">注册时间</span><span class="od-kv__value"><?= $esc($buyer['created_at'] ?? '') ?: '-' ?></span></div>
            </div>
        <?php else: ?>
            <div class="od-empty"><i class="fa fa-user-o"></i>此订单由游客下单，无登录用户信息</div>
        <?php endif; ?>
    </div>

    <!-- ======== Panel 4: 发货队列 ======== -->
    <div class="od-panel" data-panel="3">
        <?php if (empty($queueTasks)): ?>
            <div class="od-empty"><i class="fa fa-inbox"></i>暂无发货队列记录</div>
        <?php else: ?>
            <?php foreach ($queueTasks as $tk):
                $tName  = $taskStatusMap[$tk['status']] ?? $tk['status'];
                $tColor = $taskColorMap[$tk['status']]  ?? '#94a3b8';
                // 同样走 goods_type_label filter 转成插件提供的友好名
                $tTypeLabel = !empty($tk['goods_type'])
                    ? applyFilter('goods_type_label', $tk['goods_type'], $tk)
                    : '-';
            ?>
            <div class="od-queue-card">
                <div class="od-queue-card__head">
                    <span class="od-queue-card__id">#<?= (int) $tk['id'] ?></span>
                    <span class="od-queue-card__type"><?= $esc((string) $tTypeLabel) ?></span>
                    <span class="od-queue-card__status" style="color:<?= $tColor ?>;"><?= $esc($tName) ?></span>
                    <span class="od-queue-card__attempts"><?= (int) $tk['attempts'] ?> / <?= (int) $tk['max_attempts'] ?> 次</span>
                </div>
                <?php if (!empty($tk['last_error'])): ?>
                    <div class="od-queue-card__err"><?= $esc($tk['last_error']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
$(function () {
    var csrfToken = <?= json_encode($csrfToken ?? Csrf::token()) ?>;

    // Tab 切换：data-idx ↔ data-panel 匹配联动，切换时面板淡入
    $('#orderDetailTabs').on('click', '.em-tabs__item', function (e) {
        e.preventDefault();
        var idx = $(this).attr('data-idx');
        $(this).addClass('is-active').siblings().removeClass('is-active');
        $('.od-panel').removeClass('is-active')
            .filter('[data-panel="' + idx + '"]').addClass('is-active');
    });

    // 手工标记已支付：待付款 / 已过期订单显示按钮
    $('#odMarkPaidBtn').on('click', function () {
        var $btn = $(this);
        var orderId = $btn.data('order-id');
        if (!orderId) return;

        layer.confirm('确认将该订单手工标记为已支付吗？系统会立即触发发货流程。', function (idx) {
            layer.close(idx);
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 处理中...');

            $.ajax({
                url: '/admin/order.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    _action: 'mark_paid',
                    id: orderId,
                    csrf_token: csrfToken
                },
                success: function (res) {
                    if (res.code === 200) {
                        if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                        layer.msg(res.msg || '订单已标记为已支付');
                        try {
                            if (window.parent && window.parent.layui && window.parent.layui.table) {
                                window.parent.layui.table.reload('orderTableId');
                            }
                        } catch (e) {}
                        setTimeout(function () { location.reload(); }, 300);
                    } else {
                        layer.msg(res.msg || '操作失败');
                        $btn.prop('disabled', false).html('<i class="fa fa-check-circle"></i> 标记已支付');
                    }
                },
                error: function () {
                    layer.msg('网络异常');
                    $btn.prop('disabled', false).html('<i class="fa fa-check-circle"></i> 标记已支付');
                }
            });
        });
    });

    // 立即发货：嵌套 iframe 打开发货页；关闭后如有成功发货，通知外层订单列表刷新
    $('#odShipBtn').on('click', function () {
        var orderId = $(this).data('order-id');
        // 当前 popup 本身是 iframe，再开一层 iframe（parent.layer）叠在同一个父窗口
        var rootLayer = (window.parent && window.parent.layer) ? window.parent.layer : layer;
        var iframeWin = null;
        rootLayer.open({
            type: 2,
            title: '发货 #' + emEsc(<?= json_encode($order['order_no']) ?>),
            skin: 'admin-modal',
            shadeClose: false,
            area: [window.parent.innerWidth >= 720 ? '680px' : '95%', window.parent.innerHeight >= 600 ? '560px' : '90%'],
            content: '/admin/order.php?_popup=ship&id=' + encodeURIComponent(orderId),
            end: function () {
                // 尝试从发货 iframe 读 _orderShipSuccess 标记；拿不到就放过
                // 成功时刷新外层订单详情 popup（即当前 iframe）
                try {
                    if (iframeWin && iframeWin._orderShipSuccess) {
                        location.reload();
                    }
                } catch (e) {}
            },
            success: function (layero) {
                iframeWin = layero.find('iframe')[0] && layero.find('iframe')[0].contentWindow;
            }
        });
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
