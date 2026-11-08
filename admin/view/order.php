<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
$cs = $currencySymbol ?? '¥';
?>
<style>
    .em-quick-search input{
        width: 420px;
        padding-right: 0;
    }
    /* 站点归属筛选下拉：尺寸对齐右侧的快捷搜索框（32px 高 / 6px 圆角 / 同款聚焦态） */
    .em-toolbar-select{
        flex-shrink: 0;
        width: 112px;
        height: 32px;
        padding: 0 26px 0 10px;
        border: 1px solid #e5e7eb;
        border-radius: 6px;
        color: #1f2937;
        font-size: 13px;
        cursor: pointer;
        appearance: none;
        -webkit-appearance: none;
        background: #fff url("data:image/svg+xml;charset=utf8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M2.5 4.5 6 8l3.5-3.5' fill='none' stroke='%239ca3af' stroke-width='1.4' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") no-repeat right 8px center;
        transition: border-color .15s ease, box-shadow .15s ease;
    }
    .em-toolbar-select:focus{
        border-color: #4f46e5;
        outline: none;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, .1);
    }
</style>
<!-- 订单状态选项卡（每项带图标，和前台"我的订单"状态一致） -->
<div class="em-tabs" id="orderStatusTabs">
    <a class="em-tabs__item is-active" data-status=""          href="javascript:;"><i class="fa fa-th-large"></i><span>全部</span></a>
    <a class="em-tabs__item"           data-status="pending"   href="javascript:;"><i class="fa fa-clock-o"></i><span>待付款</span></a>
    <a class="em-tabs__item"           data-status="paid"      href="javascript:;"><i class="fa fa-cube"></i><span>待发货</span></a>
    <a class="em-tabs__item"           data-status="delivered" href="javascript:;"><i class="fa fa-truck"></i><span>待收货</span></a>
    <a class="em-tabs__item"           data-status="completed" href="javascript:;"><i class="fa fa-check-circle"></i><span>已完成</span></a>
    <a class="em-tabs__item"           data-status="refunded"  href="javascript:;"><i class="fa fa-undo"></i><span>已退款</span></a>
    <a class="em-tabs__item"           data-status="expired"   href="javascript:;"><i class="fa fa-hourglass-end"></i><span>已过期</span></a>
</div>

<div class="admin-page">
    <h1 class="admin-page__title">订单管理</h1>
<table id="orderTable" lay-filter="orderTable"></table>
</div>

<!-- 工具栏：刷新 + 批量删除（未勾选时禁用） -->
<script type="text/html" id="orderToolbarTpl">
    <div class="em-table-toolbar">
        <div class="em-table-toolbar__actions layui-btn-container">
            <a class="em-btn em-reset-btn" id="orderRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
            <a class="em-btn em-red-btn em-disabled-btn" lay-event="batchDelete"><i class="fa fa-trash"></i>批量删除</a>
            <a class="em-btn em-red-btn" lay-event="clearPending"><i class="fa fa-clock-o"></i>清空未支付订单</a>
            <a class="em-btn em-red-btn" lay-event="clearExpired"><i class="fa fa-hourglass-end"></i>清空已过期订单</a>
        </div>
        <!-- 站点归属筛选：主站 / 分站（原生 select，不走 layui form，避免被 form.render 接管） -->
        <select id="orderScopeFilter" class="em-toolbar-select" lay-ignore title="按站点筛选">
            <option value="">全部站点</option>
            <option value="main">主站订单</option>
            <option value="sub">分站订单</option>
        </select>
        <form class="em-quick-search" id="orderQuickSearchForm" autocomplete="off">
            <i class="fa fa-search em-quick-search__ico"></i>
            <input type="search" id="orderQuickSearch" placeholder="订单号 / 商品名 / 昵称 / 账号 / 手机号 / 邮箱 / 游客查单项" enterkeyhint="search">
            <button type="button" class="em-quick-search__clear" id="orderQuickClear" title="清空"><i class="fa fa-times"></i></button>
        </form>
    </div>
</script>

<!-- 订单号（点击即复制）+ 主站/分站小标签（merchant_id 是下单时所在商户的快照，0 = 主站订单） -->
<script type="text/html" id="orderNoTpl">
    <span class="ord-no" lay-event="copyNo" style="font-size:12.5px;" title="点击复制订单号">{{d.order_no}}</span>
    {{# if(d.merchant_id > 0){ }}
    <span class="ord-scope ord-scope--sub">分站</span>
    {{# } else { }}
    <span class="ord-scope ord-scope--main">主站</span>
    {{# } }}
</script>

<!-- 商品：首商品缩略图 + 标题 + 规格×数量；多商品显示 "+N" 小徽章 -->
<script type="text/html" id="orderGoodsTpl">
    {{# if(d.goods_count > 0){ var first = d.goods[0]; }}
    <div style="display:flex;align-items:center;gap:8px;line-height:1.4;text-align:left;">
        <img src="{{ first.cover || '' }}" onerror="this.style.visibility='hidden'"
             style="width:30px;height:30px;border-radius:4px;object-fit:cover;background:#f5f5f5;flex:0 0 30px;">
        <div style="flex:1;min-width:0;overflow:hidden;">
            <div style="font-size:12.5px;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ first.title }}">
                {{ first.title }}
                {{# if(d.goods_count > 1){ }}
                <span class="em-tag em-tag--muted" style="margin-left:4px;font-size:11px;padding:0 5px;">+{{ d.goods_count - 1 }}</span>
                {{# } }}
            </div>
            <div style="font-size:11.5px;color:#9ca3af;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                {{# if(first.spec){ }}{{ first.spec }} · {{# } }}× {{ first.quantity }}
            </div>
        </div>
    </div>
    {{# } else { }}
    <span style="color:#bbb;">-</span>
    {{# } }}
</script>

<!-- 买家：已登录 → 昵称/用户名，未登录 → 游客 tag -->
<script type="text/html" id="orderBuyerTpl">
    {{# if(d.user_id > 0){ }}
    <span>{{d.nickname || d.username}}</span>
    {{# } else { }}
    <span class="em-tag em-tag--muted">游客</span>
    {{# } }}
</script>

<script type="text/html" id="orderAmountTpl">
    <span class="em-tag em-tag--red"><?= $cs ?>{{d.pay_amount_fmt}}</span>
</script>

<script type="text/html" id="orderPaymentTpl">
    {{# if (d.payment_name) { }}
        <span class="ord-pay">
            {{# if (d.payment_image) { }}
                <img src="{{ d.payment_image }}" alt="" class="ord-pay__icon" onerror="this.style.display='none';">
            {{# } else { }}
                <i class="fa fa-credit-card"></i>
            {{# } }}
            {{ d.payment_name }}
        </span>
    {{# } else { }}
        <span class="ord-pay ord-pay--empty">未支付</span>
    {{# } }}
</script>
<style>
.ord-pay {
    display: inline-flex; align-items: center; gap: 5px; justify-content: center;
    padding: 2px 9px; font-size: 12px; font-weight: 500;
    background: #eef2ff; color: #4338ca; border-radius: 10px;
}
.ord-pay__icon { width: 16px; height: 16px; border-radius: 3px; object-fit: contain; background: #fff; }
.ord-pay--empty { background: #f3f4f6; color: #9ca3af; font-weight: 400; }

/* 订单号后的「主站 / 分站」小标签：胶囊形，比 em-tag 更紧凑，避免把订单号列撑开换行 */
.ord-scope {
    display: inline-flex; align-items: center; justify-content: center;
    height: 17px; padding: 0 6px;
    border: 1px solid; border-radius: 3px;
    font-size: 11px; line-height: 1;
    letter-spacing: .3px; white-space: nowrap; vertical-align: middle;
}
/* 主站：蓝色 */
.ord-scope--main { background: #eff6ff; color: #1d4ed8; border-color: #c7ddff; }
/* 分站：青色。状态列已经占用了绿/蓝/琥珀/红/紫，青色是唯一不撞的色系 */
.ord-scope--sub  { background: #ecfeff; color: #0e7490; border-color: #a5f3fc; }

/* 订单号可点击复制：手型 + hover 变蓝，给出可点的暗示 */
.ord-no { cursor: pointer; transition: color .15s ease; }
.ord-no:hover { color: #2563eb; }
</style>

<!-- 状态：用 em-tag 的语义颜色变体代替 layui-badge -->
<script type="text/html" id="orderStatusTpl">
    {{# var map = {
        pending:'em-tag--amber',
        paid:'em-tag--blue',
        delivering:'em-tag--purple',
        delivered:'em-tag--blue',
        completed:'em-tag--on',
        expired:'em-tag--muted',
        cancelled:'em-tag--muted',
        delivery_failed:'em-tag--red',
        refunding:'em-tag--amber',
        refunded:'em-tag--muted',
        failed:'em-tag--red'
    }; }}
    <span class="em-tag {{ map[d.status] || 'em-tag--muted' }}">{{d.status_name}}</span>
</script>

<!-- 时间：日期/时分秒分两行，更易扫读 -->
<script type="text/html" id="orderTimeTpl">
    {{# if(d.created_at){ }}
    {{# var t = d.created_at; }}
    <div style="line-height:1.4;text-align:center;">
        <div style="font-size:12.5px;">{{ t.substring(0,10) }}</div>
        <div style="font-size:11.5px;color:#999;">{{ t.substring(11,19) }}</div>
    </div>
    {{# } else { }}
    <span style="color:#bbb;">-</span>
    {{# } }}
</script>

<!-- 支付时间：同款两行样式；未支付时显示灰色占位 -->
<script type="text/html" id="orderPayTimeTpl">
    {{# if(d.pay_time){ }}
    {{# var t = d.pay_time; }}
    <div style="line-height:1.4;text-align:center;">
        <div style="font-size:12.5px;">{{ t.substring(0,10) }}</div>
        <div style="font-size:11.5px;color:#999;">{{ t.substring(11,19) }}</div>
    </div>
    {{# } else { }}
    <span class="em-tag em-tag--muted">未支付</span>
    {{# } }}
</script>

<!-- 行内操作：详情（蓝）+ 删除（红） -->
<script type="text/html" id="orderActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="detail"><i class="fa fa-eye"></i>详情</a>
        <a class="em-btn em-sm-btn em-red-btn" lay-event="delete"><i class="fa fa-trash"></i>删除</a>
    </div>
</script>

<script>
$(function () {
    // PJAX 防重复绑定：清掉本页历史 .admOrder handler，避免事件成倍触发
    $(document).off('.admOrder');
    $(window).off('.admOrder');

    'use strict';
    var csrfToken = <?= json_encode($csrfToken) ?>;

    layui.use(['layer', 'form', 'table', 'element'], function () {
        var orderQuickSearchCache = '';
        var layer = layui.layer;
        var form = layui.form;
        var table = layui.table;

        form.render('select');

        // 当前筛选状态（由 tab 控制）
        var currentStatus = '';
        // 站点归属筛选：'' 全部 / 'main' 主站 / 'sub' 分站
        var currentScope = '';

        function buildWhere() {
            return {
                _action: 'list',
                keyword: $.trim($('#orderQuickSearch').val() || ''),
                status: currentStatus,
                scope: currentScope
            };
        }
        function doReload() {
            table.reload('orderTableId', { page: {curr: 1}, where: buildWhere() });
        }

        // ============================================================
        // 表格
        // ============================================================
        table.render({
            elem: '#orderTable',
            id: 'orderTableId',
            url: '/admin/order.php',
            method: 'POST',
            where: buildWhere(),
            page: true,
            toolbar: '#orderToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 55px;',
            limit: 10,
            limits: [10, 20, 50, 100],
            cols: [[
                {type: 'checkbox'},
                // 210 = 20 位订单号 + 「主站/分站」小标签的宽度，避免标签被挤到第二行
                {field: 'order_no', title: '订单号', width: 220, templet: '#orderNoTpl'},
                {field: 'goods', title: '商品', minWidth: 240, templet: '#orderGoodsTpl'},
                {field: 'user_id', title: '买家', width: 120, align: 'center', templet: '#orderBuyerTpl'},
                {field: 'pay_amount', title: '金额', width: 110, align: 'center', templet: '#orderAmountTpl'},
                {field: 'payment_name', title: '支付方式', width: 120, align: 'center', templet: '#orderPaymentTpl'},
                {field: 'status', title: '状态', width: 100, align: 'center', templet: '#orderStatusTpl'},
                {field: 'created_at', title: '下单时间', width: 126, align: 'center', templet: '#orderTimeTpl'},
                {field: 'pay_time', title: '支付时间', width: 126, align: 'center', templet: '#orderPayTimeTpl'},
                {title: '操作', width: 170, align: 'center', templet: '#orderActionTpl'}
            ]],
            done: function () {
                $('#orderQuickSearch').val(orderQuickSearchCache);
                // 工具栏模板每次 reload 都会重绘，下拉的选中态要回填
                $('#orderScopeFilter').val(currentScope);
            },
            parseData: function (res) {
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                return {
                    'code': res.code === 200 ? 0 : res.code,
                    'msg': res.msg,
                    'data': res.data ? res.data.data : [],
                    'count': res.data ? res.data.total : 0
                };
            }
        });

        // 勾选 → 切换批量删除按钮启用态
        table.on('checkbox(orderTable)', function () {
            var checked = table.checkStatus('orderTableId').data.length > 0;
            $('[lay-event="batchDelete"]').toggleClass('em-disabled-btn', !checked);
        });

        // ============================================================
        // 状态选项卡：点击切换 currentStatus 并刷新
        // ============================================================
        $(document).on('click.admOrder', '#orderStatusTabs .em-tabs__item', function (e) {
            e.preventDefault();
            $('#orderStatusTabs .em-tabs__item').removeClass('is-active');
            $(this).addClass('is-active');
            currentStatus = $(this).attr('data-status') || '';
            doReload();
        });

        // 复制到剪贴板：用 layui 自带的 layui.lay.clipboard，不再自己造轮子。
        // 它内部同样是「navigator.clipboard 优先、非安全上下文回退 execCommand」，
        // 隐藏 textarea 用的是 position:fixed + opacity:0（比移出视口更稳）。
        // 提示统一用 EmToast（应用商店同款），不用 layer.msg 的默认深色卡。
        function copyText(text, okMsg) {
            var lb = layui.lay && layui.lay.clipboard;
            if (!lb) { EmToast.err('复制组件未就绪，请刷新页面后重试'); return; }
            lb.writeText({
                text: text,
                done: function () { EmToast.ok(okMsg); },
                error: function () { EmToast.err('复制失败，请手动选中订单号复制'); }
            });
        }

        // ============================================================
        // 站点归属筛选：选完立即刷新（和状态 tab 叠加生效）
        // ============================================================
        $(document).on('change.admOrder', '#orderScopeFilter', function () {
            currentScope = $(this).val() || '';
            doReload();
        });

        // ============================================================
        // 快捷搜索：输入实时缓存，回车触发；清空按钮立即刷新
        // ============================================================
        $(document).on('input', '#orderQuickSearch', function () { orderQuickSearchCache = $(this).val(); });
        $(document).on('em:search.admOrder', '#orderQuickSearchForm', function () {
            doReload();
        });
        $(document).on('click.admOrder', '#orderQuickClear', function () {
            orderQuickSearchCache = '';
            $('#orderQuickSearch').val('').focus();
            doReload();
        });

        // 刷新
        $(document).on('click.admOrder', '#orderRefreshBtn', function () {
            table.reload('orderTableId');
        });

        // ============================================================
        // 工具栏事件：批量删除 / 按状态清空
        // ============================================================
        function clearOrdersByStatus(status, label) {
            layer.confirm('确定要清空全部「' + label + '」订单吗？将同时清理关联的发货队列和订单商品，此操作不可恢复。', function (idx) {
                $.ajax({
                    url: '/admin/order.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {csrf_token: csrfToken, _action: 'clear_by_status', status: status},
                    success: function (res) {
                        if (res.code === 200) {
                            if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                            layer.msg(res.msg || '清空成功');
                            table.reload('orderTableId');
                        } else {
                            layer.msg(res.msg || '清空失败');
                        }
                    },
                    error: function () { layer.msg('网络异常'); },
                    complete: function () { layer.close(idx); }
                });
            });
        }

        table.on('toolbar(orderTable)', function (obj) {
            if (obj.event === 'batchDelete') {
                var checked = table.checkStatus('orderTableId').data;
                if (checked.length === 0) { layer.msg('请先勾选订单'); return; }
                var ids = checked.map(function (r) { return r.id; });
                layer.confirm('确定要删除选中的 ' + ids.length + ' 条订单吗？将同时清理关联的发货队列和订单商品，此操作不可恢复。', function (idx) {
                    $.ajax({
                        url: '/admin/order.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {csrf_token: csrfToken, _action: 'batch_delete', ids: ids},
                        success: function (res) {
                            if (res.code === 200) {
                                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                                layer.msg(res.msg || '删除成功');
                                table.reload('orderTableId');
                            } else {
                                layer.msg(res.msg || '删除失败');
                            }
                        },
                        error: function () { layer.msg('网络异常'); },
                        complete: function () { layer.close(idx); }
                    });
                });
            } else if (obj.event === 'clearPending') {
                clearOrdersByStatus('pending', '未支付');
            } else if (obj.event === 'clearExpired') {
                clearOrdersByStatus('expired', '已过期');
            }
        });

        // ============================================================
        // 行内事件：复制订单号 / 详情（iframe 打开 popup）/ 单条删除
        // ============================================================
        table.on('tool(orderTable)', function (obj) {
            if (obj.event === 'copyNo') {
                var no = String(obj.data.order_no || '');
                if (no !== '') copyText(no, '订单号已复制');
            } else if (obj.event === 'detail') {
                showOrderDetail(obj.data);
            } else if (obj.event === 'delete') {
                var data = obj.data;
                layer.confirm('确定要删除订单「' + emEsc(data.order_no) + '」吗？将同时清理关联的发货队列和订单商品，此操作不可恢复。', function (idx) {
                    $.ajax({
                        url: '/admin/order.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {csrf_token: csrfToken, _action: 'delete', id: data.id},
                        success: function (res) {
                            if (res.code === 200) {
                                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                                layer.msg(res.msg || '删除成功');
                                obj.del();
                            } else {
                                layer.msg(res.msg || '删除失败');
                            }
                        },
                        error: function () { layer.msg('网络异常'); },
                        complete: function () { layer.close(idx); }
                    });
                });
            }
        });

        // 订单详情：iframe 打开独立 popup 页
        function showOrderDetail(data) {
            layer.open({
                type: 2,
                title: '订单详情 - ' + emEsc(data.order_no),
                skin: 'admin-modal',
                maxmin: true,
                shadeClose: true,
                area: [window.innerWidth >= 900 ? '780px' : '95%', window.innerHeight >= 700 ? '640px' : '90%'],
                content: '/admin/order.php?_popup=detail&id=' + encodeURIComponent(data.id)
            });
        }
    });
});
</script>
