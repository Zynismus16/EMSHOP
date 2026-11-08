<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<div class="admin-page">
    <h1 class="admin-page__title">导航管理</h1>
    <table id="naviTable" lay-filter="naviTable"></table>
</div>

<!-- 头部工具栏 -->
<script type="text/html" id="naviToolbarTpl">
    <div class="layui-btn-container">
        <a class="em-btn em-reset-btn" id="naviRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加导航</a>
        <a class="em-btn em-purple-btn" id="naviToggleBtn"><i class="fa fa-compress"></i>全部折叠</a>
    </div>
</script>

<!-- 行操作按钮（系统导航不可删除） -->
<script type="text/html" id="naviRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        {{# if(d.is_system != 1){ }}
        <a class="em-btn em-sm-btn em-red-btn" lay-event="del"><i class="fa fa-trash"></i>删除</a>
        {{# } }}
    </div>
</script>

<!-- 类型模板（em-tag 胶囊） -->
<script type="text/html" id="naviTypeTpl">
    {{# if(d.type === 'system'){ }}
    <span class="em-tag em-tag--blue">系统</span>
    {{# } else if(d.type === 'goods_cat'){ }}
    <span class="em-tag em-tag--on">商品分类</span>
    {{# } else if(d.type === 'blog_cat'){ }}
    <span class="em-tag em-tag--amber">博客分类</span>
    {{# } else if(d.type === 'page'){ }}
    <span class="em-tag em-tag--red">自定义页面</span>
    {{# } else { }}
    <span class="em-tag em-tag--purple">自定义</span>
    {{# } }}
</script>

<!-- 链接模板（可点击外链，没值时灰标签） -->
<script type="text/html" id="naviLinkTpl">
    {{# if(d.link){ }}
    <a href="{{d.link}}" target="_blank" class="admin-link-cell" title="{{d.link}}" onclick="event.stopPropagation();">{{d.link}}</a>
    {{# } else { }}
    <span class="em-tag em-tag--muted">未设置</span>
    {{# } }}
</script>

<!-- 打开方式：点击切换（新窗口 / 当前窗口） -->
<script type="text/html" id="naviTargetTpl">
    {{# if(d.target === '_blank'){ }}
    <span class="em-tag em-tag--blue em-tag--clickable" lay-event="toggleTarget" title="点击改为当前窗口">
        <span class="em-tag__dot"></span>新窗口
    </span>
    {{# } else { }}
    <span class="em-tag em-tag--muted em-tag--clickable" lay-event="toggleTarget" title="点击改为新窗口">
        <span class="em-tag__dot"></span>当前窗口
    </span>
    {{# } }}
</script>

<!-- 状态：点击切换 -->
<script type="text/html" id="naviStatusTpl">
    {{# if(d.status == 1){ }}
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
    // PJAX 防重复绑定：清掉本页历史 .admNavi handler，避免事件成倍触发
    $(document).off('.admNavi');
    $(window).off('.admNavi');

    'use strict';

    var csrfToken = <?php echo json_encode($csrfToken); ?>;

    function updateCsrf(token) { if (token) csrfToken = token; }
    window.updateCsrf = updateCsrf;

    layui.use(['layer', 'form', 'treeTable'], function () {
        var layer = layui.layer;
        var form = layui.form;
        var treeTable = layui.treeTable;

        // 渲染树形表格
        var inst = treeTable.render({
            elem: '#naviTable',
            id: 'naviTableId',
            url: '/admin/navi.php',
            method: 'POST',
            where: {_action: 'list'},
            toolbar: '#naviToolbarTpl',
            defaultToolbar: [],
            page: false,
            tree: {
                data: { isSimpleData: true, rootPid: 0 },
                customName: { id: 'id', pid: 'parent_id', name: 'name' },
                view: { showIcon: false, expandAllDefault: true }
            },
            lineStyle: 'height: 55px;',
            cols: [[
                {field: 'name', title: '导航名称', minWidth: 180},
                {field: 'type', title: '类型', width: 120, templet: '#naviTypeTpl', align: 'center'},
                {field: 'link', title: '链接', minWidth: 200, templet: '#naviLinkTpl'},
                {field: 'target', title: '打开方式', width: 130, templet: '#naviTargetTpl', align: 'center'},
                {field: 'sort', title: '排序', width: 80, sort: true, align: 'center', edit: 'text'},
                {field: 'status', title: '状态', width: 100, templet: '#naviStatusTpl', align: 'center'},
                {title: '操作', width: 200, align: 'center', toolbar: '#naviRowActionTpl'}
            ]],
            done: function (res) {
                if (res.csrf_token) csrfToken = res.csrf_token;
            }
        });

        // 刷新
        $(document).on('click.admNavi', '#naviRefreshBtn', function () {
            treeTable.reloadData('naviTableId');
        });

        // 展开/折叠
        var expanded = true;
        $(document).on('click.admNavi', '#naviToggleBtn', function () {
            expanded = !expanded;
            treeTable.expandAll('naviTableId', expanded);
            $(this).html(expanded
                ? '<i class="fa fa-compress"></i>全部折叠'
                : '<i class="fa fa-expand"></i>全部展开'
            );
        });

        // 工具栏事件
        treeTable.on('toolbar(naviTable)', function (obj) {
            if (obj.event === 'add') openPopup('添加导航');
        });

        // 行内编辑排序
        treeTable.on('edit(naviTable)', function (obj) {
            if (obj.field === 'sort') {
                var sortData = JSON.stringify([{id: obj.data.id, sort: parseInt(obj.value) || 100}]);
                $.post('/admin/navi.php', {
                    _action: 'sort', csrf_token: csrfToken, sort_data: sortData
                }, function (res) {
                    if (res.code === 200) {
                        if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                        layer.msg('排序已保存');
                    } else {
                        layer.msg(res.msg || '保存失败');
                    }
                }, 'json');
            }
        });

        // 行操作（编辑/删除/点击状态或打开方式切换标签）
        treeTable.on('tool(naviTable)', function (obj) {
            var data = obj.data;
            if (obj.event === 'edit') {
                openPopup('编辑导航', data.id);
            } else if (obj.event === 'del') {
                layer.confirm('确定要删除导航「' + emEsc(data.name) + '」吗？', function (idx) {
                    $.post('/admin/navi.php', {
                        _action: 'delete', csrf_token: csrfToken, id: data.id
                    }, function (res) {
                        if (res.code === 200) {
                            if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                            layer.msg(res.msg || '已删除');
                            treeTable.reloadData('naviTableId');
                        } else {
                            layer.msg(res.msg || '删除失败');
                        }
                        layer.close(idx);
                    }, 'json');
                });
            } else if (obj.event === 'toggleStatus') {
                toggleTag($(this), '/admin/navi.php', {_action: 'toggle_status', id: data.id}, {
                    onClass: 'em-tag--on', offClass: 'em-tag--muted',
                    onHtml: '<span class="em-tag__dot"></span>启用', offHtml: '<span class="em-tag__dot"></span>禁用',
                    onTitle: '点击禁用', offTitle: '点击启用'
                });
            } else if (obj.event === 'toggleTarget') {
                // 注意 target 不是 0/1 切换，而是字符串：_blank ↔ _self，需要读当前 class 决定下一个值
                var $tag = $(this);
                var goingToBlank = $tag.hasClass('em-tag--muted');  // 当前是"当前窗口"（灰）→ 切到新窗口
                toggleTag($tag, '/admin/navi.php',
                    {_action: 'update_target', id: data.id, target: goingToBlank ? '_blank' : '_self'},
                    {
                        onClass: 'em-tag--blue', offClass: 'em-tag--muted',
                        onHtml: '<span class="em-tag__dot"></span>新窗口', offHtml: '<span class="em-tag__dot"></span>当前窗口',
                        onTitle: '点击改为当前窗口', offTitle: '点击改为新窗口'
                    }
                );
            }
        });

        // 通用：点击 em-tag 切换状态，成功后原地改 class/文案/title，不 reload
        function toggleTag($tag, url, data, cfg) {
            if ($tag.hasClass('is-loading')) return;
            $tag.addClass('is-loading');
            data.csrf_token = csrfToken;
            $.post(url, data, function (res) {
                if (res.code === 200) {
                    if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                    if ($tag.hasClass(cfg.onClass)) {
                        $tag.removeClass(cfg.onClass).addClass(cfg.offClass)
                            .attr('title', cfg.offTitle).html(cfg.offHtml);
                    } else {
                        $tag.removeClass(cfg.offClass).addClass(cfg.onClass)
                            .attr('title', cfg.onTitle).html(cfg.onHtml);
                    }
                    layer.msg(res.msg || '操作成功');
                } else {
                    layer.msg(res.msg || '操作失败');
                }
            }, 'json').fail(function () {
                layer.msg('网络异常');
            }).always(function () {
                $tag.removeClass('is-loading');
            });
        }

        // 弹窗
        function openPopup(title, editId) {
            var url = '/admin/navi.php?_popup=1';
            if (editId) url += '&id=' + editId;
            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 800 ? '560px' : '95%', window.innerHeight >= 800 ? '640px' : '90%'],
                shadeClose: true,
                content: url,
                end: function () {
                    if (window._naviPopupSaved) {
                        window._naviPopupSaved = false;
                        treeTable.reloadData('naviTableId');
                    }
                }
            });
        }
    });
});
</script>
