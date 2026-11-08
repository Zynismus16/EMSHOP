<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

// 类型配置
$typeConfig = [
    'goods' => [
        'label' => '商品分类',
        'icon'  => 'fa fa-shopping-bag',
        'desc'  => '管理商城商品分类，支持无限级子分类',
    ],
    'blog'  => [
        'label' => '文章分类',
        'icon'  => 'fa fa-pencil-square',
        'desc'  => '管理博客文章分类，支持无限级子分类',
    ],
    'nav'   => [
        'label' => '导航管理',
        'icon'  => 'fa fa-navicon',
        'desc'  => '管理网站导航菜单，支持无限级子菜单',
    ],
];

$csrfToken = Csrf::token();
?>
<div class="admin-page">
    <h1 class="admin-page__title"><?php echo htmlspecialchars($typeConfig[$currentType]['label'], ENT_QUOTES, 'UTF-8'); ?></h1>

    <!-- 搜索栏 -->
    <div class="cat-search-bar">
        <form class="layui-form layui-row layui-col-space12 em-list-search" data-em-search-btn="#catSearchBtn" lay-filter="cat-search-form" autocomplete="off">
            <div class="layui-col-md4">
                <div class="layui-input-wrap">
                    <div class="layui-input-prefix">
                        <i class="layui-icon layui-icon-search"></i>
                    </div>
                    <input type="search" name="keyword" id="catSearchKeyword" placeholder="搜索分类名称" class="layui-input" enterkeyhint="search">
                </div>
            </div>
            <div class="layui-col-md2">
                <button type="button" class="layui-btn" id="catSearchBtn" lay-filter="cat-search" lay-submit><i class="layui-icon layui-icon-search"></i> 搜索</button>
                <button type="reset" class="layui-btn layui-btn-primary" id="catSearchReset">重置</button>
            </div>
        </form>
    </div>

    <!-- 表格容器 -->
    <table id="catTable" lay-filter="catTable"></table>
</div>

<!-- 行工具栏模板 -->
<script type="text/html" id="catToolbarTpl">
    <div class="layui-btn-container">
        <a class="layui-btn layui-btn-sm" lay-event="add"><i class="layui-icon layui-icon-add-circle"></i> 添加分类</a>
    </div>
</script>

<!-- 行内操作按钮模板 -->
<script type="text/html" id="catRowActionTpl">
    <div class="layui-clear-space">
        <a class="layui-btn layui-btn-xs" lay-event="addSub"><i class="layui-icon layui-icon-add-circle"></i> 子分类</a>
        <a class="layui-btn layui-btn-xs layui-btn-normal" lay-event="edit"><i class="layui-icon layui-icon-edit"></i></a>
        <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="del"><i class="layui-icon layui-icon-delete"></i></a>
    </div>
</script>

<!-- 状态开关模板 -->
<script type="text/html" id="catStatusTpl">
    <input type="checkbox" name="status" id="catStatusSwitch" value="{{d.id}}" lay-skin="switch" lay-text="启用|禁用" lay-filter="catStatusFilter" {{d.status == 1 ? 'checked' : ''}}>
</script>

<!-- 分类图标模板 -->
<script type="text/html" id="catIconTpl">
    {{# if(d.icon){ }}
    <i class="{{d.icon}}"></i>
    {{# } }}
    <strong>{{d.name}}</strong>
    {{# if(d.slug){ }}
    <span class="cat-slug">{{d.slug}}</span>
    {{# } }}
</script>

<!-- 封面图片模板 -->
<script type="text/html" id="catCoverTpl">
    {{# if(d.cover_image){ }}
    <img src="{{d.cover_image}}" class="cat-cover-thumb" lay-event="previewImg">
    {{# } }}
</script>

<script>

$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admCategory handler，避免事件成倍触发
    $(document).off('.admCategory');
    $(window).off('.admCategory');

    'use strict';

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var currentType = <?php echo json_encode($currentType); ?>;
    var tableIns;

    function updateCsrf(token) {
        if (token) csrfToken = token;
    }
    window.updateCsrf = updateCsrf;

    
        layui.use(['layer', 'form', 'table'], function () {
            var layer = layui.layer;
            var form = layui.form;
            var table = layui.table;

            // 渲染表格
            tableIns = table.render({
                elem: '#catTable',
                id: 'catTableId',
                url: '/admin/category.php?type=' + currentType + '&_action=list',
                headers: {csrf: csrfToken},
                method: 'GET',
                page: false,
                toolbar: '#catToolbarTpl',
                defaultToolbar: ['filter', 'exports', 'print'],
                height: 'full-220',
                skin: 'nob',
                size: 'sm',
                cols: [[
                    {field: 'sort', title: '排序', width: 70, sort: true, align: 'center'},
                    {field: 'name', title: '名称', minWidth: 150, templet: '#catIconTpl'},
                    {field: 'parent_name', title: '上级分类', width: 140},
                    {field: 'cover_image', title: '封面', width: 70, templet: '#catCoverTpl', align: 'center', style: 'padding:4px 8px;'},
                    {field: 'description', title: '描述', minWidth: 150},
                    {field: 'status', title: '状态', width: 90, templet: '#catStatusTpl', align: 'center'},
                    {fixed: 'right', title: '操作', width: 180, templet: '#catRowActionTpl', align: 'center'}
                ]],
                done: function (res) {
                    csrfToken = res.csrf_token || csrfToken;
                }
            });

            // 状态开关监听
            form.on('switch(catStatusFilter)', function (obj) {
                var checked = obj.elem.checked;
                var id = this.value;
                $.ajax({
                    url: '/admin/category.php?type=' + currentType,
                    type: 'POST',
                    dataType: 'json',
                    data: {csrf_token: csrfToken, _action: 'toggle_status', id: id, status: checked ? 1 : 0},
                    success: function (res) {
                        if (res.code === 0) {
                            csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                            layer.msg(res.msg || '状态已更新', {icon: 1});
                        } else {
                            obj.elem.checked = !checked;
                            form.render('switch');
                            layer.msg(res.msg || '更新失败', {icon: 2});
                        }
                    },
                    error: function () {
                        obj.elem.checked = !checked;
                        form.render('switch');
                        layer.msg('网络异常', {icon: 2});
                    }
                });
            });

            // 搜索
            form.on('submit(cat-search)', function (data) {
                table.reload('catTableId', {
                    page: {curr: 1},
                    where: {keyword: data.field.keyword || ''}
                });
                return false;
            });

            // 重置
            $('#catSearchReset').on('click', function () {
                table.reload('catTableId', {page: {curr: 1}, where: {keyword: ''}});
            });

            // 封面图点击预览
            $(document).on('click.admCategory', '[lay-event="previewImg"]', function () {
                var src = $(this).attr('src');
                if (src) {
                    layer.photos({photos: {title: '', id: 0, start: 0, data: [{alt: '', pid: 0, src: src}]}, anim: 5});
                }
            });

            // 工具栏事件
            table.on('tool(catTable)', function (obj) {
                var data = obj.data;
                switch (obj.event) {
                    case 'addSub':
                        openPopup('添加子分类', data.id);
                        break;
                    case 'edit':
                        openPopup('编辑分类', null, data.id);
                        break;
                    case 'del':
                        layer.confirm('确定要删除分类「' + emEsc(data.name) + '」吗？此操作不可恢复。', function (idx) {
                            $.ajax({
                                url: '/admin/category.php?type=' + currentType,
                                type: 'POST',
                                dataType: 'json',
                                data: {csrf_token: csrfToken, _action: 'delete', id: data.id},
                                success: function (res) {
                                    if (res.code === 0) {
                                        csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                        layer.msg(res.msg || '删除成功', {icon: 1});
                                        obj.del();
                                    } else {
                                        layer.msg(res.msg || '删除失败', {icon: 2});
                                    }
                                },
                                error: function () { layer.msg('网络异常', {icon: 2}); },
                                complete: function () { layer.close(idx); }
                            });
                        });
                        break;
                }
            });

            // 头部工具栏
            table.on('toolbar(catTable)', function (obj) {
                if (obj.event === 'add') {
                    openPopup('添加分类');
                }
            });

            // 打开弹窗
            function openPopup(title, parentId, editId) {
                var url = '/admin/category.php?type=' + currentType + '&_popup=1';
                if (editId) url += '&id=' + editId;
                if (parentId) url += '&parent_id=' + parentId;
                layer.open({
                    type: 2,
                    title: title,
                    skin: 'admin-modal',
                    maxmin: true,
                    area: [window.innerWidth >= 800 ? '520px' : '95%', window.innerHeight >= 600 ? '85%' : '90%'],
                    shadeClose: true,
                    content: url,
                    end: function () {
                        table.reload('catTableId');
                    }
                });
            }

            // 行点击预览封面图
            table.on('row(catTable)', function (obj) {
                var $td = $(obj.tr).find('[lay-event="previewImg"]');
                if ($td.length) {
                    var src = $td.attr('src');
                    if (src) {
                        layer.photos({photos: {title: '', id: 0, start: 0, data: [{alt: '', pid: 0, src: src}]}, anim: 5});
                    }
                }
            });

        });
   
});

</script>

