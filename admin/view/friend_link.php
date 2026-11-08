<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<div class="admin-page">
    <h1 class="admin-page__title">友情链接</h1>
<table id="linkTable" lay-filter="linkTable"></table>
</div>

<!-- 头部工具栏 -->
<script type="text/html" id="linkToolbarTpl">
    <div class="em-table-toolbar">
        <div class="em-table-toolbar__actions layui-btn-container">
            <a class="em-btn em-reset-btn" id="linkRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
            <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加友链</a>
            <a class="em-btn em-red-btn em-disabled-btn" lay-event="batchDelete"><i class="fa fa-trash"></i>批量删除</a>
        </div>
        <form class="em-quick-search" id="linkQuickSearchForm" autocomplete="off">
            <i class="fa fa-search em-quick-search__ico"></i>
            <input type="search" id="linkSearchKeyword" placeholder="搜索名称 / 地址 / 描述，回车" enterkeyhint="search">
            <button type="button" class="em-quick-search__clear" id="linkQuickClear" title="清空"><i class="fa fa-times"></i></button>
        </form>
    </div>
</script>

<!-- 行内操作按钮 -->
<script type="text/html" id="linkRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        <a class="em-btn em-sm-btn em-red-btn" lay-event="del"><i class="fa fa-trash"></i>删除</a>
    </div>
</script>

<!-- 图片模板（点击 Viewer.js 预览） -->
<script type="text/html" id="linkImageTpl">
    {{# if(d.image){ }}
    <img src="{{d.image}}" class="link-image" lay-event="previewImg">
    {{# } else { }}
    <span class="em-tag em-tag--muted"><i class="fa fa-link" style="margin-right:3px;"></i>无图</span>
    {{# } }}
</script>

<!-- 地址模板（新窗口外链 + 省略） -->
<script type="text/html" id="linkUrlTpl">
    <a href="{{d.url}}" target="_blank" class="admin-link-cell" title="{{d.url}}" onclick="event.stopPropagation();">{{d.url}}</a>
</script>

<!-- 过期时间模板：永久 / 已过期 / 具体时间 -->
<script type="text/html" id="linkExpireTpl">
    {{# if(!d.expire_time || d.expire_time === '0000-00-00 00:00:00'){ }}
    <span class="em-tag em-tag--blue"><span class="em-tag__dot"></span>永久</span>
    {{# } else { }}
    {{# var now = new Date(); var expire = new Date(d.expire_time); if(expire < now){ }}
    <span class="em-tag em-tag--red" title="已于 {{d.expire_time}} 过期"><span class="em-tag__dot"></span>已过期</span>
    {{# } else { }}
    <span style="color:#374151;">{{d.expire_time}}</span>
    {{# } }}
    {{# } }}
</script>

<!-- 状态：点击即切换 -->
<script type="text/html" id="linkStatusTpl">
    {{# if(d.enabled === 'y'){ }}
    <span class="em-tag em-tag--on em-tag--clickable" lay-event="toggleStatus" title="点击禁用">
        <span class="em-tag__dot"></span>启用
    </span>
    {{# } else { }}
    <span class="em-tag em-tag--muted em-tag--clickable" lay-event="toggleStatus" title="点击启用">
        <span class="em-tag__dot"></span>禁用
    </span>
    {{# } }}
</script>

<style>
.link-image {
    width: 48px; height: 28px; object-fit: cover;
    border-radius: 4px; border: 1px solid #e5e7eb;
    display: block; margin: 0 auto; cursor: pointer;
    transition: transform .15s ease, border-color .15s ease;
}
.link-image:hover { transform: scale(1.08); border-color: #6366f1; }
</style>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admFriendLink handler，避免事件成倍触发
    $(document).off('.admFriendLink');
    $(window).off('.admFriendLink');

    'use strict';

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var tableIns;

    function updateCsrf(token) {
        if (token) csrfToken = token;
    }
    window.updateCsrf = updateCsrf;

    layui.use(['layer', 'form', 'table'], function () {
        var linkSearchKeywordCache = '';
        var layer = layui.layer;
        var form = layui.form;
        var table = layui.table;

        // 渲染表格
        tableIns = table.render({
            elem: '#linkTable',
            id: 'linkTableId',
            url: '/admin/friend_link.php',
            headers: {csrf: csrfToken},
            method: 'POST',
            where: {_action: 'list'},
            page: false,
            toolbar: '#linkToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 55px;',
            initSort: {field: 'sort', type: 'desc'},
            cols: [[
                {type: 'checkbox', width: 50},
                {title: '图片', width: 90, templet: '#linkImageTpl', align: 'center'},
                {field: 'name', title: '名称', minWidth: 140, align: 'left'},
                {field: 'url', title: '地址', minWidth: 200, templet: '#linkUrlTpl'},
                {field: 'expire_time', title: '过期时间', width: 180, templet: '#linkExpireTpl', align: 'center'},
                {field: 'sort', title: '排序', width: 80, align: 'center'},
                {field: 'enabled', title: '状态', width: 100, templet: '#linkStatusTpl', align: 'center'},
                {title: '操作', width: 200, templet: '#linkRowActionTpl', align: 'center'}
            ]],
            done: function () {
                $('#linkSearchKeyword').val(linkSearchKeywordCache);
            },
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

        // 复选框联动批量删除按钮启用态
        table.on('checkbox(linkTable)', function () {
            var checked = table.checkStatus('linkTableId').data.length > 0;
            $('[lay-event="batchDelete"]').toggleClass('em-disabled-btn', !checked);
        });
        $(document).on('input', '#linkSearchKeyword', function () { linkSearchKeywordCache = $(this).val(); });

        // 快捷搜索（回车触发 / 清空按钮）
        function doQuickSearch() {
            table.reload('linkTableId', {
                page: {curr: 1},
                where: { _action: 'list', keyword: $.trim($('#linkSearchKeyword').val() || '') }
            });
        }
        $(document).on('em:search.admFriendLink', '#linkQuickSearchForm', function () {
            doQuickSearch();
        });
        $(document).on('click.admFriendLink', '#linkQuickClear', function () {
            linkSearchKeywordCache = '';
            $('#linkSearchKeyword').val('').focus();
            doQuickSearch();
        });

        // 刷新
        $(document).on('click.admFriendLink', '#linkRefreshBtn', function () {
            table.reload('linkTableId');
        });

        // 行内事件（编辑/删除/预览图片/切换状态）
        table.on('tool(linkTable)', function (obj) {
            var data = obj.data;
            switch (obj.event) {
                case 'previewImg':
                    // Viewer.js 预览（admin/view/index.php 已全局加载）
                    var src = data.image;
                    if (!src) return;
                    var $tmp = $('<div style="display:none;"><img src="' + src + '"></div>').appendTo('body');
                    var viewer = new Viewer($tmp[0], {
                        navbar: false, title: false, toolbar: true,
                        hidden: function () { viewer.destroy(); $tmp.remove(); }
                    });
                    viewer.show();
                    break;
                case 'edit':
                    openPopup('编辑友链', data.id);
                    break;
                case 'del':
                    layer.confirm('确定要删除友链「' + emEsc(data.name) + '」吗？此操作不可恢复。', function (idx) {
                        $.ajax({
                            url: '/admin/friend_link.php',
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
                case 'toggleStatus':
                    // 点击启用/禁用标签：调 toggle，成功后原地切换标签样式
                    var $tag = $(this);
                    if ($tag.hasClass('is-loading')) return;
                    $tag.addClass('is-loading');
                    $.ajax({
                        url: '/admin/friend_link.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {csrf_token: csrfToken, _action: 'toggle', id: data.id},
                        success: function (res) {
                            if (res.code === 200) {
                                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                if ($tag.hasClass('em-tag--on')) {
                                    $tag.removeClass('em-tag--on').addClass('em-tag--muted')
                                        .attr('title', '点击启用')
                                        .html('<span class="em-tag__dot"></span>禁用');
                                } else {
                                    $tag.removeClass('em-tag--muted').addClass('em-tag--on')
                                        .attr('title', '点击禁用')
                                        .html('<span class="em-tag__dot"></span>启用');
                                }
                                layer.msg(res.msg || '状态已更新');
                            } else {
                                layer.msg(res.msg || '更新失败');
                            }
                        },
                        error: function () { layer.msg('网络异常'); },
                        complete: function () { $tag.removeClass('is-loading'); }
                    });
                    break;
            }
        });

        // 头部工具栏（添加 / 批量删除）
        table.on('toolbar(linkTable)', function (obj) {
            switch (obj.event) {
                case 'add':
                    openPopup('添加友链');
                    break;
                case 'batchDelete':
                    batchDelete();
                    break;
            }
        });

        // 批量删除
        function batchDelete() {
            var checkStatus = table.checkStatus('linkTableId');
            var data = checkStatus.data;
            if (data.length === 0) {
                layer.msg('请先选择要删除的友链');
                return;
            }
            var ids = data.map(function(item) { return item.id; });
            var names = data.map(function(item) { return item.name; }).join('、');
            if (names.length > 100) names = names.substring(0, 100) + '...';

            layer.confirm('确定要删除以下 ' + data.length + ' 条友链吗？<br><span style="color:#9ca3af;font-size:12px;">' + emEsc(names) + '</span>', function (idx) {
                var loadIndex = layer.load(1);
                $.ajax({
                    url: '/admin/friend_link.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { csrf_token: csrfToken, _action: 'batchDelete', ids: ids.join(',') },
                    success: function (res) {
                        if (res.code === 200) {
                            csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                            layer.msg(res.msg || '删除成功');
                            table.reload('linkTableId');
                        } else {
                            layer.msg(res.msg || '删除失败');
                        }
                    },
                    error: function () { layer.msg('网络异常'); },
                    complete: function () { layer.close(loadIndex); layer.close(idx); }
                });
            });
        }

        // 打开弹窗
        function openPopup(title, editId) {
            var url = '/admin/friend_link.php?_popup=1';
            if (editId) url += '&id=' + encodeURIComponent(editId);
            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 800 ? '520px' : '95%', window.innerHeight >= 820 ? '800px' : '90%'],
                shadeClose: true,
                content: url,
                end: function () {
                    if (window._linkPopupSaved) {
                        window._linkPopupSaved = false;
                        table.reload('linkTableId');
                    }
                }
            });
        }
    });
});
</script>
