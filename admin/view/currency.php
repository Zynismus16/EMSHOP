<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<!-- 多币种说明（独立版块，和表格分开） -->
<div class="currency-intro">
    <div class="currency-intro__icon"><i class="fa fa-info-circle"></i></div>
    <div class="currency-intro__body">
        <div class="currency-intro__title">多币种配置说明</div>
        <div class="currency-intro__desc">
            <strong>主货币</strong>（<span id="primaryCodeDisplay">-</span>）一旦设定不可再切换，数据库里所有金额都以主货币存储。<br>
            新增其他货币仅用于前台展示换算；<strong>汇率 = 1 单位该货币 = 多少个主货币</strong>
            （例：主货币 CNY，USD 汇率 7.23 表示 1 USD = 7.23 CNY）。
        </div>
    </div>
</div>

<style>
.currency-intro {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    background: #eef2ff;
    border: 1px solid #c7d2fe;
    border-radius: 10px;
    padding: 14px 18px;
    margin-bottom: 14px;
    box-shadow: 0 1px 3px rgba(99, 102, 241, 0.05);
}
.currency-intro__icon {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(99, 102, 241, 0.15);
    color: #4f46e5;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}
.currency-intro__title {
    font-size: 14px;
    font-weight: 600;
    color: #3730a3;
    margin-bottom: 4px;
}
.currency-intro__desc {
    font-size: 13px;
    color: #4338ca;
    line-height: 1.7;
}
.currency-intro__desc strong {
    color: #312e81;
}
</style>

<div class="admin-page">
    <h1 class="admin-page__title">货币配置</h1>
    <table id="currencyTable" lay-filter="currencyTable"></table>
</div>

<!-- 行工具栏 -->
<script type="text/html" id="currencyToolbarTpl">
    <div class="layui-btn-container">
        <a class="em-btn em-reset-btn" id="currencyRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加货币</a>
    </div>
</script>

<!-- 行内操作（主货币不允许删除） -->
<script type="text/html" id="currencyRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        {{# if(!(d.is_primary === true || d.is_primary === 1)){ }}
        <a class="em-btn em-sm-btn em-red-btn" lay-event="del"><i class="fa fa-trash"></i>删除</a>
        {{# } }}
    </div>
</script>

<!-- 货币代码列：主货币加紫色胶囊 -->
<script type="text/html" id="currencyCodeTpl">
    {{# if(d.is_primary){ }}
    <span class="em-tag em-tag--purple" style="font-weight:600;">{{d.code}}</span>
    {{# } else { }}
    <span style="font-family:Menlo,Consolas,monospace;color:#374151;">{{d.code}}</span>
    {{# } }}
</script>

<!-- 主货币标识 -->
<script type="text/html" id="currencyPrimaryTpl">
    {{# if(d.is_primary){ }}
    <span class="em-tag em-tag--on"><span class="em-tag__dot"></span>主货币</span>
    {{# } else { }}
    <span class="em-tag em-tag--muted">—</span>
    {{# } }}
</script>

<!-- 汇率列（主货币始终是 1） -->
<script type="text/html" id="currencyRateTpl">
    {{# if(d.is_primary){ }}
    <span class="em-tag em-tag--muted">基准</span>
    {{# } else { }}
    <span style="font-family:Menlo,Consolas,monospace;color:#111827;">{{d.rate.toFixed(4)}}</span>
    {{# } }}
</script>

<!-- 前台默认（访客首次访问展示哪个币；和主货币独立） -->
<script type="text/html" id="currencyFrontendDefaultTpl">
    {{# if(d.is_frontend_default){ }}
    <span class="em-tag em-tag--on"><span class="em-tag__dot"></span>默认</span>
    {{# } else if(d.enabled === 'y' || d.enabled === 1){ }}
    <span class="em-tag em-tag--muted em-tag--clickable" lay-event="setFrontendDefault" title="点击设为前台默认">
        <span class="em-tag__dot"></span>设为默认
    </span>
    {{# } else { }}
    <span class="em-tag em-tag--muted" title="已禁用的货币不能设为默认">—</span>
    {{# } }}
</script>

<!-- 状态切换标签（主货币不可切） -->
<script type="text/html" id="currencyStatusTpl">
    {{# if(d.is_primary){ }}
    <span class="em-tag em-tag--on"><span class="em-tag__dot"></span>启用</span>
    {{# } else if(d.enabled === 'y' || d.enabled === 1){ }}
    <span class="em-tag em-tag--on em-tag--clickable" lay-event="toggleStatus" title="点击禁用">
        <span class="em-tag__dot"></span>启用
    </span>
    {{# } else { }}
    <span class="em-tag em-tag--muted em-tag--clickable" lay-event="toggleStatus" title="点击启用">
        <span class="em-tag__dot"></span>禁用
    </span>
    {{# } }}
</script>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admCurrency handler，避免事件成倍触发
    $(document).off('.admCurrency');
    $(window).off('.admCurrency');

    'use strict';

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var tableIns;

    function updateCsrf(token) { if (token) csrfToken = token; }
    window.updateCsrf = updateCsrf;

    layui.use(['layer', 'form', 'table'], function () {
        var layer = layui.layer;
        var form = layui.form;
        var table = layui.table;

        // 渲染表格
        tableIns = table.render({
            elem: '#currencyTable',
            id: 'currencyTableId',
            url: '/admin/currency.php',
            method: 'POST',
            where: {_action: 'list'},
            page: false,
            toolbar: '#currencyToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 55px;',
            initSort: {field: 'code', type: 'asc'},
            cols: [[
                {title: '序号', width: 70, align: 'center', type: 'numbers'},
                {field: 'code', title: '货币代码', width: 130, align: 'center', templet: '#currencyCodeTpl', sort: true},
                {field: 'name', title: '货币名称', minWidth: 140, align: 'center'},
                {field: 'symbol', title: '符号', width: 80, align: 'center'},
                {field: 'rate', title: '兑主货币汇率', width: 160, align: 'center', templet: '#currencyRateTpl'},
                {field: 'is_primary', title: '主货币', width: 110, align: 'center', templet: '#currencyPrimaryTpl'},
                {field: 'is_frontend_default', title: '前台默认', width: 130, align: 'center', templet: '#currencyFrontendDefaultTpl'},
                {field: 'enabled', title: '状态', width: 110, align: 'center', templet: '#currencyStatusTpl'},
                {title: '操作', width: 200, templet: '#currencyRowActionTpl', align: 'center'}
            ]],
            parseData: function (res) {
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                if (res.data && res.data.primary_code) {
                    $('#primaryCodeDisplay').text(res.data.primary_code);
                }
                return {
                    'code': res.code === 200 ? 0 : res.code,
                    'msg': res.msg,
                    'data': res.data ? res.data.data : [],
                    'count': res.data ? (res.data.data || []).length : 0
                };
            }
        });

        // 刷新
        $(document).on('click.admCurrency', '#currencyRefreshBtn', function () {
            table.reload('currencyTableId');
        });

        // 头部工具栏：添加
        table.on('toolbar(currencyTable)', function (obj) {
            if (obj.event === 'add') openPopup('添加货币');
        });

        // 行内事件（编辑 / 删除 / 切换启用）
        table.on('tool(currencyTable)', function (obj) {
            var data = obj.data;
            switch (obj.event) {
                case 'edit':
                    openPopup('编辑货币', data.id);
                    break;
                case 'del':
                    layer.confirm('确定要删除货币「' + emEsc(data.code) + '」吗？此操作不可恢复。', function (idx) {
                        $.ajax({
                            url: '/admin/currency.php',
                            type: 'POST',
                            dataType: 'json',
                            data: {_action: 'delete', id: data.id, csrf_token: csrfToken},
                            success: function (res) {
                                if (res.code === 200) {
                                    csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                    layer.msg(res.msg || '删除成功');
                                    obj.del();
                                } else {
                                    csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                    layer.msg(res.msg || '删除失败');
                                }
                            },
                            error: function () { layer.msg('网络异常'); },
                            complete: function () { layer.close(idx); }
                        });
                    });
                    break;
                case 'toggleStatus':
                    var $tag = $(this);
                    if ($tag.hasClass('is-loading')) return;
                    $tag.addClass('is-loading');
                    $.ajax({
                        url: '/admin/currency.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {_action: 'toggle', id: data.id, csrf_token: csrfToken},
                        success: function (res) {
                            if (res.code === 200) {
                                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                if ($tag.hasClass('em-tag--on')) {
                                    $tag.removeClass('em-tag--on').addClass('em-tag--muted')
                                        .attr('title', '点击启用').html('<span class="em-tag__dot"></span>禁用');
                                } else {
                                    $tag.removeClass('em-tag--muted').addClass('em-tag--on')
                                        .attr('title', '点击禁用').html('<span class="em-tag__dot"></span>启用');
                                }
                                layer.msg(res.msg || '状态已更新');
                            } else {
                                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                layer.msg(res.msg || '更新失败');
                            }
                        },
                        error: function () { layer.msg('网络异常'); },
                        complete: function () { $tag.removeClass('is-loading'); }
                    });
                    break;
                case 'setFrontendDefault':
                    // 整表 reload 是因为"默认"是全局互斥关系 —— 改了一条，其他行的显示也要同步刷
                    $.ajax({
                        url: '/admin/currency.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {_action: 'set_frontend_default', id: data.id, csrf_token: csrfToken},
                        success: function (res) {
                            if (res.code === 200) {
                                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                layer.msg(res.msg || '已设为前台默认');
                                table.reload('currencyTableId');
                            } else {
                                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                layer.msg(res.msg || '设置失败');
                            }
                        },
                        error: function () { layer.msg('网络异常'); }
                    });
                    break;
            }
        });

        // 打开新增 / 编辑弹窗
        function openPopup(title, editId) {
            var url = '/admin/currency_popup.php?mode=' + (editId ? 'edit' : 'add');
            if (editId) url += '&id=' + encodeURIComponent(editId);
            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 800 ? '520px' : '95%', window.innerHeight >= 800 ? '620px' : '90%'],
                shadeClose: true,
                content: url,
                end: function () {
                    if (window._currencyPopupSaved) {
                        window._currencyPopupSaved = false;
                        table.reload('currencyTableId');
                    }
                }
            });
        }
    });
});
</script>
