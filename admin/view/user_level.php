<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<div class="admin-page">
    <h1 class="admin-page__title">用户等级</h1>
    <table id="levelTable" lay-filter="levelTable"></table>
</div>

<!-- 头部工具栏 -->
<script type="text/html" id="levelToolbarTpl">
    <div class="layui-btn-container">
        <a class="em-btn em-reset-btn" id="levelRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加等级</a>
    </div>
</script>

<!-- 行内操作 -->
<script type="text/html" id="levelRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        <a class="em-btn em-sm-btn em-red-btn" lay-event="del"><i class="fa fa-trash"></i>删除</a>
    </div>
</script>

<!-- 等级数值：Lv.N 紫色胶囊，凸显层级 -->
<script type="text/html" id="levelNumTpl">
    <span class="em-tag em-tag--purple" style="font-weight:600;">Lv.{{d.level}}</span>
</script>

<!-- 等级名称：带小皇冠图标，视觉上更有"等级"感 -->
<script type="text/html" id="levelNameTpl">
    <span style="display:inline-flex;align-items:center;gap:6px;font-weight:500;color:#374151;">
        <i class="fa fa-diamond" style="color:#8b5cf6;font-size:12px;"></i>
        {{d.name}}
    </span>
</script>

<!-- 享受折扣：琥珀胶囊，和商品价类标签统一 -->
<script type="text/html" id="levelDiscountTpl">
    <span class="em-tag em-tag--amber">{{d.discount}} 折</span>
</script>

<!-- 自助开通价格：有价显蓝色胶囊，0 显未设置 -->
<script type="text/html" id="levelPriceTpl">
    {{# if(d.self_open_price == 0){ }}
    <span class="em-tag em-tag--muted">未设置</span>
    {{# } else { }}
    <span class="em-tag em-tag--amber">¥ {{d.self_open_price.toFixed(2)}}</span>
    {{# } }}
</script>

<!-- 解锁经验值：有值显蓝胶囊，0 显未启用 -->
<script type="text/html" id="levelExpTpl">
    {{# if(d.unlock_exp == 0){ }}
    <span class="em-tag em-tag--muted">未启用</span>
    {{# } else { }}
    <span class="em-tag em-tag--blue"><i class="fa fa-star" style="font-size:10px;margin-right:3px;"></i>{{d.unlock_exp.toLocaleString()}}</span>
    {{# } }}
</script>

<!-- 备注：空值显灰标签避免单元格留白 -->
<script type="text/html" id="levelRemarkTpl">
    {{# if(d.remark){ }}
    <span style="color:#4b5563;">{{d.remark}}</span>
    {{# } else { }}
    <span class="em-tag em-tag--muted">无</span>
    {{# } }}
</script>

<!-- 状态开关 -->
<script type="text/html" id="levelStatusTpl">
    <input type="checkbox" name="status" value="{{d.id}}" lay-skin="switch" lay-text="启用|禁用" lay-filter="levelStatusFilter" {{d.enabled === 'y' ? 'checked' : ''}}>
</script>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admUserLevel handler，避免事件成倍触发
    $(document).off('.admUserLevel');
    $(window).off('.admUserLevel');

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

        // 渲染表格（无搜索、无分页——等级总数很少，直接全部拉出来）
        tableIns = table.render({
            elem: '#levelTable',
            id: 'levelTableId',
            url: '/admin/user_level.php',
            headers: {csrf: csrfToken},
            method: 'POST',
            where: {_action: 'list'},
            page: false,
            toolbar: '#levelToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 55px;',
            initSort: {field: 'level', type: 'asc'},
            cols: [[
                {title: '序号', width: 70, align: 'center', type: 'numbers'},
                {field: 'level', title: '等级', width: 90, align: 'center', templet: '#levelNumTpl'},
                {field: 'name', title: '等级名称', minWidth: 140, align: 'center', templet: '#levelNameTpl'},
                {field: 'discount', title: '享受折扣', minWidth: 110, align: 'center', templet: '#levelDiscountTpl'},
                {field: 'self_open_price', title: '自助开通价格', minWidth: 130, align: 'center', templet: '#levelPriceTpl'},
                {field: 'unlock_exp', title: '解锁经验值', minWidth: 130, align: 'center', templet: '#levelExpTpl'},
                {field: 'remark', title: '备注', minWidth: 150, align: 'center', templet: '#levelRemarkTpl', style: 'max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'},
                {field: 'status', title: '状态', minWidth: 90, templet: '#levelStatusTpl', align: 'center'},
                {title: '操作', width: 200, templet: '#levelRowActionTpl', align: 'center'}
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

        // 状态开关监听
        form.on('switch(levelStatusFilter)', function (obj) {
            var id = this.value;
            var $switch = $(obj.elem);
            var $wrap = $switch.closest('.layui-unselect');
            var $switchSpan = $wrap.find('.layui-form-switch');

            $switchSpan.css('position', 'relative').append('<i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop" style="position:absolute;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.85);font-size:16px;"></i>');
            $switch.prop('disabled', true);

            $.ajax({
                url: '/admin/user_level.php',
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
        table.on('tool(levelTable)', function (obj) {
            var data = obj.data;
            switch (obj.event) {
                case 'edit':
                    openPopup('编辑等级', data.id);
                    break;
                case 'del':
                    layer.confirm('确定要删除等级「' + emEsc(data.name) + '」吗？此操作不可恢复。', function (idx) {
                        $.ajax({
                            url: '/admin/user_level.php',
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
        $(document).on('click.admUserLevel', '#levelRefreshBtn', function () {
            table.reload('levelTableId');
        });

        // 头部工具栏
        table.on('toolbar(levelTable)', function (obj) {
            if (obj.event === 'add') {
                openPopup('添加等级');
            }
        });

        // 弹窗（新增 / 编辑）
        function openPopup(title, editId) {
            var url = '/admin/user_level.php?_popup=1';
            if (editId) url += '&id=' + encodeURIComponent(editId);
            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 800 ? '520px' : '95%', window.innerHeight >= 800 ? '780px' : '90%'],
                shadeClose: true,
                content: url,
                end: function () {
                    if (window._levelPopupSaved) {
                        window._levelPopupSaved = false;
                        table.reload('levelTableId');
                    }
                }
            });
        }
    });
});
</script>
