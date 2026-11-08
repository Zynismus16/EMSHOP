<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<!-- 搜索条件（em-filter 风格，和商品标签管理一致） -->
<div class="em-filter" id="tagFilter">
    <div class="em-filter__head" id="tagFilterHead">
        <span class="em-filter__title"><i class="fa fa-filter"></i>搜索条件</span>
        <span class="em-filter__toggle"><i class="fa fa-angle-down"></i><span class="em-filter__toggle-text">展开</span></span>
    </div>
    <div class="em-filter__body">
        <form class="em-filter__form" data-em-search-btn="#tagSearchBtn" autocomplete="off">
        <div class="em-filter__grid">
            <div class="em-filter__field">
                <label>标签名</label>
                <input type="search" id="tagSearchKeyword" placeholder="搜索标签名" enterkeyhint="search">
            </div>
        </div>
        <div class="em-filter__actions">
            <button type="button" class="em-btn em-reset-btn" id="tagResetBtn"><i class="fa fa-undo mr-5"></i>重置</button>
            <button type="button" class="em-btn em-save-btn" id="tagSearchBtn"><i class="fa fa-search mr-5"></i>搜索</button>
        </div>
        </form>
    </div>
</div>

<div class="admin-page">
    <h1 class="admin-page__title">标签管理</h1>
    <table id="tagTable" lay-filter="tagTable"></table>
</div>

<!-- 工具栏模板（em-btn 体系；计数在文章保存/状态切换/删除时自动刷新，不再需要手动按钮） -->
<script type="text/html" id="tagToolbarTpl">
    <div class="layui-btn-container">
        <a class="em-btn em-reset-btn" id="tagRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <a class="em-btn em-save-btn" id="tagAddBtn"><i class="fa fa-plus-circle"></i>新增标签</a>
        <a class="em-btn em-red-btn em-disabled-btn" lay-event="batchDelete"><i class="fa fa-trash"></i>批量删除</a>
    </div>
</script>

<!-- 行内操作按钮模板 -->
<script type="text/html" id="tagRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        <a class="em-btn em-sm-btn em-red-btn" lay-event="delete"><i class="fa fa-trash"></i>删除</a>
    </div>
</script>

<!-- 编辑弹窗模板 -->
<script type="text/html" id="tagFormTpl">
    <div style="padding:20px 30px 0;">
        <form class="layui-form" lay-filter="tagForm">
            <input type="hidden" name="id" value="">
            <div class="layui-form-item">
                <label class="layui-form-label">标签名 <span style="color:red;">*</span></label>
                <div class="layui-input-block">
                    <input type="text" name="name" lay-verify="required" placeholder="请输入标签名" class="layui-input" autocomplete="off">
                </div>
            </div>
            <div class="layui-form-item">
                <label class="layui-form-label">排序</label>
                <div class="layui-input-block">
                    <input type="number" name="sort" value="0" placeholder="数字越小越靠前" class="layui-input">
                </div>
            </div>
            <div class="layui-form-item" style="text-align:right; padding-top:10px; border-top:1px solid #f0f0f0;">
                <button type="button" class="em-btn em-reset-btn" id="tagFormCancel"><i class="fa fa-times mr-5"></i>取消</button>
                <button type="button" class="em-btn em-save-btn" lay-submit lay-filter="tagFormSubmit"><i class="fa fa-check mr-5"></i>保存</button>
            </div>
        </form>
    </div>
</script>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admBlogTag handler，避免事件成倍触发
    $(document).off('.admBlogTag');
    $(window).off('.admBlogTag');

    'use strict';

    var csrfToken = <?= json_encode($csrfToken) ?>;

    function updateCsrf(token) {
        if (token) csrfToken = token;
    }

    layui.use(['layer', 'form', 'table'], function () {
        var layer = layui.layer;
        var form = layui.form;
        var table = layui.table;

        // em-filter 展开/收起（和其他列表页一致）
        var $filter = $('#tagFilter');
        var filterOpenKey = 'blog_tag_filter_open';
        function setFilterOpen(open) {
            $filter.toggleClass('is-open', open);
            $('.em-filter__toggle-text', $filter).text(open ? '收起' : '展开');
            localStorage.setItem(filterOpenKey, open ? 'y' : 'n');
        }
        setFilterOpen(localStorage.getItem(filterOpenKey) === 'y');
        $('#tagFilterHead').on('click', function () {
            setFilterOpen(!$filter.hasClass('is-open'));
        });

        // ============================================================
        // 渲染表格
        // ============================================================
        table.render({
            elem: '#tagTable',
            id: 'tagTableId',
            url: '/admin/blog_tag.php?_action=list',
            method: 'POST',
            toolbar: '#tagToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 55px;',
            page: true,
            limit: 10,
            limits: [10, 20, 50, 100],
            cellMinWidth: 80,
            cols: [[
                {type: 'checkbox', width: 50},
                {field: 'id', title: 'ID', width: 80, align: 'center', sort: true},
                {field: 'name', title: '标签名', minWidth: 200},
                {field: 'article_count', title: '文章数', width: 100, align: 'center', sort: true},
                {field: 'sort', title: '排序', width: 80, align: 'center'},
                {field: 'created_at', title: '创建时间', width: 170, align: 'center'},
                {title: '操作', width: 200, align: 'center', toolbar: '#tagRowActionTpl'}
            ]],
            done: function (res) {
                if (res.csrf_token) csrfToken = res.csrf_token;
            }
        });

        // ============================================================
        // 搜索 / 重置 / 刷新
        // ============================================================
        $(document).on('click.admBlogTag', '#tagSearchBtn', function () {
            table.reload('tagTableId', {
                where: { keyword: $('#tagSearchKeyword').val() || '' },
                page: {curr: 1}
            });
        });
        $(document).on('click.admBlogTag', '#tagResetBtn', function () {
            $('#tagSearchKeyword').val('');
            table.reload('tagTableId', { where: { keyword: '' }, page: {curr: 1} });
        });
        $(document).on('click.admBlogTag', '#tagRefreshBtn', function () {
            table.reload('tagTableId');
        });

        // 复选框联动：em-disabled-btn 类切换
        table.on('checkbox(tagTable)', function () {
            var checked = table.checkStatus('tagTableId').data.length > 0;
            $('[lay-event="batchDelete"]').toggleClass('em-disabled-btn', !checked);
        });

        // ============================================================
        // 新增 / 编辑弹窗
        // ============================================================
        var editLayerIdx = null;
        function openEditForm(data) {
            editLayerIdx = layer.open({
                type: 1,
                title: data ? '编辑标签' : '新增标签',
                area: ['420px', '280px'],
                content: $('#tagFormTpl').html(),
                success: function (layero) {
                    if (data) {
                        layero.find('[name="id"]').val(data.id);
                        layero.find('[name="name"]').val(data.name);
                        layero.find('[name="sort"]').val(data.sort || 0);
                    }
                    form.render('select');
                }
            });
        }

        $(document).on('click.admBlogTag', '#tagAddBtn', function () { openEditForm(null); });
        $(document).on('click.admBlogTag', '#tagFormCancel', function () {
            if (editLayerIdx !== null) layer.close(editLayerIdx);
        });

        // 提交表单
        form.on('submit(tagFormSubmit)', function (obj) {
            obj.field.csrf_token = csrfToken;
            $.ajax({
                url: '/admin/blog_tag.php?_action=save',
                type: 'POST',
                dataType: 'json',
                data: obj.field,
                success: function (res) {
                    updateCsrf(res.data && res.data.csrf_token);
                    if (res.code === 200) {
                        layer.msg('保存成功');
                        if (editLayerIdx !== null) layer.close(editLayerIdx);
                        table.reload('tagTableId');
                    } else {
                        layer.msg(res.msg || '保存失败');
                    }
                },
                error: function () { layer.msg('网络异常'); }
            });
            return false;
        });

        // ============================================================
        // 批量删除
        // ============================================================
        table.on('toolbar(tagTable)', function (obj) {
            if (obj.event !== 'batchDelete') return;
            var checkStatus = table.checkStatus('tagTableId');
            var data = checkStatus.data;
            if (data.length === 0) { layer.msg('请选择标签'); return; }
            layer.confirm('确定要删除选中的 ' + data.length + ' 个标签吗？', function (idx) {
                var ids = data.map(function(item) { return item.id; });
                $.ajax({
                    url: '/admin/blog_tag.php?_action=batch_delete',
                    type: 'POST',
                    dataType: 'json',
                    data: {csrf_token: csrfToken, ids: ids},
                    success: function (res) {
                        updateCsrf(res.data && res.data.csrf_token);
                        if (res.code === 200) {
                            layer.msg(res.msg || '删除成功');
                            table.reload('tagTableId');
                        } else {
                            layer.msg(res.msg || '删除失败');
                        }
                    },
                    error: function () { layer.msg('网络异常'); },
                    complete: function () { layer.close(idx); }
                });
            });
        });

        // ============================================================
        // 行内事件
        // ============================================================
        table.on('tool(tagTable)', function (obj) {
            if (obj.event === 'edit') {
                openEditForm(obj.data);
            } else if (obj.event === 'delete') {
                layer.confirm('确定要删除标签"' + emEsc(obj.data.name) + '"吗？删除后将解除所有文章关联。', function (idx) {
                    $.ajax({
                        url: '/admin/blog_tag.php?_action=delete',
                        type: 'POST',
                        dataType: 'json',
                        data: {csrf_token: csrfToken, id: obj.data.id},
                        success: function (res) {
                            updateCsrf(res.data && res.data.csrf_token);
                            if (res.code === 200) {
                                layer.msg('删除成功');
                                table.reload('tagTableId');
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
    });
});
</script>
