<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = $csrfToken ?? Csrf::token();
?>
<style>
.admin-page-merchant-market { padding: 8px 4px 40px; background: unset; }

.mm-toolbar {
    display: flex; gap: 10px; align-items: center; margin: 0 0 12px;
    flex-wrap: wrap;
}
.mm-toolbar .layui-input, .mm-toolbar .layui-select {
    height: 34px; font-size: 13px;
}
.mm-toolbar__search {
    position: relative; flex: 0 0 240px;
}
.mm-toolbar__search input {
    width: 100%; height: 34px; padding: 0 12px 0 32px;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
    outline: none; font-size: 13px;
    transition: border-color .15s, box-shadow .15s;
}
.mm-toolbar__search input:focus {
    border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.12);
}
.mm-toolbar__search i {
    position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
    color: #9ca3af; font-size: 12px; pointer-events: none;
}
.mm-toolbar__hint {
    margin-left: auto; color: #6b7280; font-size: 12px;
}

.mm-cover {
    width: 36px; height: 36px; border-radius: 8px; object-fit: cover;
    background: #f3f4f6; vertical-align: middle;
}
.mm-cover--empty {
    display: inline-flex; align-items: center; justify-content: center;
    color: #9ca3af; font-size: 14px;
}
.mm-title { font-weight: 600; color: #0f172a; }
.mm-title small { display: block; color: #9ca3af; font-weight: 400; font-size: 12px; margin-top: 2px; }

.mm-type {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; font-size: 11px; font-weight: 500;
    border-radius: 4px; line-height: 18px;
}
.mm-type--template { background: #ecfeff; color: #0891b2; }
.mm-type--plugin   { background: #f5f3ff; color: #7c3aed; }

.mm-stock { font-weight: 600; }
.mm-stock--ok    { color: #059669; }
.mm-stock--warn  { color: #d97706; }
.mm-stock--out   { color: #dc2626; }

.mm-listed-tag {
    display: inline-block; padding: 2px 8px; font-size: 11px; border-radius: 4px;
}
.mm-listed-tag--on  { background: #d1fae5; color: #065f46; }
.mm-listed-tag--off { background: #fee2e2; color: #991b1b; }

.mm-price { font-weight: 600; color: #1e293b; }
.mm-price small { color: #94a3b8; font-weight: 400; }

.mm-act-btn {
    padding: 4px 10px; height: 28px; line-height: 20px;
    font-size: 12px; border-radius: 6px;
}
</style>

<div class="admin-page-merchant-market">
    <form class="mm-toolbar em-list-search" id="mmSearchForm" autocomplete="off">
        <div class="mm-toolbar__search">
            <i class="fa fa-search"></i>
            <input type="search" id="mmSearch" placeholder="搜索应用名称 / 标识符" enterkeyhint="search">
        </div>
        <select id="mmType" class="layui-select">
            <option value="">全部类型</option>
            <option value="plugin">插件</option>
            <option value="template">模板</option>
        </select>
        <select id="mmListed" class="layui-select">
            <option value="">全部状态</option>
            <option value="1">已上架</option>
            <option value="0">已下架</option>
        </select>
        <div class="mm-toolbar__hint">
            上架新应用 → <a href="/admin/appstore.php?tab=merchant" class="layui-text-blue">应用商店 · 分站货架</a>
        </div>
    </form>

    <table id="mmTableId" lay-filter="mmTable"></table>
</div>

<script type="text/html" id="mmCoverTpl">
{{# if (d.cover) { }}
    <img class="mm-cover" src="{{ d.cover }}" alt="">
{{# } else { }}
    <span class="mm-cover mm-cover--empty"><i class="fa fa-cube"></i></span>
{{# } }}
</script>

<script type="text/html" id="mmTitleTpl">
<div class="mm-title">
    {{ d.title || d.app_code }}
    <small>{{ d.app_code }}{{# if (d.category) { }} · {{ d.category }}{{# } }}</small>
</div>
</script>

<script type="text/html" id="mmTypeTpl">
<span class="mm-type mm-type--{{ d.type }}">
    <i class="fa fa-{{ d.type === 'template' ? 'paint-brush' : 'puzzle-piece' }}"></i>
    {{ d.type === 'template' ? '模板' : '插件' }}
</span>
</script>

<script type="text/html" id="mmStockTpl">
{{# var rem = d.remaining || 0;
   var cls = rem === 0 ? 'mm-stock--out' : (rem <= 3 ? 'mm-stock--warn' : 'mm-stock--ok'); }}
<span class="mm-stock {{ cls }}">{{ rem }}</span>
<small style="color:#94a3b8;">/ {{ d.total_quota || 0 }}</small>
</script>

<script type="text/html" id="mmSoldTpl">
{{ d.sold_count || 0 }} / {{ d.consumed_quota || 0 }}
</script>

<script type="text/html" id="mmPriceTpl">
<span class="mm-price">¥ {{ ((d.retail_price || 0) / 1000000).toFixed(2) }}</span>
<small> / 次</small>
</script>

<script type="text/html" id="mmListedTpl">
{{# if (d.is_listed == 1) { }}
    <span class="mm-listed-tag mm-listed-tag--on">已上架</span>
{{# } else { }}
    <span class="mm-listed-tag mm-listed-tag--off">已下架</span>
{{# } }}
</script>

<script type="text/html" id="mmActionTpl">
<button class="layui-btn layui-btn-xs mm-act-btn" lay-event="setPrice">改价</button>
{{# if (d.is_listed == 1) { }}
    <button class="layui-btn layui-btn-xs layui-btn-warm mm-act-btn" lay-event="toggleList">下架</button>
{{# } else { }}
    <button class="layui-btn layui-btn-xs layui-btn-normal mm-act-btn" lay-event="toggleList">上架</button>
{{# } }}
<button class="layui-btn layui-btn-xs layui-btn-primary mm-act-btn" lay-event="logs">流水</button>
</script>

<script>
window.MM_CSRF = <?= json_encode($csrfToken) ?>;
layui.use(['table', 'layer', 'form'], function () {
    var $ = layui.jquery, table = layui.table, layer = layui.layer, form = layui.form;

    function buildWhere() {
        return {
            keyword:   $('#mmSearch').val().trim(),
            type:      $('#mmType').val() || '',
            is_listed: $('#mmListed').val() || ''
        };
    }

    table.render({
        elem: '#mmTableId',
        url:  '/admin/merchant_market.php?_action=list',
        method: 'get',
        where: buildWhere(),
        page: true,
        limit: 20,
        limits: [10, 20, 50, 100],
        skin: 'line',
        even: true,
        height: 'full-160',
        parseData: function (res) {
            return {
                code:  res.code === 200 ? 0 : (res.code || 500),
                msg:   res.msg || '',
                count: res.data && res.data.count !== undefined ? res.data.count : (res.count || 0),
                data:  res.data && res.data.data ? res.data.data : (res.data || [])
            };
        },
        request: { pageName: 'page', limitName: 'limit' },
        cols: [[
            { field: 'cover',          title: '封面',  width: 70,  align: 'center', templet: '#mmCoverTpl', unresize: true },
            { field: 'title',          title: '应用',  minWidth: 220, templet: '#mmTitleTpl' },
            { field: 'type',           title: '类型',  width: 80,  templet: '#mmTypeTpl', align: 'center' },
            { field: 'version',        title: '版本',  width: 80,  align: 'center' },
            { field: 'remaining',      title: '剩余库存', width: 110, templet: '#mmStockTpl', align: 'center' },
            { field: 'sold_count',     title: '已售/已扣', width: 110, templet: '#mmSoldTpl',  align: 'center' },
            { field: 'retail_price',   title: '分站售价', width: 130, templet: '#mmPriceTpl' },
            { field: 'is_listed',      title: '上架',   width: 90,  templet: '#mmListedTpl', align: 'center' },
            { title: '操作', width: 220, align: 'center', toolbar: '#mmActionTpl' }
        ]]
    });

    function reloadTable() {
        table.reload('mmTableId', { where: buildWhere(), page: { curr: 1 } });
    }

    // 筛选事件
    var searchTimer;
    $('#mmSearch').on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(reloadTable, 300);
    });
    $(document).on('em:search', '#mmSearchForm', function () {
        clearTimeout(searchTimer);
        reloadTable();
    });
    form.on('select(mmType)', reloadTable);
    form.on('select(mmListed)', reloadTable);
    // layui 不会自动给原生 select 绑事件,这里 fallback 监听 change
    $('#mmType, #mmListed').on('change', reloadTable);

    // 工具条事件
    table.on('tool(mmTable)', function (obj) {
        var d = obj.data, evt = obj.event;
        if (evt === 'setPrice') return openPriceModal(d);
        if (evt === 'toggleList') return doToggleList(d);
        if (evt === 'logs') return openLogsModal(d);
    });

    // ---------- 改价 ----------
    function openPriceModal(d) {
        var current = ((d.retail_price || 0) / 1000000).toFixed(2);
        var html = '<div style="padding:18px 20px;">' +
                   '  <div style="margin-bottom:10px;color:#6b7280;font-size:13px;">' +
                   '    应用:<b style="color:#0f172a;">' + (d.title || d.app_code) + '</b>' +
                   '  </div>' +
                   '  <div style="margin-bottom:6px;color:#6b7280;font-size:12px;">' +
                   '    分站售价(元 / 次)' +
                   '  </div>' +
                   '  <input id="mmPriceInput" class="layui-input" type="number" min="0" step="0.01" value="' + current + '" autofocus>' +
                   '  <div style="margin-top:6px;color:#94a3b8;font-size:12px;">' +
                   '    主站采购成本约 ¥' + ((d.cost_price || 0) / 1000000).toFixed(2) + ' / 次' +
                   '  </div>' +
                   '</div>';
        layer.open({
            type: 1, title: '修改分站售价', area: ['380px', 'auto'], shadeClose: false,
            content: html, btn: ['确定', '取消'],
            yes: function (idx) {
                var v = parseFloat($('#mmPriceInput').val());
                if (isNaN(v) || v < 0) { layer.msg('价格非法'); return; }
                var micro = Math.round(v * 1000000);
                $.post('/admin/merchant_market.php', {
                    _action:           'set_retail_price',
                    csrf_token:        window.MM_CSRF,
                    market_id:         d.id,
                    retail_price_micro: micro
                }).done(function (res) {
                    if (res.code === 200) {
                        layer.msg('已更新');
                        if (res.data && res.data.csrf_token) window.MM_CSRF = res.data.csrf_token;
                        layer.close(idx);
                        reloadTable();
                    } else {
                        layer.msg(res.msg || '更新失败');
                    }
                }).fail(function () { layer.msg('网络异常'); });
            }
        });
    }

    // ---------- 上下架 ----------
    function doToggleList(d) {
        var listing = (d.is_listed != 1);
        layer.confirm(
            '确定要' + (listing ? '上架' : '下架') + '<b>' + (d.title || d.app_code) + '</b>吗?' +
            (listing ? '' : '<br><small style="color:#94a3b8;">下架不影响已购分站,但分站市场不可见、不再售出。</small>'),
            { title: '确认' },
            function (idx) {
                $.post('/admin/merchant_market.php', {
                    _action:    'toggle_list',
                    csrf_token: window.MM_CSRF,
                    market_id:  d.id,
                    is_listed:  listing ? 1 : 0
                }).done(function (res) {
                    if (res.code === 200) {
                        layer.msg(listing ? '已上架' : '已下架');
                        if (res.data && res.data.csrf_token) window.MM_CSRF = res.data.csrf_token;
                        layer.close(idx);
                        reloadTable();
                    } else {
                        layer.msg(res.msg || '操作失败');
                    }
                }).fail(function () { layer.msg('网络异常'); });
            }
        );
    }

    // ---------- 采购流水 ----------
    function openLogsModal(d) {
        $.get('/admin/merchant_market.php', { _action: 'logs', market_id: d.id }, function (res) {
            if (res.code !== 200) { layer.msg(res.msg || '加载失败'); return; }
            var logs = (res.data && res.data.list) || [];
            var rows = logs.map(function (l) {
                return '<tr>' +
                    '<td>' + emEsc(l.purchased_at || '') + '</td>' +
                    '<td>' + emEsc(l.remark || '-') + '</td>' +
                    '<td style="text-align:right;">' + (l.purchase_qty || 0) + '</td>' +
                    '<td style="text-align:right;">¥' + ((l.cost_per_unit || 0) / 1000000).toFixed(2) + '</td>' +
                    '<td style="text-align:right;">¥' + ((l.total_cost || 0) / 1000000).toFixed(2) + '</td>' +
                    '<td>' + emEsc(l.remote_order_no || '-') + '</td>' +
                    '</tr>';
            }).join('');
            if (!rows) rows = '<tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:30px;">暂无采购流水</td></tr>';

            var html = '<div style="padding:0 6px;">' +
                '<table class="layui-table" style="margin:0;">' +
                '  <thead><tr>' +
                '    <th>时间</th><th>说明</th><th style="text-align:right;">数量</th>' +
                '    <th style="text-align:right;">单价</th><th style="text-align:right;">小计</th><th>订单号</th>' +
                '  </tr></thead>' +
                '  <tbody>' + rows + '</tbody>' +
                '</table>' +
                '</div>';
            layer.open({
                type: 1, title: '采购流水 · ' + emEsc(d.title || d.app_code),
                area: ['760px', '440px'], shadeClose: true,
                content: html, btn: false
            });
        }, 'json').fail(function () { layer.msg('网络异常'); });
    }
});
</script>
