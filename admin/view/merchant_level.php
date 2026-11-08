<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<div class="admin-page">
    <h1 class="admin-page__title">商户等级</h1>
    <table id="mlTable" lay-filter="mlTable"></table>
</div>

<!-- 头部工具栏 -->
<script type="text/html" id="mlToolbarTpl">
    <div class="layui-btn-container">
        <a class="em-btn em-reset-btn" id="mlRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加等级</a>
    </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="mlRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        <a class="em-btn em-sm-btn em-red-btn" lay-event="del"><i class="fa fa-trash"></i>删除</a>
    </div>
</script>

<!-- 自助开通价 -->
<script type="text/html" id="mlPriceTpl">
    {{# if(d.price == 0){ }}
    <span class="em-tag em-tag--muted">不可自助开通</span>
    {{# } else { }}
    <span class="em-tag em-tag--amber">{{d.price_view}} 元</span>
    {{# } }}
</script>

<!-- 自建手续费 -->
<script type="text/html" id="mlFeeTpl">
    <span class="em-tag em-tag--blue">{{d.self_goods_fee_rate_view}}%</span>
</script>

<!-- 提现手续费 -->
<script type="text/html" id="mlWithdrawFeeTpl">
    <span class="em-tag em-tag--blue">{{d.withdraw_fee_rate_view}}%</span>
</script>

<!-- 域名权限：二级域名 / 自定义顶级域名（点击即切换，data-on-class 告知 JS 启用态样式） -->
<script type="text/html" id="mlFlagsTpl">
    <span class="em-tag em-tag--clickable {{d.allow_subdomain == 1 ? 'em-tag--on' : 'em-tag--off'}}"
          data-id="{{d.id}}" data-field="allow_subdomain" data-on-class="em-tag--on" title="点击切换">二级域名</span>
    <span class="em-tag em-tag--clickable {{d.allow_custom_domain == 1 ? 'em-tag--on' : 'em-tag--off'}}"
          data-id="{{d.id}}" data-field="allow_custom_domain" data-on-class="em-tag--on" title="点击切换">自定义域名</span>
</script>

<!-- 功能权限：自建商品（同上） -->
<script type="text/html" id="mlPermsTpl">
    <span class="em-tag em-tag--clickable {{d.allow_self_goods == 1 ? 'em-tag--purple' : 'em-tag--off'}}"
          data-id="{{d.id}}" data-field="allow_self_goods" data-on-class="em-tag--purple" title="点击切换">自建商品</span>
</script>

<!-- 状态开关 -->
<script type="text/html" id="mlStatusTpl">
    <input type="checkbox" name="status" value="{{d.id}}" lay-skin="switch" lay-text="启用|禁用" lay-filter="mlStatusFilter" {{d.is_enabled == 1 ? 'checked' : ''}}>
</script>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admMerchantLevel handler，避免事件成倍触发
    $(document).off('.admMerchantLevel');
    $(window).off('.admMerchantLevel');

    'use strict';

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var tableIns;

    function updateCsrf(token) {
        if (token) csrfToken = token;
    }
    window.updateCsrf = updateCsrf;

    layui.use(['layer', 'form', 'table'], function () {
        var layer = layui.layer;
        var form = layui.form;
        var table = layui.table;

        // 渲染表格（无搜索、无分页——等级总数很少，全部拉取）
        tableIns = table.render({
            elem: '#mlTable',
            id: 'mlTableId',
            url: '/admin/merchant_level.php',
            headers: {csrf: csrfToken},
            method: 'POST',
            where: {_action: 'list'},
            page: false,
            toolbar: '#mlToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 55px;',
            initSort: {field: 'sort', type: 'asc'},
            cols: [[
                {title: '序号', width: 70, align: 'center', type: 'numbers'},
                {field: 'name', title: '等级名称', minWidth: 140, align: 'center'},
                {field: 'price', title: '自助开通价', minWidth: 140, align: 'center', templet: '#mlPriceTpl'},
                {field: 'self_goods_fee_rate', title: '自建手续费', minWidth: 110, align: 'center', templet: '#mlFeeTpl'},
                {field: 'withdraw_fee_rate', title: '提现手续费', minWidth: 110, align: 'center', templet: '#mlWithdrawFeeTpl'},
                {title: '域名权限', minWidth: 220, align: 'center', templet: '#mlFlagsTpl'},
                {title: '功能权限', minWidth: 200, align: 'center', templet: '#mlPermsTpl'},
                {field: 'sort', title: '排序', width: 80, align: 'center'},
                {field: 'status', title: '状态', minWidth: 90, templet: '#mlStatusTpl', align: 'center'},
                {title: '操作', width: 200, templet: '#mlRowActionTpl', align: 'center'}
            ]],
            parseData: function (res) {
                if (res.data && res.data.csrf_token) {
                    csrfToken = res.data.csrf_token;
                }
                return {
                    'code': res.code === 200 ? 0 : res.code,
                    'msg': res.msg,
                    'data': res.data ? res.data.data : [],
                    'count': res.data ? res.data.total : 0
                };
            }
        });

        // 状态开关：点击切换 enabled，带 loading 动效 + 失败回滚
        form.on('switch(mlStatusFilter)', function (obj) {
            var id = this.value;
            var $switch = $(obj.elem);
            var $wrap = $switch.closest('.layui-unselect');
            var $switchSpan = $wrap.find('.layui-form-switch');

            $switchSpan.css('position', 'relative').append('<i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop" style="position:absolute;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.85);font-size:16px;"></i>');
            $switch.prop('disabled', true);

            $.ajax({
                url: '/admin/merchant_level.php',
                type: 'POST',
                dataType: 'json',
                data: {csrf_token: csrfToken, _action: 'toggle', id: id},
                success: function (res) {
                    if (res.code === 200) {
                        csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                        $switchSpan.find('i').removeClass().addClass('layui-icon layui-icon-ok').fadeOut(600, function(){ $(this).remove(); });
                        layer.msg(res.msg || '状态已更新');
                    } else {
                        obj.elem.checked = !obj.elem.checked;
                        form.render('switch');
                        $switchSpan.find('i').removeClass().addClass('layui-icon layui-icon-close').fadeOut(600, function(){ $(this).remove(); });
                        layer.msg(res.msg || '更新失败');
                    }
                },
                error: function () {
                    obj.elem.checked = !obj.elem.checked;
                    form.render('switch');
                    layer.msg('网络异常');
                },
                complete: function () {
                    $switch.prop('disabled', false);
                }
            });
        });

        // 行内事件
        table.on('tool(mlTable)', function (obj) {
            var data = obj.data;
            switch (obj.event) {
                case 'edit':
                    openPopup('编辑商户等级', data.id);
                    break;
                case 'del':
                    layer.confirm('确定要删除等级「' + emEsc(data.name) + '」吗？此操作不可恢复。', function (idx) {
                        $.ajax({
                            url: '/admin/merchant_level.php',
                            type: 'POST',
                            dataType: 'json',
                            data: {csrf_token: csrfToken, _action: 'delete', id: data.id},
                            success: function (res) {
                                if (res.code === 200) {
                                    csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
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
                    break;
            }
        });

        // 刷新按钮
        $(document).on('click.admMerchantLevel', '#mlRefreshBtn', function () {
            table.reload('mlTableId');
        });

        // 权限标签点击切换：命中表格内 em-tag--clickable，向 toggle_perm 提交 field + id
        // 成功：按返回值重排 on/off class；失败：不动
        $(document).on('click.admMerchantLevel', '#mlTable + .layui-table-view .em-tag--clickable[data-field]', function () {
            var $tag = $(this);
            if ($tag.hasClass('is-loading')) return;
            var id = $tag.data('id');
            var field = $tag.data('field');
            var onClass = $tag.data('on-class') || 'em-tag--on';
            $tag.addClass('is-loading');

            $.ajax({
                url: '/admin/merchant_level.php',
                type: 'POST',
                dataType: 'json',
                data: {csrf_token: csrfToken, _action: 'toggle_perm', id: id, field: field},
                success: function (res) {
                    if (res.code === 200) {
                        csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                        if ((res.data && res.data.value) === 1) {
                            $tag.removeClass('em-tag--off').addClass(onClass);
                        } else {
                            $tag.removeClass(onClass).addClass('em-tag--off');
                        }
                    } else {
                        layer.msg(res.msg || '切换失败');
                    }
                },
                error: function () { layer.msg('网络异常'); },
                complete: function () { $tag.removeClass('is-loading'); }
            });
        });

        // 头部工具栏
        table.on('toolbar(mlTable)', function (obj) {
            if (obj.event === 'add') {
                openPopup('添加商户等级');
            }
        });

        // 弹窗（新增 / 编辑）
        function openPopup(title, editId) {
            var url = '/admin/merchant_level.php?_popup=1';
            if (editId) url += '&id=' + encodeURIComponent(editId);
            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 800 ? '620px' : '95%', window.innerHeight >= 800 ? '578px' : '90%'],
                shadeClose: true,
                content: url,
                end: function () {
                    if (window._mlPopupSaved) {
                        window._mlPopupSaved = false;
                        table.reload('mlTableId');
                    }
                }
            });
        }
    });
});
</script>
