<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<div class="admin-page">
    <h1 class="admin-page__title">语言列表</h1>

    <!-- 表格容器 -->
    <table id="langTable" lay-filter="langTable"></table>
</div>

<!-- 行工具栏模板 -->
<script type="text/html" id="langToolbarTpl">
    <div class="layui-btn-container">
        <a class="em-btn em-reset-btn" id="langRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加语言</a>
    </div>
</script>

<!-- 行内操作按钮模板 -->
<script type="text/html" id="langRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        <a class="em-btn em-sm-btn em-red-btn" lay-event="del"><i class="fa fa-trash"></i>删除</a>
    </div>
</script>

<!-- 状态：点击即切换 -->
<script type="text/html" id="langStatusTpl">
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

<!-- 语言名称模板（名称 + 默认标签） -->
<script type="text/html" id="langNameTpl">
    <span style="display:inline-flex;align-items:center;gap:6px;">
        <span style="font-weight:500;color:#374151;">{{d.name}}</span>
        {{# if(d.is_default === 'y'){ }}
        <span class="em-tag em-tag--blue">默认</span>
        {{# } }}
    </span>
</script>

<!-- 语言图标模板 -->
<script type="text/html" id="langIconTpl">
    {{# if(d.icon){ }}
    <img src="{{d.icon}}" class="lang-icon-img" lay-tips="点击放大">
    {{# } else { }}
    <i class="fa fa-globe" style="color:#d1d5db;font-size:18px;display:block;text-align:center;"></i>
    {{# } }}
</script>

<style>
.lang-icon-img {
    width: 32px; height: 22px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid #e5e7eb;
    display: block;
    margin: 0 auto;
    cursor: pointer;
    transition: transform .15s ease, border-color .15s ease;
}
.lang-icon-img:hover { transform: scale(1.1); border-color: #6366f1; }
</style>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admLanguage handler，避免事件成倍触发
    $(document).off('.admLanguage');
    $(window).off('.admLanguage');

    'use strict';

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var tableIns;

    function updateCsrf(token) { if (token) csrfToken = token; }
    window.updateCsrf = updateCsrf;

    layui.use(['layer', 'form', 'table'], function () {
        var layer = layui.layer;
        var form = layui.form;
        var table = layui.table;

        // 渲染表格（语言条目少，不分页）
        tableIns = table.render({
            elem: '#langTable',
            id: 'langTableId',
            url: '/admin/language.php',
            headers: {csrf: csrfToken},
            method: 'POST',
            where: {_action: 'list'},
            page: false,
            toolbar: '#langToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 55px;',
            initSort: {field: 'id', type: 'asc'},
            cols: [[
                {title: '序号', width: 70, align: 'center', type: 'numbers'},
                {field: 'icon', title: '图标', width: 80, templet: '#langIconTpl', align: 'center'},
                {field: 'name', title: '语言名称', minWidth: 160, templet: '#langNameTpl', align: 'left'},
                {field: 'code', title: '语言代码', width: 140, align: 'center'},
                {field: 'enabled', title: '状态', width: 100, templet: '#langStatusTpl', align: 'center'},
                {title: '操作', width: 200, templet: '#langRowActionTpl', align: 'center'}
            ]],
            done: function (res) {
                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
            },
            parseData: function (res) {
                if (res.data && res.data.csrf_token) csrfToken = res.data.csrf_token;
                return {
                    'code': res.code === 200 ? 0 : res.code,
                    'msg': res.msg,
                    'data': res.data ? res.data.data : [],
                    'count': res.data ? res.data.total : 0
                };
            }
        });

        // 刷新
        $(document).on('click.admLanguage', '#langRefreshBtn', function () {
            table.reload('langTableId');
        });

        // 头部工具栏
        table.on('toolbar(langTable)', function (obj) {
            if (obj.event === 'add') openPopup('添加语言');
        });

        // 行内事件（编辑 / 删除 / 切换状态 / 图标预览）
        table.on('tool(langTable)', function (obj) {
            var data = obj.data;
            switch (obj.event) {
                case 'edit':
                    openPopup('编辑语言', data.id);
                    break;
                case 'del':
                    layer.confirm('确定要删除语言「' + emEsc(data.name) + '」吗？该操作将同时删除所有相关翻译词条，不可恢复。', function (idx) {
                        $.ajax({
                            url: '/admin/language.php',
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
                    var $tag = $(this);
                    if ($tag.hasClass('is-loading')) return;
                    $tag.addClass('is-loading');
                    $.ajax({
                        url: '/admin/language.php',
                        type: 'POST',
                        dataType: 'json',
                        data: {csrf_token: csrfToken, _action: 'toggle', id: data.id},
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
                                layer.msg(res.msg || '更新失败');
                            }
                        },
                        error: function () { layer.msg('网络异常'); },
                        complete: function () { $tag.removeClass('is-loading'); }
                    });
                    break;
            }
        });

        // 图标点击放大：Viewer.js（admin/view/index.php 已全局加载）
        $(document).on('click.admLanguage', '.lang-icon-img', function () {
            var src = $(this).attr('src');
            if (!src) return;
            var $tmp = $('<div style="display:none;"><img src="' + src + '"></div>').appendTo('body');
            var viewer = new Viewer($tmp[0], {
                navbar: false, title: false, toolbar: true,
                hidden: function () { viewer.destroy(); $tmp.remove(); }
            });
            viewer.show();
        });

        // 打开弹窗
        function openPopup(title, editId) {
            var url = '/admin/language.php?_popup=1';
            if (editId) url += '&id=' + encodeURIComponent(editId);
            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 800 ? '520px' : '95%', window.innerHeight >= 750 ? '660px' : '90%'],
                shadeClose: true,
                content: url,
                end: function () {
                    if (window._langPopupSaved) {
                        window._langPopupSaved = false;
                        table.reload('langTableId');
                    }
                }
            });
        }
    });
});
</script>
