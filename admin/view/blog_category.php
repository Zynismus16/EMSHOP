<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<!-- 搜索条件（em-filter 风格，和商品分类 / 商品列表一致） -->
<div class="em-filter" id="catFilter">
    <div class="em-filter__head" id="catFilterHead">
        <span class="em-filter__title"><i class="fa fa-filter"></i>搜索条件</span>
        <span class="em-filter__toggle"><i class="fa fa-angle-down"></i><span class="em-filter__toggle-text">展开</span></span>
    </div>
    <div class="em-filter__body">
        <form class="em-filter__form" data-em-search-btn="#catSearchBtn" autocomplete="off">
        <div class="em-filter__grid">
            <div class="em-filter__field">
                <label>分类名称</label>
                <input type="search" id="catSearchKeyword" placeholder="搜索分类名称" enterkeyhint="search">
            </div>
        </div>
        <div class="em-filter__actions">
            <button type="button" class="em-btn em-reset-btn" id="catResetBtn"><i class="fa fa-undo mr-5"></i>重置</button>
            <button type="button" class="em-btn em-save-btn" id="catSearchBtn"><i class="fa fa-search mr-5"></i>搜索</button>
        </div>
        </form>
    </div>
</div>
<div class="admin-page">
    <h1 class="admin-page__title">文章分类</h1>

    <table id="catTable" lay-filter="catTable"></table>
</div>

<!-- 行工具栏模板 -->
<script type="text/html" id="catToolbarTpl">
    <div class="layui-btn-container">
        <a class="em-btn em-reset-btn" id="catRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加分类</a>
        <a class="em-btn em-red-btn em-disabled-btn" id="catBatchDelBtn"><i class="fa fa-trash"></i>批量删除</a>
        <a class="em-btn em-purple-btn" id="catToggleBtn"><i class="fa fa-compress"></i>全部折叠</a>
    </div>
</script>

<!-- 行内操作按钮模板 -->
<script type="text/html" id="catRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        <a class="em-btn em-sm-btn em-red-btn" lay-event="del"><i class="fa fa-trash"></i>删除</a>
    </div>
</script>

<!-- 封面图片模板 -->
<script type="text/html" id="catCoverTpl">
    {{# if(d.cover_image){ }}
    <span lay-event="previewImg" style="cursor:pointer;">
        <img src="{{d.cover_image}}" style="width:36px;height:36px;object-fit:cover;border-radius:4px;">
    </span>
    {{# } }}
</script>

<!-- 状态开关模板 -->
<script type="text/html" id="catStatusTpl">
    <input type="checkbox" name="status" value="{{d.id}}" lay-skin="switch" lay-text="启用|禁用" lay-filter="catStatusFilter" {{d.status == 1 ? 'checked' : ''}}>
</script>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admBlogCat handler，避免事件成倍触发
    $(document).off('.admBlogCat');
    $(window).off('.admBlogCat');

    'use strict';

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var inst;

    function updateCsrf(token) {
        if (token) csrfToken = token;
    }
    window.updateCsrf = updateCsrf;

    layui.use(['layer', 'form', 'treeTable'], function () {
        var layer = layui.layer;
        var form = layui.form;
        var treeTable = layui.treeTable;

        // em-filter 展开/收起（自己做，不依赖 layui.element.collapse）
        var $filter = $('#catFilter');
        var filterOpenKey = 'blog_cat_filter_open';
        function setFilterOpen(open) {
            $filter.toggleClass('is-open', open);
            $('.em-filter__toggle-text', $filter).text(open ? '收起' : '展开');
            localStorage.setItem(filterOpenKey, open ? 'y' : 'n');
        }
        setFilterOpen(localStorage.getItem(filterOpenKey) === 'y');
        $('#catFilterHead').on('click', function () {
            setFilterOpen(!$filter.hasClass('is-open'));
        });

        // 渲染树形表格
        inst = treeTable.render({
            elem: '#catTable',
            id: 'catTableId',
            url: '/admin/blog_category.php',
            headers: {csrf: csrfToken},
            method: 'POST',
            where: {_action: 'list'},
            toolbar: '#catToolbarTpl',
            defaultToolbar: [],
            page: false,
            cellMinWidth: 120,
            lineStyle: 'height: 55px;',
            tree: {
                data: {
                    isSimpleData: true,
                    rootPid: 0
                },
                customName: {
                    id: 'id',
                    pid: 'parent_id',
                    name: 'name'
                },
                view: {
                    showIcon: false,
                    expandAllDefault: true
                }
            },
            cols: [[
                {type: 'checkbox', width: 50, unresize: true},
                {field: 'cover_image', title: '分类图片', width: 80, templet: '#catCoverTpl', align: 'center'},
                {field: 'name', title: '分类名称', minWidth: 200},
                {field: 'slug', title: '别名', width: 140},
                {field: 'sort', title: '排序', width: 80, sort: true, align: 'center'},
                {field: 'status', title: '状态', width: 90, templet: '#catStatusTpl', align: 'center', uncheck: true},
                {title: '操作', width: 220, align: 'center', toolbar: '#catRowActionTpl', uncheck: true}
            ]],
            done: function (res) {
                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                if (res.data && res.data.data) {
                    this.data = res.data.data;
                }
            }
        });

        // 搜索
        $(document).on('click.admBlogCat', '#catSearchBtn', function () {
            treeTable.reloadData('catTableId', {
                where: {
                    _action: 'list',
                    keyword: $('#catSearchKeyword').val() || ''
                }
            });
        });

        // 重置
        $(document).on('click.admBlogCat', '#catResetBtn', function () {
            $('#catSearchKeyword').val('');
            treeTable.reloadData('catTableId', {
                where: {
                    _action: 'list',
                    keyword: ''
                }
            });
        });

        // 刷新按钮
        $(document).on('click.admBlogCat', '#catRefreshBtn', function () {
            treeTable.reloadData('catTableId');
        });

        // 全部展开/折叠
        var treeExpanded = true;
        $(document).on('click.admBlogCat', '#catToggleBtn', function () {
            treeExpanded = !treeExpanded;
            treeTable.expandAll('catTableId', treeExpanded);
            var $btn = $('#catToggleBtn');
            if (treeExpanded) {
                $btn.html('<i class="fa fa-compress mr-6"></i>全部折叠');
            } else {
                $btn.html('<i class="fa fa-expand mr-6"></i>全部展开');
            }
        });

        // 头部工具栏
        treeTable.on('toolbar(catTable)', function (obj) {
            if (obj.event === 'add') {
                openPopup('添加分类');
            }
        });

        // 勾选变化 → 更新"批量删除"按钮可用态和计数
        treeTable.on('checkbox(catTable)', function () {
            var st = treeTable.checkStatus('catTableId');
            var cnt = (st && st.data) ? st.data.length : 0;
            var $btn = $('#catBatchDelBtn');
            if (cnt > 0) {
                $btn.removeClass('em-disabled-btn').html('<i class="fa fa-trash"></i>批量删除（' + cnt + '）');
            } else {
                $btn.addClass('em-disabled-btn').html('<i class="fa fa-trash"></i>批量删除');
            }
        });

        // 批量删除
        $(document).on('click.admBlogCat', '#catBatchDelBtn', function () {
            if ($(this).hasClass('em-disabled-btn')) return;
            var st = treeTable.checkStatus('catTableId');
            var rows = (st && st.data) ? st.data : [];
            if (!rows.length) return;
            var ids = rows.map(function (r) { return r.id; });
            var names = rows.map(function (r) { return r.name; }).slice(0, 3).join('、') + (rows.length > 3 ? '…' : '');

            layer.confirm('确认删除 ' + rows.length + ' 个分类？<br><span style="color:#9ca3af;font-size:12px;">' + emEsc(names) + '</span>', function (idx) {
                $.ajax({
                    url: '/admin/blog_category.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { csrf_token: csrfToken, _action: 'batch_delete', ids: ids },
                    success: function (res) {
                        if (res.code === 200) {
                            csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                            layer.msg(res.msg || '删除成功');
                            treeTable.reloadData('catTableId');
                        } else {
                            layer.msg(res.msg || '删除失败');
                        }
                    },
                    error: function () { layer.msg('网络异常'); },
                    complete: function () { layer.close(idx); }
                });
            });
        });

        // 行内工具栏
        treeTable.on('tool(catTable)', function (obj) {
            var data = obj.data;
            switch (obj.event) {
                case 'previewImg':
                    // 用 Viewer.js（admin/view/index.php 已全局加载）代替 layer.photos
                    var src = $(this).find('img').attr('src');
                    if (src) {
                        var $tmp = $('<div style="display:none;"><img src="' + src + '"></div>').appendTo('body');
                        var viewer = new Viewer($tmp[0], {
                            navbar: false, title: false, toolbar: true,
                            hidden: function () { viewer.destroy(); $tmp.remove(); }
                        });
                        viewer.show();
                    }
                    break;
                case 'edit':
                    openPopup('编辑分类', data.id);
                    break;
                case 'del':
                    layer.confirm('确定要删除分类「' + emEsc(data.name) + '」吗？此操作不可恢复。', function (idx) {
                        $.ajax({
                            url: '/admin/blog_category.php',
                            type: 'POST',
                            dataType: 'json',
                            data: {csrf_token: csrfToken, _action: 'delete', id: data.id},
                            success: function (res) {
                                if (res.code === 200) {
                                    csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                    layer.msg(res.msg || '删除成功');
                                    treeTable.removeNode('catTableId', obj.tr.attr('data-index'));
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

        // 状态开关监听
        form.on('switch(catStatusFilter)', function (obj) {
            var id = this.value;
            var $switch = $(obj.elem);
            var $wrap = $switch.closest('.layui-unselect');
            var $switchSpan = $wrap.find('.layui-form-switch');

            $switchSpan.css('position', 'relative').append('<i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop" style="position:absolute;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.85);font-size:16px;"></i>');
            $switch.prop('disabled', true);

            $.ajax({
                url: '/admin/blog_category.php',
                type: 'POST',
                dataType: 'json',
                data: {csrf_token: csrfToken, _action: 'toggle_status', id: id},
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

        // 打开弹窗
        function openPopup(title, editId) {
            var url = '/admin/blog_category.php?_popup=1';
            if (editId) url += '&id=' + encodeURIComponent(editId);
            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 800 ? '720px' : '95%', window.innerHeight >= 800 ? '775px' : '90%'],
                shadeClose: true,
                content: url,
                end: function () {
                    if (window._catPopupSaved) {
                        window._catPopupSaved = false;
                        treeTable.reloadData('catTableId', {
                            where: {_action: 'list', keyword: $('#catSearchKeyword').val() || ''}
                        });
                    }
                }
            });
        }
    });
});
</script>
