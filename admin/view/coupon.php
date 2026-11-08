<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
$typeOptions = CouponModel::typeOptions();
?>
<!-- 搜索条件（em-filter 风格） -->
<div class="em-filter" id="couponFilter">
    <div class="em-filter__head" id="couponFilterHead">
        <span class="em-filter__title"><i class="fa fa-filter"></i>搜索条件</span>
        <span class="em-filter__toggle"><i class="fa fa-angle-down"></i><span class="em-filter__toggle-text">展开</span></span>
    </div>
    <div class="em-filter__body">
        <form class="em-filter__form" data-em-search-btn="#couponSearchBtn" autocomplete="off">
        <div class="em-filter__grid">
            <div class="em-filter__field">
                <label>关键字</label>
                <input type="search" id="couponSearchKeyword" placeholder="名称 / 券码 / 标题" enterkeyhint="search">
            </div>
            <div class="em-filter__field">
                <label>类型</label>
                <select id="couponSearchType">
                    <option value="">全部</option>
                    <?php foreach ($typeOptions as $k => $v): ?>
                    <option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="em-filter__field">
                <label>状态</label>
                <select id="couponSearchEnabled">
                    <option value="">全部</option>
                    <option value="1">启用中</option>
                    <option value="0">已禁用</option>
                </select>
            </div>
        </div>
        <div class="em-filter__actions">
            <button type="button" class="em-btn em-reset-btn" id="couponResetBtn"><i class="fa fa-undo mr-5"></i>重置</button>
            <button type="button" class="em-btn em-save-btn" id="couponSearchBtn"><i class="fa fa-search mr-5"></i>搜索</button>
        </div>
        </form>
    </div>
</div>

<div class="admin-page">
    <h1 class="admin-page__title">优惠券</h1>
    <table id="couponTable" lay-filter="couponTable"></table>
</div>

<!-- 表头工具栏 -->
<script type="text/html" id="couponToolbarTpl">
    <div class="layui-btn-container">
        <a class="em-btn em-reset-btn" id="couponRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加优惠券</a>
        <a class="em-btn em-red-btn em-disabled-btn" lay-event="batchDelete"><i class="fa fa-trash"></i>批量删除</a>
    </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="couponRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        <a class="em-btn em-sm-btn em-red-btn" lay-event="del"><i class="fa fa-trash"></i>删除</a>
    </div>
</script>

<!-- 类型徽章 -->
<script type="text/html" id="couponTypeTpl">
    {{#
    var typeMap = <?= json_encode($typeOptions, JSON_UNESCAPED_UNICODE) ?>;
    }}
    <span class="layui-badge layui-bg-blue">{{ typeMap[d.type] || d.type }}</span>
</script>

<!-- 券值展示 -->
<script type="text/html" id="couponValueTpl">
    {{# if (d.type === 'fixed_amount') { }}
    减 ¥{{ d.value }}
    {{# } else if (d.type === 'percent') { }}
    打 {{ (d.value/10).toFixed(1) }} 折
    {{# } else if (d.type === 'free_shipping') { }}
    免运费
    {{# } }}
</script>

<!-- 使用次数 -->
<script type="text/html" id="couponUsageTpl">
    {{ d.used_count }} / {{# if (d.total_usage_limit === -1) { }}∞{{# } else { }}{{ d.total_usage_limit }}{{# } }}
</script>

<!-- 有效期 -->
<script type="text/html" id="couponValidTpl">
    {{# if (d.start_at || d.end_at) { }}
    <div style="font-size:12px;line-height:1.4;">
        {{# if (d.start_at) { }}<div>起 {{ d.start_at.substring(0, 16) }}</div>{{# } }}
        {{# if (d.end_at) { }}<div>止 {{ d.end_at.substring(0, 16) }}</div>{{# } }}
    </div>
    {{# } else { }}<span class="layui-badge layui-bg-gray">不限</span>{{# } }}
</script>

<!-- 状态开关 -->
<script type="text/html" id="couponStatusTpl">
    <input type="checkbox" name="status" value="{{d.id}}" lay-skin="switch" lay-text="启用|禁用" lay-filter="couponStatusFilter" {{d.is_enabled == 1 ? 'checked' : ''}}>
</script>

<!-- 前台展示开关 -->
<script type="text/html" id="couponShowFrontTpl">
    <input type="checkbox" name="show_on_front" value="{{d.id}}" lay-skin="switch" lay-text="展示|隐藏" lay-filter="couponShowFrontFilter" {{d.show_on_front == 1 ? 'checked' : ''}}>
</script>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admCoupon handler，避免事件成倍触发
    $(document).off('.admCoupon');
    $(window).off('.admCoupon');

    'use strict';
    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var tableIns;

    function updateCsrf(token) { if (token) csrfToken = token; }
    window.updateCsrf = updateCsrf;

    layui.use(['layer', 'form', 'table'], function () {
        var layer = layui.layer, form = layui.form, table = layui.table;

        // em-filter 展开/收起（自己实现，不依赖 layui.element.collapse）
        var $filter = $('#couponFilter');
        var filterOpenKey = 'coupon_filter_open';
        function setFilterOpen(open) {
            $filter.toggleClass('is-open', open);
            $('.em-filter__toggle-text', $filter).text(open ? '收起' : '展开');
            localStorage.setItem(filterOpenKey, open ? 'y' : 'n');
        }
        setFilterOpen(localStorage.getItem(filterOpenKey) === 'y');
        $('#couponFilterHead').on('click', function () {
            setFilterOpen(!$filter.hasClass('is-open'));
        });

        tableIns = table.render({
            elem: '#couponTable',
            id: 'couponTableId',
            url: '/admin/coupon.php',
            method: 'POST',
            where: { _action: 'list' },
            page: true,
            limit: 10,
            limits: [10, 20, 50, 100],
            toolbar: '#couponToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 55px;',
            cols: [[
                { type: 'checkbox' },
                { type: 'numbers', title: '序号', width: 60 },
                { field: 'code', title: '券码', width: 140, align: 'center' },
                { field: 'name', title: '名称', minWidth: 150 },
                { field: 'type', title: '类型', width: 90, align: 'center', templet: '#couponTypeTpl' },
                { field: 'value', title: '券值', width: 100, align: 'center', templet: '#couponValueTpl' },
                { field: 'min_amount', title: '门槛', width: 90, align: 'center', templet: function(d){ return d.min_amount > 0 ? '满 ¥'+d.min_amount : '无门槛'; } },
                { field: 'usage', title: '使用次数', width: 110, align: 'center', templet: '#couponUsageTpl' },
                { field: 'valid', title: '有效期', width: 160, align: 'center', templet: '#couponValidTpl' },
                { field: 'show_on_front', title: '前台显示', width: 100, align: 'center', templet: '#couponShowFrontTpl' },
                { field: 'is_enabled', title: '状态', width: 90, align: 'center', templet: '#couponStatusTpl' },
                { title: '操作', width: 200, templet: '#couponRowActionTpl', align: 'center', fixed: 'right' }
            ]],
            parseData: function (res) {
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                return {
                    code: res.code === 200 ? 0 : res.code,
                    msg: res.msg,
                    data: res.data ? res.data.data : [],
                    count: res.data ? res.data.total : 0
                };
            }
        });

        $(document).on('click.admCoupon', '#couponSearchBtn', function () {
            table.reload('couponTableId', {
                page: { curr: 1 },
                where: {
                    _action: 'list',
                    keyword: $('#couponSearchKeyword').val() || '',
                    type: $('#couponSearchType').val() || '',
                    enabled: $('#couponSearchEnabled').val() || ''
                }
            });
        });

        $(document).on('click.admCoupon', '#couponResetBtn', function () {
            $('#couponSearchKeyword').val('');
            $('#couponSearchType').val('');
            $('#couponSearchEnabled').val('');
            form.render('select');
            table.reload('couponTableId', { page: { curr: 1 }, where: { _action: 'list' } });
        });

        $(document).on('click.admCoupon', '#couponRefreshBtn', function () {
            table.reload('couponTableId');
        });

        // 状态切换
        form.on('switch(couponStatusFilter)', function (obj) {
            var id = this.value;
            $.ajax({
                url: '/admin/coupon.php', type: 'POST', dataType: 'json',
                data: { csrf_token: csrfToken, _action: 'toggle', id: id },
                success: function (res) {
                    if (res.code === 200) {
                        if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                        layer.msg(res.msg || '已更新');
                    } else {
                        obj.elem.checked = !obj.elem.checked;
                        form.render('switch');
                        layer.msg(res.msg || '更新失败');
                    }
                },
                error: function () {
                    obj.elem.checked = !obj.elem.checked;
                    form.render('switch');
                    layer.msg('网络异常');
                }
            });
        });

        // 前台显示切换
        form.on('switch(couponShowFrontFilter)', function (obj) {
            var id = this.value;
            $.ajax({
                url: '/admin/coupon.php', type: 'POST', dataType: 'json',
                data: { csrf_token: csrfToken, _action: 'toggle_show_front', id: id },
                success: function (res) {
                    if (res.code === 200) {
                        if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                        layer.msg(res.msg || '已更新');
                    } else {
                        obj.elem.checked = !obj.elem.checked;
                        form.render('switch');
                        layer.msg(res.msg || '更新失败');
                    }
                },
                error: function () {
                    obj.elem.checked = !obj.elem.checked;
                    form.render('switch');
                    layer.msg('网络异常');
                }
            });
        });

        // 工具栏：添加 / 批量删除
        table.on('toolbar(couponTable)', function (obj) {
            if (obj.event === 'add') {
                openPopup('添加优惠券');
            } else if (obj.event === 'batchDelete') {
                var checked = table.checkStatus('couponTableId').data;
                if (!checked.length) { layer.msg('请先勾选优惠券'); return; }
                var ids = checked.map(function (r) { return r.id; });
                layer.confirm('确定要删除选中的 ' + ids.length + ' 张优惠券吗？此操作不可恢复。', function (idx) {
                    $.ajax({
                        url: '/admin/coupon.php', type: 'POST', dataType: 'json',
                        data: { csrf_token: csrfToken, _action: 'batch_delete', ids: ids },
                        success: function (res) {
                            if (res.code === 200) {
                                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                                layer.msg(res.msg || '批量删除成功');
                                table.reload('couponTableId');
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

        // 勾选 → 切换批量删除按钮启用态
        table.on('checkbox(couponTable)', function () {
            var checked = table.checkStatus('couponTableId').data.length > 0;
            $('[lay-event="batchDelete"]').toggleClass('em-disabled-btn', !checked);
        });

        // 行内操作
        table.on('tool(couponTable)', function (obj) {
            var data = obj.data;
            if (obj.event === 'edit') {
                openPopup('编辑优惠券', data.id);
            } else if (obj.event === 'del') {
                layer.confirm('确定删除优惠券「' + emEsc(data.name) + '」吗？此操作不可恢复', function (idx) {
                    $.ajax({
                        url: '/admin/coupon.php', type: 'POST', dataType: 'json',
                        data: { csrf_token: csrfToken, _action: 'delete', id: data.id },
                        success: function (res) {
                            if (res.code === 200) {
                                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                                layer.msg(res.msg || '已删除'); obj.del();
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

        function openPopup(title, editId) {
            var url = '/admin/coupon.php?_popup=1';
            if (editId) url += '&id=' + encodeURIComponent(editId);
            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 900 ? '680px' : '95%', window.innerHeight >= 800 ? '780px' : '90%'],
                shadeClose: false,
                content: url,
                end: function () {
                    if (window._couponPopupSaved) {
                        window._couponPopupSaved = false;
                        table.reload('couponTableId');
                    }
                }
            });
        }
    });
});
</script>
