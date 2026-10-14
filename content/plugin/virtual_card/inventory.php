<?php
/**
 * 虚拟商品（自动发货）— 卡密库存管理界面
 *
 * 由 goods_type_virtual_card_stock_form 钩子加载（auto_delivery 模式）。
 * 也由 card_manager action 在独立弹窗中加载。
 *
 * 可用变量：
 *   $goods, $specs, $goodsId, $csrfToken
 *   $totalCards, $availableCards, $soldCards
 *   $specMap       — spec_id => name
 *   $specStockMap  — spec_id => available count
 */
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
?>

<style>
/* ===== 规格库存概览：卡片栅格 ===== */
.va-spec-stats {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
    gap: 10px;
}
.va-spec-item {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    padding: 12px 8px;
    text-align: center;
    transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
}
.va-spec-item:hover {
    border-color: #a5b4fc;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.1);
    transform: translateY(-1px);
}
.va-spec-item__num {
    font-size: 22px;
    font-weight: 700;
    color: #059669;
    line-height: 1.2;
    font-family: Menlo, Consolas, monospace;
}
.va-spec-item__num.is-zero { color: #dc2626; }
.va-spec-item__label {
    font-size: 12px;
    color: #6b7280;
    margin-top: 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

/* ===== 卡号单元格（等宽字体 + 图标） ===== */
.card-no-cell {
    font-family: Menlo, Consolas, monospace;
    font-size: 12.5px;
    color: #374151;
}

/* ===== 表格区：去掉 popup-section 内侧边距，让表格贴边 ===== */
.va-table-wrap {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    overflow: hidden;
}

/* ===== 时间列（日期加粗 + 时间浅色等宽，和用户/日志列表一致） ===== */
.va-time {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    line-height: 1.3;
}
.va-time__date { color: #374151; font-weight: 500; font-size: 12.5px; }
.va-time__clock { color: #9ca3af; font-size: 11.5px; font-family: Menlo, Consolas, monospace; }

/* ===== 操作栏（独立于 layui table toolbar，小屏横滚） ===== */
.va-toolbar {
    margin-bottom: 10px;
}
.va-toolbar__actions {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 8px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: thin;
}
.va-toolbar__actions .em-btn {
    flex-shrink: 0;
    margin-right: 0;
    margin-bottom: 0;
}
</style>

<div class="popup-inner">

    <!-- 规格库存概览 -->
    <div class="popup-section">
        <div class="va-spec-stats" id="specStockStats">
            <?php foreach ($specs as $spec):
                $sid   = (int)$spec['id'];
                $avail = $specStockMap[$sid] ?? 0;
            ?>
            <div class="va-spec-item" data-spec-id="<?= $sid ?>">
                <div class="va-spec-item__num<?= $avail === 0 ? ' is-zero' : '' ?>"><?= $avail ?></div>
                <div class="va-spec-item__label" title="<?= htmlspecialchars($spec['name'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($spec['name'], ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 搜索条件（em-filter 风格，两个字段 → 保留可折叠面板） -->
    <div class="em-filter" id="cardFilter">
        <div class="em-filter__head" id="cardFilterHead">
            <span class="em-filter__title"><i class="fa fa-filter"></i>搜索条件</span>
            <span class="em-filter__toggle"><i class="fa fa-angle-down"></i><span class="em-filter__toggle-text">展开</span></span>
        </div>
        <div class="em-filter__body">
            <div class="em-filter__grid">
                <div class="em-filter__field">
                    <label>卡号 / 备注</label>
                    <input type="text" id="cardSearchKeyword" placeholder="搜索卡号或备注" autocomplete="off">
                </div>
                <div class="em-filter__field">
                    <label>所属规格</label>
                    <select id="cardSearchSpec">
                        <option value="">全部规格</option>
                        <?php foreach ($specs as $spec): ?>
                        <option value="<?= (int) $spec['id'] ?>"><?= htmlspecialchars($spec['name'], ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="em-filter__field">
                    <label>订单状态</label>
                    <select id="cardSearchOrderState">
                        <option value="">全部订单</option>
                        <option value="exists">订单未删除</option>
                        <option value="missing">订单已删除</option>
                    </select>
                </div>
            </div>
            <div class="em-filter__actions">
                <button type="button" class="em-btn em-reset-btn" id="cardResetBtn"><i class="fa fa-undo mr-5"></i>重置</button>
                <button type="button" class="em-btn em-save-btn" id="cardSearchBtn"><i class="fa fa-search mr-5"></i>搜索</button>
            </div>
        </div>
    </div>

    <!-- 状态选项卡（em-tabs 同款，带动态计数） -->
    <div class="em-tabs" id="cardTabs">
        <a class="em-tabs__item" data-status=""><i class="fa fa-list"></i>全部<em class="em-tabs__count" id="tabAll"></em></a>
        <a class="em-tabs__item is-active" data-status="1"><i class="fa fa-cube"></i>未售<em class="em-tabs__count" id="tabAvailable"></em></a>
        <a class="em-tabs__item" data-status="0"><i class="fa fa-check-circle"></i>已售<em class="em-tabs__count" id="tabSold"></em></a>
        <a class="em-tabs__item" data-status="2"><i class="fa fa-flag"></i>标记售出<em class="em-tabs__count" id="tabMarked"></em></a>
    </div>

    <!-- 操作栏（自定义布局，不用 layui table toolbar） -->
    <div class="va-toolbar" id="cardToolbar">
        <div class="va-toolbar__actions">
            <button type="button" class="em-btn em-reset-btn" id="cardRefreshBtn"><i class="fa fa-refresh"></i>刷新</button>
            <button type="button" class="em-btn em-save-btn" id="cardImportBtn"><i class="fa fa-upload"></i>导入卡密</button>
            <button type="button" class="em-btn em-save-btn" id="cardExportBtn"><i class="fa fa-download"></i>导出卡密</button>
            <button type="button" class="em-btn em-red-btn em-disabled-btn" id="cardBatchDeleteBtn"><i class="fa fa-trash"></i>批量删除</button>
            <button type="button" class="em-btn em-red-btn" id="cardClearStockBtn"><i class="fa fa-eraser"></i>清空库存</button>
        </div>
    </div>

    <!-- 表格 -->
    <div class="va-table-wrap" style="margin-bottom: 15px;">
        <table id="cardTable" lay-filter="cardTable"></table>
    </div>

</div><!-- /popup-inner -->

<!-- 状态徽章：em-tag + 圆点，和项目其他列表页统一 -->
<script type="text/html" id="cardStatusTpl">
    {{# if(d.status == 1){ }}
    <span class="em-tag em-tag--on"><span class="em-tag__dot"></span>未售</span>
    {{# } else if(d.status == 0){ }}
    <span class="em-tag em-tag--muted"><span class="em-tag__dot"></span>已售</span>
    {{# } else if(d.status == 2){ }}
    <span class="em-tag em-tag--amber"><span class="em-tag__dot"></span>标记售出</span>
    {{# } }}
</script>

<!-- 卡号列模板（含密码图标 + 优先销售图标） -->
<script type="text/html" id="cardNoTpl">
    <span class="card-no-cell" title="{{d.card_no}}">{{d.card_no}}</span>
    {{# if(d.card_pwd){ }}
    <i class="fa fa-key" style="color:#9ca3af;margin-left:4px;font-size:11px;" title="含密码"></i>
    {{# } }}
    {{# if(d.sell_priority > 0){ }}
    <i class="fa fa-bolt" style="color:#f59e0b;margin-left:4px;font-size:11px;" title="优先销售"></i>
    {{# } }}
</script>

<!-- 时间列模板 -->
<script type="text/html" id="cardTimeTpl">
    {{# if(d.LAY_TIME_VAL){ var parts = d.LAY_TIME_VAL.replace('T',' ').substring(0,19).split(' '); }}
    <span class="va-time">
        <span class="va-time__date">{{parts[0]}}</span>
        <span class="va-time__clock">{{parts[1] || ''}}</span>
    </span>
    {{# } else { }}
    <span class="em-tag em-tag--muted">—</span>
    {{# } }}
</script>

<!-- 操作列（三点下拉菜单） -->
<script type="text/html" id="cardActionTpl">
    <a class="em-btn em-reset-btn card-more-btn" data-id="{{d.id}}" data-status="{{d.status}}" style="padding:0 10px;">
        <i class="fa fa-ellipsis-h"></i>
    </a>
</script>

<script>
var csrfToken      = <?= json_encode($csrfToken) ?>;
var goodsId        = <?= $goodsId ?>;
var specMap        = <?= json_encode($specMap, JSON_UNESCAPED_UNICODE) ?>;
var currentTabStatus = '1';
var currentTotal = 0;

$(function(){
    'use strict';

    var table, dropdown, form, layer;

    layui.use(['layer', 'form', 'table', 'dropdown'], function(){
        layer    = layui.layer;
        form     = layui.form;
        table    = layui.table;
        dropdown = layui.dropdown;

        form.render('select');

        // 卡密 action 入口：主站默认 /admin/index.php，商户端由 popup header 覆盖为 /user/merchant/goods.php
        var cardActionBase = (typeof CARD_ACTION_BASE !== 'undefined' && CARD_ACTION_BASE) ? CARD_ACTION_BASE : '/admin/index.php';
        function cardUrl(action) {
            var sep = cardActionBase.indexOf('?') >= 0 ? '&' : '?';
            return cardActionBase + sep + '_action=' + encodeURIComponent(action);
        }

        // em-filter 展开/收起（和其他页一致，localStorage 记忆）
        var $filter = $('#cardFilter');
        var filterOpenKey = 'virtual_card_inventory_filter_open';
        function setFilterOpen(open) {
            $filter.toggleClass('is-open', open);
            $('.em-filter__toggle-text', $filter).text(open ? '收起' : '展开');
            localStorage.setItem(filterOpenKey, open ? 'y' : 'n');
        }
        setFilterOpen(localStorage.getItem(filterOpenKey) === 'y');
        $('#cardFilterHead').on('click', function () { setFilterOpen(!$filter.hasClass('is-open')); });

        // ============================================================
        //  列定义（根据当前选项卡动态生成）
        // ============================================================
        var specTplFn = function(d){ return specMap[d.spec_id] || '<span style="color:#9ca3af;">-</span>'; };
        var orderTplFn = function(d){ return d.order_id > 0 ? '<span style="color:#2563eb;font-family:Menlo,Consolas,monospace;">#'+d.order_id+'</span>' : '<span style="color:#9ca3af;">-</span>'; };
        // 用 LAY_TIME_VAL 字段名转交给 cardTimeTpl 模板（layui templet 里没法直接写变量名参数）
        var makeTimeTpl = function(field) {
            return function(d){
                var v = d[field];
                if (!v) return '<span class="em-tag em-tag--muted">—</span>';
                var parts = String(v).replace('T',' ').substring(0,19).split(' ');
                return '<span class="va-time"><span class="va-time__date">'+parts[0]+'</span><span class="va-time__clock">'+(parts[1]||'')+'</span></span>';
            };
        };

        function getTableCols(){
            var cols = [
                {type:'checkbox', width:50},
                {field:'seq', title:'序号', width:70, align:'center'},
                {field:'card_no', title:'卡号', minWidth:200, templet:'#cardNoTpl'},
                {field:'spec_id', title:'规格', width:150, templet: specTplFn},
                {field:'status', title:'状态', width:110, align:'center', templet:'#cardStatusTpl'}
            ];
            // 非"未售" tab 显示订单列
            if (currentTabStatus !== '1') {
                cols.push({field:'order_id', title:'订单', width:90, align:'center', templet: orderTplFn});
            }
            // 已售 / 标记售出 / 全部 显示售出时间列
            if (currentTabStatus === '0' || currentTabStatus === '2' || currentTabStatus === '') {
                cols.push({field:'sold_at', title:'售出时间', width:130, align:'center', templet: makeTimeTpl('sold_at')});
            }
            cols.push({field:'created_at', title:'入库时间', width:130, align:'center', templet: makeTimeTpl('created_at')});
            cols.push({title:'操作', width:80, align:'center', fixed:'right', toolbar:'#cardActionTpl'});
            return [cols];
        }

        // ============================================================
        //  表格
        // ============================================================
        table.render({
            elem: '#cardTable',
            id: 'cardTableId',
            url: cardUrl('card_list'),
            method: 'POST',
            where: { goods_id: goodsId, status: '1' },
            page: true,
            limit: 10,
            limits: [10, 20, 50, 100],
            lineStyle: 'height: 55px;',
            cellMinWidth: 60,
            cols: getTableCols(),
            done: function(res){
                if (res.csrf_token) csrfToken = res.csrf_token;
                if (res.stats) updateStats(res.stats);
                if (typeof res.count === 'number') currentTotal = res.count;
                initRowDropdowns();
            }
        });

        // 勾选联动：批量删除按钮启用/禁用
        table.on('checkbox(cardTable)', function () {
            var checked = table.checkStatus('cardTableId').data.length > 0;
            $('#cardBatchDeleteBtn').toggleClass('em-disabled-btn', !checked);
        });

        // ============================================================
        //  统计数据（选项卡计数 + 规格库存卡片）
        // ============================================================
        function updateStats(stats){
            $('#tabAll').text(stats.total || '');
            $('#tabAvailable').text(stats.available || '');
            $('#tabSold').text(stats.sold || '');
            $('#tabMarked').text(stats.marked || '');
            if (stats.specs) {
                stats.specs.forEach(function(s){
                    var $item = $('#specStockStats .va-spec-item[data-spec-id="'+s.id+'"]');
                    if ($item.length) {
                        var $num = $item.find('.va-spec-item__num');
                        $num.text(s.available);
                        $num.toggleClass('is-zero', s.available === 0);
                    }
                });
            }
        }

        // ============================================================
        //  em-tabs 切换
        // ============================================================
        $('#cardTabs').on('click', '.em-tabs__item', function(){
            var $item = $(this);
            if ($item.hasClass('is-active')) return;
            $item.addClass('is-active').siblings().removeClass('is-active');
            currentTabStatus = String($item.data('status') == null ? '' : $item.data('status'));
            reloadTable();
        });

        // ============================================================
        //  搜索 & 重置
        // ============================================================
        $(document).on('click', '#cardSearchBtn', function(){ reloadTable(); });
        $(document).on('click', '#cardResetBtn', function(){
            $('#cardSearchKeyword').val('');
            $('#cardSearchSpec').val('');
            $('#cardSearchOrderState').val('');
            form.render('select');
            reloadTable();
        });
        $('#cardSearchKeyword').on('keydown', function(e){ if(e.keyCode === 13) reloadTable(); });

        function reloadTable(){
            table.reload('cardTableId', {
                cols: getTableCols(),
                where: {
                    goods_id: goodsId,
                    status:   currentTabStatus,
                    keyword:  $('#cardSearchKeyword').val() || '',
                    spec_id:  $('#cardSearchSpec').val() || '',
                    order_state: $('#cardSearchOrderState').val() || ''
                },
                page: {curr: 1}
            });
        }

        // 弹窗尺寸：大屏用首选 px，小屏按视口百分比自适应（库存管理在 iframe 内）
        function cardModalArea(preferW) {
            var w = window.innerWidth >= preferW + 40 ? (preferW + 'px') : '95%';
            return [w, 'auto'];
        }
        function cardModalMaxH() {
            return Math.max(280, Math.floor(window.innerHeight * 0.9));
        }

        // ============================================================
        //  操作栏事件
        // ============================================================
        $('#cardRefreshBtn').on('click', function () { table.reload('cardTableId'); });

        $('#cardImportBtn').on('click', function () { openImport(); });

        $('#cardExportBtn').on('click', function () {
            var html = '<div style="padding:20px;">'
                + '<div style="margin-bottom:14px;"><label style="display:block;font-size:13px;color:#6b7280;margin-bottom:4px;">订单ID</label>'
                + '<input type="number" id="exportOrderId" min="1" class="layui-input" placeholder="留空 = 按下方序号区间导出"></div>'
                + '<div style="margin-bottom:14px;"><label style="display:block;font-size:13px;color:#6b7280;margin-bottom:4px;">起始序号</label>'
                + '<input type="number" id="exportFrom" min="1" class="layui-input" placeholder="留空 = 从第 1 个开始"></div>'
                + '<div style="margin-bottom:14px;"><label style="display:block;font-size:13px;color:#6b7280;margin-bottom:4px;">结束序号</label>'
                + '<input type="number" id="exportTo" min="1" class="layui-input" placeholder="留空 = 到最后一个"></div>'
                + '<div style="font-size:12px;color:#9ca3af;">序号即列表「序号」列（当前共 ' + currentTotal + ' 条）；起始 / 结束都留空则导出全部。</div>'
                + '<div style="font-size:12px;color:#9ca3af;margin-top:6px;">填了订单ID 就只导出该订单的卡密，状态 / 关键字 / 规格 / 序号区间一概忽略（订单已删除的卡密也能按订单ID取回）。</div>'
                + '</div>';
            layer.open({
                type: 1,
                title: '导出卡密',
                skin: 'admin-modal',
                area: cardModalArea(380),
                maxHeight: cardModalMaxH(),
                content: html,
                btn: ['导出', '取消'],
                yes: function (idx) {
                    var from = Math.max(0, parseInt($('#exportFrom').val(), 10) || 0);
                    var to = Math.max(0, parseInt($('#exportTo').val(), 10) || 0);
                    if (from > 0 && to > 0 && from > to) {
                        layer.msg('起始序号不能大于结束序号');
                        return;
                    }
                    var orderId = Math.max(0, parseInt($('#exportOrderId').val(), 10) || 0);
                    var url = cardUrl('card_export');
                    url += '&goods_id=' + encodeURIComponent(goodsId);
                    if (orderId > 0) {
                        // 按订单导出：订单ID 一说就只认它，其余条件一概不带
                        url += '&order_id=' + encodeURIComponent(orderId);
                    } else {
                        url += '&status=' + encodeURIComponent(currentTabStatus);
                        var keyword = $('#cardSearchKeyword').val() || '';
                        var specId = $('#cardSearchSpec').val() || '';
                        var orderState = $('#cardSearchOrderState').val() || '';
                        if (keyword) url += '&keyword=' + encodeURIComponent(keyword);
                        if (specId) url += '&spec_id=' + encodeURIComponent(specId);
                        if (orderState) url += '&order_state=' + encodeURIComponent(orderState);
                        if (from > 0) url += '&from=' + from;
                        if (to > 0) url += '&to=' + to;
                    }
                    layer.close(idx);
                    // 后台返回 Content-Disposition: attachment，新窗口打开即触发下载
                    var a = document.createElement('a');
                    a.href = url;
                    a.target = '_blank';
                    a.rel = 'noopener';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                }
            });
        });

        $('#cardBatchDeleteBtn').on('click', function () {
            if ($(this).hasClass('em-disabled-btn')) return;
            var data = table.checkStatus('cardTableId').data;
            if (!data.length) { layer.msg('请选择卡密'); return; }
            var ids = data.map(function (d) { return d.id; });
            layer.confirm('确定删除选中的 <strong>' + ids.length + '</strong> 条卡密吗？', function (idx) {
                layer.close(idx);
                doDelete(ids);
            });
        });

        $('#cardClearStockBtn').on('click', function () {
            layer.confirm('确定清空本商品全部「未售」卡密？已售与标记售出记录不会被删除。', function (idx) {
                layer.close(idx);
                $.post(cardUrl('card_clear_available'), {
                    csrf_token: csrfToken,
                    goods_id: goodsId
                }, function (res) {
                    if (res.data && res.data.csrf_token) {
                        csrfToken = res.data.csrf_token;
                    }
                    layer.msg(res.msg || (res.code === 200 ? '操作完成' : '操作失败'));
                    if (res.code === 200) {
                        table.reload('cardTableId');
                    }
                }, 'json');
            });
        });

        // ============================================================
        //  行内三点下拉菜单
        // ============================================================
        function initRowDropdowns(){
            $('.card-more-btn').each(function(){
                var $btn   = $(this);
                var cardId = $btn.data('id');
                var status = parseInt($btn.data('status'));

                var items = [
                    {title:'复制',   id:'copy', templet:'<i class="fa fa-clipboard"></i> {{= d.title }}'},
                    {title:'查看',   id:'view', templet:'<i class="fa fa-eye"></i> {{= d.title }}'}
                ];

                if (status === 1) {
                    items.push(
                        {type:'-'},
                        {title:'编辑',     id:'edit',     templet:'<i class="fa fa-pencil"></i> {{= d.title }}'},
                        {title:'优先销售', id:'priority', templet:'<i class="fa fa-bolt" style="color:#f59e0b;"></i> {{= d.title }}'},
                        {title:'标记售出', id:'markSold', templet:'<i class="fa fa-check" style="color:#10b981;"></i> {{= d.title }}'}
                    );
                }

                items.push(
                    {type:'-'},
                    {title:'删除', id:'delete', templet:'<i class="fa fa-trash" style="color:#dc2626;"></i> <span style="color:#dc2626;">{{= d.title }}</span>'}
                );

                dropdown.render({
                    elem: this,
                    data: items,
                    align: 'right',
                    click: function(obj){ handleAction(obj.id, cardId); }
                });
            });
        }

        // ============================================================
        //  操作处理
        // ============================================================
        function getCard(id){
            var rows = table.cache['cardTableId'] || [];
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].id == id) return rows[i];
            }
            return null;
        }

        function handleAction(action, cardId){
            var card = getCard(cardId);
            if (!card) { layer.msg('数据异常'); return; }

            switch(action){
                case 'copy':
                    copyText(card.card_no || '');
                    layer.msg('已复制');
                    break;
                case 'view':    showDetail(card);  break;
                case 'edit':    showEdit(card);    break;
                case 'priority':
                    ajaxAction('card_priority', {id: cardId}, card.sell_priority > 0 ? '已取消优先销售' : '已设为优先销售');
                    break;
                case 'markSold':
                    layer.confirm('确定将该卡密标记为已售出吗？', function(idx){
                        layer.close(idx);
                        ajaxAction('card_mark_sold', {id: cardId}, '标记成功');
                    });
                    break;
                case 'delete':
                    layer.confirm('确定删除该卡密吗？', function(idx){
                        layer.close(idx);
                        doDelete([cardId]);
                    });
                    break;
            }
        }

        // ============================================================
        //  查看详情（em-tag 胶囊风格）
        // ============================================================
        function showDetail(card){
            var specName = specMap[card.spec_id] || '-';
            var statusBadge = {
                1: '<span class="em-tag em-tag--on"><span class="em-tag__dot"></span>未售</span>',
                0: '<span class="em-tag em-tag--muted"><span class="em-tag__dot"></span>已售</span>',
                2: '<span class="em-tag em-tag--amber"><span class="em-tag__dot"></span>标记售出</span>'
            };
            var row = function(label, val){
                return '<tr><td style="padding:9px 12px;color:#6b7280;white-space:nowrap;width:80px;font-size:13px;vertical-align:top;">' + label
                     + '</td><td style="padding:9px 12px;font-size:13px;word-break:break-all;">' + val + '</td></tr>';
            };
            var html = '<div style="padding:16px 20px;box-sizing:border-box;">'
                // 卡号高亮区
                + '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 16px;margin-bottom:16px;">'
                + '<div style="font-size:12px;color:#9ca3af;margin-bottom:4px;">卡号</div>'
                + '<div style="font-family:Menlo,Consolas,monospace;font-size:15px;word-break:break-all;color:#111827;">' + esc(card.card_no) + '</div>'
                + (card.card_pwd
                    ? '<div style="font-size:12px;color:#9ca3af;margin-top:10px;">密码</div><div style="font-family:Menlo,Consolas,monospace;font-size:15px;word-break:break-all;color:#111827;">' + esc(card.card_pwd) + '</div>'
                    : '')
                + '</div>'
                + '<table style="width:100%;border-collapse:collapse;">'
                + row('状态', (statusBadge[card.status] || '未知')
                    + (card.sell_priority > 0 ? ' <i class="fa fa-bolt" style="color:#f59e0b;margin-left:6px;" title="优先销售"></i>' : ''))
                + row('规格', esc(specName))
                + (card.order_id > 0 ? row('订单', '<span style="color:#2563eb;font-family:Menlo,Consolas,monospace;">#' + card.order_id + '</span>') : '')
                + row('入库时间', card.created_at || '-')
                + (card.sold_at ? row('售出时间', card.sold_at) : '')
                + (card.remark ? row('备注', esc(card.remark)) : '')
                + '</table></div>';
            layer.open({
                type: 1,
                title: '卡密详情',
                skin: 'admin-modal',
                area: cardModalArea(460),
                maxHeight: cardModalMaxH(),
                content: html,
                shadeClose: true
            });
        }

        // ============================================================
        //  编辑卡密
        // ============================================================
        function showEdit(card){
            var specOpts = '<option value="">请选择规格</option>';
            for (var sid in specMap) {
                specOpts += '<option value="' + sid + '"' + (card.spec_id == sid ? ' selected' : '') + '>' + esc(specMap[sid]) + '</option>';
            }
            var lbl = 'display:block;font-size:13px;color:#6b7280;margin-bottom:4px;';
            var html = '<div style="padding:20px;box-sizing:border-box;">'
                + '<div style="margin-bottom:14px;"><label style="' + lbl + '">卡号</label>'
                + '<input type="text" class="layui-input" id="editCardNo" value="' + escAttr(card.card_no) + '"></div>'
                + '<div style="margin-bottom:14px;"><label style="' + lbl + '">密码</label>'
                + '<input type="text" class="layui-input" id="editCardPwd" value="' + escAttr(card.card_pwd || '') + '" placeholder="无密码可留空"></div>'
                + '<div style="margin-bottom:14px;"><label style="' + lbl + '">规格</label>'
                + '<select class="layui-input" id="editSpecId" style="height:38px;">' + specOpts + '</select></div>'
                + '<div style="margin-bottom:0;"><label style="' + lbl + '">备注</label>'
                + '<input type="text" class="layui-input" id="editRemark" value="' + escAttr(card.remark || '') + '" placeholder="选填"></div>'
                + '</div>';
            layer.open({
                type: 1,
                title: '编辑卡密',
                skin: 'admin-modal',
                area: cardModalArea(420),
                maxHeight: cardModalMaxH(),
                content: html,
                btn: ['保存', '取消'],
                yes: function(idx){
                    var cardNo = $.trim($('#editCardNo').val());
                    if (!cardNo) { layer.msg('卡号不能为空'); return; }
                    $.post(cardUrl('card_save'), {
                        csrf_token: csrfToken,
                        id: card.id,
                        goods_id: goodsId,
                        card_no:  cardNo,
                        card_pwd: $('#editCardPwd').val(),
                        spec_id:  $('#editSpecId').val(),
                        remark:   $('#editRemark').val()
                    }, function(res){
                        if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                        if (res.code === 200) {
                            layer.close(idx);
                            layer.msg('保存成功');
                            table.reload('cardTableId');
                        } else {
                            layer.msg(res.msg || '保存失败');
                        }
                    }, 'json');
                }
            });
        }

        // ============================================================
        //  通用 AJAX（优先销售 / 标记售出等）
        // ============================================================
        function ajaxAction(action, data, successMsg){
            data.csrf_token = csrfToken;
            data.goods_id   = goodsId;
            $.post(cardUrl(action), data, function(res){
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                layer.msg(res.msg || (res.code === 200 ? successMsg : '操作失败'));
                if (res.code === 200) table.reload('cardTableId');
            }, 'json');
        }

        function doDelete(ids){
            $.post(cardUrl('card_delete'), {
                csrf_token: csrfToken, ids: ids
            }, function(res){
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                layer.msg(res.msg || (res.code === 200 ? '删除成功' : '删除失败'));
                if (res.code === 200) table.reload('cardTableId');
            }, 'json');
        }

        // ============================================================
        //  导入弹窗
        // ============================================================
        function openImport(){
            // 库存管理本身在 iframe 内，导入弹窗用父页 layer 打开，避免套娃
            var rootLayer = (window.parent && window.parent.layer) ? window.parent.layer : layer;
            var pWin = window.parent || window;
            rootLayer.open({
                type: 2,
                title: '<i class="fa fa-upload"></i> 导入卡密',
                skin: 'admin-modal',
                maxmin: true,
                area: [pWin.innerWidth >= 800 ? '560px' : '95%', pWin.innerHeight >= 800 ? '520px' : '80%'],
                shadeClose: true,
                content: cardUrl('card_import_page') + '&goods_id=' + goodsId,
                end: function(){ table.reload('cardTableId'); }
            });
        }

        // ============================================================
        //  工具函数
        // ============================================================
        function esc(s){ return s ? $('<i>').text(s).html() : ''; }
        function escAttr(s){
            return s ? String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;') : '';
        }
        function copyText(text){
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                var $t = $('<input>').val(text).appendTo('body').select();
                document.execCommand('copy');
                $t.remove();
            }
        }
    });
});
</script>
