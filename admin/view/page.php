<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<!-- 选项卡：按发布状态筛选（em-tabs 同款，带动态计数徽章） -->
<div class="em-tabs" id="pageTabs">
    <a class="em-tabs__item is-active" data-status=""><i class="fa fa-file-text"></i>全部页面<em class="em-tabs__count" id="tabCountAll"></em></a>
    <a class="em-tabs__item" data-status="1"><i class="fa fa-eye"></i>已发布<em class="em-tabs__count" id="tabCountPublished"></em></a>
    <a class="em-tabs__item" data-status="0"><i class="fa fa-eye-slash"></i>草稿箱<em class="em-tabs__count" id="tabCountDraft"></em></a>
</div>

<div class="admin-page">
    <h1 class="admin-page__title">页面管理</h1>
<table id="pageTable" lay-filter="pageTable"></table>
</div>

<!-- 工具栏模板 -->
<script type="text/html" id="pageToolbarTpl">
    <div class="em-table-toolbar">
        <div class="em-table-toolbar__actions layui-btn-container">
            <a class="em-btn em-reset-btn" id="pageRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
            <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加页面</a>
            <a class="em-btn em-red-btn em-disabled-btn" lay-event="batchDelete"><i class="fa fa-trash"></i>批量删除</a>
            <a class="em-btn em-reset-btn em-disabled-btn" id="pageStatusDropdownBtn"><i class="fa fa-eye"></i>发布/草稿 <i class="layui-icon layui-icon-down layui-font-12"></i></a>
        </div>
        <form class="em-quick-search" id="pageQuickSearchForm" autocomplete="off">
            <i class="fa fa-search em-quick-search__ico"></i>
            <input type="search" id="pageSearchKeyword" placeholder="搜索标题或 slug，回车" enterkeyhint="search">
            <button type="button" class="em-quick-search__clear" id="pageQuickClear" title="清空"><i class="fa fa-times"></i></button>
        </form>
    </div>
</script>

<!-- 标题：is_homepage=1 时附"首页"角标 -->
<script type="text/html" id="pageTitleTpl">
    {{# if (d.is_homepage == 1) { }}<span class="em-tag em-tag--home" style="background:#fef3c7;color:#a16207;border:1px solid #fde68a;padding:1px 7px;border-radius:9px;font-size:11px;margin-right:6px;"><i class="fa fa-home"></i> 站点首页</span>{{# } }}
    <span style="font-weight:500;color:#1f2937;">{{d.title}}</span>
</script>

<!-- 行内操作按钮 -->
<script type="text/html" id="pageRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        <a class="em-btn em-sm-btn em-purple-btn" lay-event="preview" title="前台预览"><i class="fa fa-external-link"></i>预览</a>
        {{# if (d.is_homepage == 1) { }}
        <a class="em-btn em-sm-btn em-warm-btn" lay-event="clearHomepage" title="取消站点首页"><i class="fa fa-home"></i>取消首页</a>
        {{# } else { }}
        <a class="em-btn em-sm-btn" lay-event="setHomepage" title="设为站点首页"><i class="fa fa-home"></i>设为首页</a>
        {{# } }}
        <a class="em-btn em-sm-btn em-red-btn" lay-event="delete"><i class="fa fa-trash"></i>删除</a>
    </div>
</script>

<!-- URL 别名：等宽字体 + 灰色 code 背板 -->
<script type="text/html" id="pageSlugTpl">
    <code style="background:#f3f4f6;border:1px solid #e5e7eb;border-radius:4px;padding:1px 6px;font-family:Menlo,Consolas,monospace;font-size:12.5px;color:#374151;">/p/{{d.slug}}</code>
</script>

<!-- 模板名：空时显"通用" -->
<script type="text/html" id="pageTemplateTpl">
    {{# if(d.template_name){ }}
    <span class="em-tag em-tag--purple">{{d.template_name}}</span>
    {{# } else { }}
    <span class="em-tag em-tag--muted">通用</span>
    {{# } }}
</script>

<!-- 状态：点击即切换（em-tag 同商品/博客页） -->
<script type="text/html" id="pageStatusTpl">
    {{# if(d.status == 1){ }}
    <span class="em-tag em-tag--on em-tag--clickable" lay-event="toggleStatus" title="点击转草稿">
        <span class="em-tag__dot"></span>已发布
    </span>
    {{# } else { }}
    <span class="em-tag em-tag--muted em-tag--clickable" lay-event="toggleStatus" title="点击发布">
        <span class="em-tag__dot"></span>草稿
    </span>
    {{# } }}
</script>

<!-- 时间：日期加粗 + 时间浅色等宽（和博客列表同风格） -->
<script type="text/html" id="pageTimeTpl">
    {{# if(d.updated_at){ }}
    {{# var dt = d.updated_at.replace('T', ' ').substring(0, 19); var parts = dt.split(' '); }}
    <span style="display:inline-flex;flex-direction:column;align-items:center;line-height:1.3;">
        <span style="color:#374151;font-weight:500;font-size:12.5px;">{{parts[0]}}</span>
        <span style="color:#9ca3af;font-size:11.5px;font-family:Menlo,Consolas,monospace;">{{parts[1] || ''}}</span>
    </span>
    {{# } else { }}
    <span class="em-tag em-tag--muted">无</span>
    {{# } }}
</script>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admPage handler，避免事件成倍触发
    $(document).off('.admPage');
    $(window).off('.admPage');

    'use strict';

    var csrfToken = <?= json_encode($csrfToken) ?>;
    var table, dropdown;

    function updateCsrf(token) { if (token) csrfToken = token; }
    window.updateCsrf = updateCsrf;

    layui.use(['layer', 'form', 'table', 'dropdown'], function () {
        var pageSearchKeywordCache = '';
        var layer = layui.layer;
        var form = layui.form;
        table = layui.table;
        dropdown = layui.dropdown;

        var currentStatusTab = '';

        table.render({
            elem: '#pageTable',
            id: 'pageTableId',
            url: '/admin/page.php?_action=list',
            method: 'POST',
            toolbar: '#pageToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 60px;',
            page: true,
            limit: 20,
            limits: [10, 20, 50, 100],
            cellMinWidth: 80,
            cols: [[
                {type: 'checkbox', width: 50},
                {field: 'title', title: '页面标题', minWidth: 240, templet: '#pageTitleTpl'},
                {field: 'slug', title: 'URL 别名', minWidth: 180, templet: '#pageSlugTpl'},
                {field: 'template_name', title: '模板', width: 140, templet: '#pageTemplateTpl', align: 'center'},
                {field: 'views_count', title: '阅读', width: 80, align: 'center'},
                {field: 'sort', title: '排序', width: 80, align: 'center', sort: true},
                {field: 'status', title: '状态', width: 110, templet: '#pageStatusTpl', align: 'center', unresize: true},
                {field: 'updated_at', title: '更新时间', width: 130, templet: '#pageTimeTpl', align: 'center', sort: true},
                {title: '操作', width: 340, align: 'center', toolbar: '#pageRowActionTpl'}
            ]],
            done: function (res) {
                $('#pageSearchKeyword').val(pageSearchKeywordCache);
                if (res.csrf_token) csrfToken = res.csrf_token;
                if (res.tab_counts) {
                    $('#tabCountAll').text(res.tab_counts.all || '');
                    $('#tabCountPublished').text(res.tab_counts.published || '');
                    $('#tabCountDraft').text(res.tab_counts.draft || '');
                }
                initToolbarDropdowns();
            }
        });

        // 工具栏下拉菜单（批量发布/转草稿）
        function initToolbarDropdowns() {
            dropdown.render({
                elem: '#pageStatusDropdownBtn',
                data: [
                    {title: '批量发布', templet: '<i class="fa fa-eye"></i> {{= d.title }}', id: 'batchPublish'},
                    {title: '批量转草稿', templet: '<i class="fa fa-eye-slash"></i> {{= d.title }}', id: 'batchDraft'}
                ],
                click: function(obj) {
                    if ($('#pageStatusDropdownBtn').hasClass('em-disabled-btn')) return;
                    var data = table.checkStatus('pageTableId').data;
                    if (data.length === 0) { layer.msg('请选择页面'); return; }
                    batchAction(obj.id === 'batchPublish' ? 'publish' : 'draft', data);
                }
            });
        }
        initToolbarDropdowns();

        // em-tabs 切换
        $('#pageTabs').on('click', '.em-tabs__item', function () {
            var $item = $(this);
            if ($item.hasClass('is-active')) return;
            $item.addClass('is-active').siblings().removeClass('is-active');
            currentStatusTab = $item.data('status');
            if (currentStatusTab === undefined) currentStatusTab = '';
            table.reload('pageTableId', {
                where: {
                    keyword: $('#pageSearchKeyword').val() || '',
                    status: currentStatusTab === '' ? '' : String(currentStatusTab)
                },
                page: {curr: 1}
            });
        });

        // 勾选联动
        table.on('checkbox(pageTable)', function () {
            var checked = table.checkStatus('pageTableId').data.length > 0;
            $('[lay-event="batchDelete"], #pageStatusDropdownBtn').toggleClass('em-disabled-btn', !checked);
        });
        $(document).on('input', '#pageSearchKeyword', function () { pageSearchKeywordCache = $(this).val(); });

        // 快捷搜索（回车 / 清空）
        function doQuickSearch() {
            table.reload('pageTableId', {
                where: {
                    keyword: $.trim($('#pageSearchKeyword').val() || ''),
                    status: currentStatusTab === '' ? '' : String(currentStatusTab)
                },
                page: {curr: 1}
            });
        }
        $(document).on('em:search.admPage', '#pageQuickSearchForm', function () {
            doQuickSearch();
        });
        $(document).on('click.admPage', '#pageQuickClear', function () {
            pageSearchKeywordCache = '';
            $('#pageSearchKeyword').val('').focus();
            doQuickSearch();
        });
        $(document).on('click.admPage', '#pageRefreshBtn', function () {
            table.reload('pageTableId');
        });

        // 工具栏事件
        table.on('toolbar(pageTable)', function (obj) {
            var data = table.checkStatus('pageTableId').data;
            if (obj.event === 'add') {
                openEditPopup('添加页面');
            } else if (obj.event === 'batchDelete') {
                if (data.length === 0) { layer.msg('请选择页面'); return; }
                layer.confirm('确定要删除选中的 ' + data.length + ' 个页面吗？', function (idx) {
                    batchAction('delete', data, idx);
                });
            }
        });

        // 行内事件
        table.on('tool(pageTable)', function (obj) {
            var d = obj.data;
            if (obj.event === 'edit') {
                openEditPopup('编辑页面', d.id);
            } else if (obj.event === 'preview') {
                // 前台预览新开页签
                window.open('/p/' + encodeURIComponent(d.slug), '_blank');
            } else if (obj.event === 'delete') {
                layer.confirm('确定要删除页面「' + emEsc(d.title) + '」吗？', function (idx) {
                    $.ajax({
                        url: '/admin/page.php?_action=delete',
                        type: 'POST',
                        dataType: 'json',
                        data: {csrf_token: csrfToken, id: d.id},
                        success: function (res) {
                            if (res.code === 200) {
                                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                layer.msg(res.msg || '删除成功');
                                table.reload('pageTableId');
                            } else {
                                layer.msg(res.msg || '删除失败');
                            }
                        },
                        error: function () { layer.msg('网络异常'); },
                        complete: function () { layer.close(idx); }
                    });
                });
            } else if (obj.event === 'toggleStatus') {
                toggleTag($(this), d.id);
            } else if (obj.event === 'setHomepage') {
                layer.confirm('把「' + emEsc(d.title) + '」设为主站首页？同时会替换当前已设的页面首页。', function (idx) {
                    $.ajax({
                        url: '/admin/page.php?_action=set_homepage',
                        type: 'POST', dataType: 'json',
                        data: {csrf_token: csrfToken, id: d.id},
                        success: function (res) {
                            if (res.code === 200) {
                                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                layer.msg(res.msg || '已设为首页');
                                table.reload('pageTableId');
                            } else { layer.msg(res.msg || '设置失败'); }
                        },
                        error: function () { layer.msg('网络异常'); },
                        complete: function () { layer.close(idx); }
                    });
                });
            } else if (obj.event === 'clearHomepage') {
                $.ajax({
                    url: '/admin/page.php?_action=clear_homepage',
                    type: 'POST', dataType: 'json',
                    data: {csrf_token: csrfToken, id: d.id},
                    success: function (res) {
                        if (res.code === 200) {
                            csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                            layer.msg(res.msg || '已取消首页');
                            table.reload('pageTableId');
                        } else { layer.msg(res.msg || '取消失败'); }
                    },
                    error: function () { layer.msg('网络异常'); }
                });
            }
        });

        // 点击状态标签：toggle，成功后原地切换 class，不 reload
        function toggleTag($tag, id) {
            if ($tag.hasClass('is-loading')) return;
            $tag.addClass('is-loading');
            $.ajax({
                url: '/admin/page.php?_action=toggle_status',
                type: 'POST',
                dataType: 'json',
                data: {csrf_token: csrfToken, id: id},
                success: function (res) {
                    if (res.code === 200) {
                        csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                        if ($tag.hasClass('em-tag--on')) {
                            $tag.removeClass('em-tag--on').addClass('em-tag--muted')
                                .attr('title', '点击发布')
                                .html('<span class="em-tag__dot"></span>草稿');
                        } else {
                            $tag.removeClass('em-tag--muted').addClass('em-tag--on')
                                .attr('title', '点击转草稿')
                                .html('<span class="em-tag__dot"></span>已发布');
                        }
                        layer.msg(res.msg || '操作成功');
                    } else {
                        layer.msg(res.msg || '操作失败');
                    }
                },
                error: function () { layer.msg('网络异常'); },
                complete: function () { $tag.removeClass('is-loading'); }
            });
        }

        // 批量操作
        function batchAction(action, data, closeIdx) {
            var ids = data.map(function(item) { return item.id; });
            $.ajax({
                url: '/admin/page.php?_action=batch',
                type: 'POST',
                dataType: 'json',
                data: {csrf_token: csrfToken, batch_action: action, ids: ids},
                success: function (res) {
                    if (res.code === 200) {
                        csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                        layer.msg(res.msg || '操作成功');
                        table.reload('pageTableId');
                    } else {
                        layer.msg(res.msg || '操作失败');
                    }
                },
                error: function () { layer.msg('网络异常'); },
                complete: function () { if (closeIdx) layer.close(closeIdx); }
            });
        }

        // 打开编辑弹窗
        function openEditPopup(title, editId) {
            var url = '/admin/page_edit.php?_popup=1';
            if (editId) url += '&id=' + encodeURIComponent(editId);
            window._pagePopupSaved = false;
            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: ['820px', '92%'],
                shadeClose: true,
                content: url,
                end: function () {
                    if (window._pagePopupSaved) {
                        window._pagePopupSaved = false;
                        table.reload('pageTableId');
                    }
                }
            });
        }
    });
});
</script>
