<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
$languagesJson = json_encode($languages);
?>
<!-- 搜索条件（em-filter 风格，和其他列表页一致） -->
<div class="em-filter" id="langTransFilter">
    <div class="em-filter__head" id="langTransFilterHead">
        <span class="em-filter__title"><i class="fa fa-filter"></i>搜索条件</span>
        <span class="em-filter__toggle"><i class="fa fa-angle-down"></i><span class="em-filter__toggle-text">展开</span></span>
    </div>
    <div class="em-filter__body">
        <form class="em-filter__form" data-em-search-btn="#langTransSearchBtn" autocomplete="off">
        <div class="em-filter__grid">
            <div class="em-filter__field">
                <label>语言类型</label>
                <select id="langTransSearchLangType">
                    <option value="">全部语言</option>
                </select>
            </div>
            <div class="em-filter__field">
                <label>关键词</label>
                <input type="search" id="langTransSearchKeyword" placeholder="搜索翻译语句或翻译内容" enterkeyhint="search">
            </div>
        </div>
        <div class="em-filter__actions">
            <button type="button" class="em-btn em-reset-btn" id="langTransResetBtn"><i class="fa fa-undo mr-5"></i>重置</button>
            <button type="button" class="em-btn em-save-btn" id="langTransSearchBtn"><i class="fa fa-search mr-5"></i>搜索</button>
        </div>
        </form>
    </div>
</div>

<div class="admin-page">
    <h1 class="admin-page__title">翻译管理</h1>
    <table id="langTransTable" lay-filter="langTransTable"></table>
</div>

<!-- 工具栏模板 -->
<script type="text/html" id="langTransToolbarTpl">
    <div class="layui-btn-container">
        <a class="em-btn em-reset-btn" id="langTransRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加翻译</a>
        <a class="em-btn em-green-btn" lay-event="batchSave"><i class="fa fa-save"></i>保存修改</a>
        <a class="em-btn em-red-btn em-disabled-btn" lay-event="batchDelete"><i class="fa fa-trash"></i>批量删除</a>
    </div>
</script>

<!-- 行内操作按钮 -->
<script type="text/html" id="langTransRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i>编辑</a>
        <a class="em-btn em-sm-btn em-red-btn" lay-event="del"><i class="fa fa-trash"></i>删除</a>
    </div>
</script>

<!-- 语言类型：em-tag 胶囊 -->
<script type="text/html" id="langTypeTpl">
    {{# if(d.lang_name && d.lang_code){ }}
    <span class="em-tag em-tag--blue">{{d.lang_name}} ({{d.lang_code}})</span>
    {{# } else { }}
    <span class="em-tag em-tag--muted">未知</span>
    {{# } }}
</script>

<!-- 翻译值单元格模板（可内联编辑，点击文本进入输入框） -->
<script type="text/html" id="langTransValueTpl">
    <div class="lang-trans-value-cell" data-id="{{d.id}}" data-original="{{d.content}}">
        <span class="lang-trans-value-text">{{d.content}}</span>
    </div>
</script>

<style>
.lang-trans-modified { background-color: #fffbe6 !important; }
.lang-trans-value-cell {
    cursor: text;
    min-height: 20px;
    padding: 2px 4px;
    border-radius: 3px;
    transition: background-color 0.15s;
}
.lang-trans-value-cell:hover { background-color: #f0f0f0; }
.lang-trans-inline-input { width: 100%; height: 28px !important; }
</style>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admLang handler，避免事件成倍触发
    $(document).off('.admLang');
    $(window).off('.admLang');

    'use strict';

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var allLanguages = <?php echo $languagesJson; ?>;
    var tableIns;
    var modifiedRows = {}; // {id: {oldVal, newVal}}

    function updateCsrf(token) { if (token) csrfToken = token; }
    window.updateCsrf = updateCsrf;

    layui.use(['layer', 'form', 'table'], function () {
        var layer = layui.layer;
        var form = layui.form;
        var table = layui.table;

        // em-filter 展开/收起
        var $filter = $('#langTransFilter');
        var filterOpenKey = 'lang_trans_filter_open';
        function setFilterOpen(open) {
            $filter.toggleClass('is-open', open);
            $('.em-filter__toggle-text', $filter).text(open ? '收起' : '展开');
            localStorage.setItem(filterOpenKey, open ? 'y' : 'n');
        }
        // 默认展开（翻译常需要配合语言类型筛选）
        var saved = localStorage.getItem(filterOpenKey);
        setFilterOpen(saved === null ? true : saved === 'y');
        $('#langTransFilterHead').on('click', function () {
            setFilterOpen(!$filter.hasClass('is-open'));
        });

        // 渲染表格
        tableIns = table.render({
            elem: '#langTransTable',
            id: 'langTransTableId',
            url: '/admin/lang.php',
            headers: {csrf: csrfToken},
            method: 'POST',
            where: {_action: 'list'},
            page: { limit: 10, limits: [10, 20, 50, 100] },
            toolbar: '#langTransToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 55px;',
            initSort: {field: 'id', type: 'desc'},
            autoSort: false,
            cols: [[
                {type: 'checkbox', width: 50},
                {field: 'id', title: '编号', width: 80, align: 'center'},
                {field: 'translate', title: '翻译语句', minWidth: 200, align: 'left'},
                {field: 'content', title: '翻译内容', minWidth: 300, templet: '#langTransValueTpl'},
                {field: 'lang_name', title: '语言类型', width: 200, align: 'center', templet: '#langTypeTpl'},
                {title: '操作', width: 200, templet: '#langTransRowActionTpl', align: 'center'}
            ]],
            done: function (res) {
                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                modifiedRows = {};
                bindInlineEdit();
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

        // 填充语言筛选下拉框
        (function populateLangTypeSelect() {
            var $sel = $('#langTransSearchLangType');
            allLanguages.forEach(function (lang) {
                $sel.append('<option value="' + lang.id + '">' + lang.name + ' (' + lang.code + ')</option>');
            });
        })();

        // 搜索 / 重置 / 刷新
        function buildWhere() {
            return {
                _action: 'list',
                keyword: $('#langTransSearchKeyword').val() || '',
                langId: $('#langTransSearchLangType').val() || ''
            };
        }
        $(document).on('click.admLang', '#langTransSearchBtn', function () {
            table.reload('langTransTableId', { where: buildWhere(), page: {curr: 1} });
        });
        $(document).on('click.admLang', '#langTransResetBtn', function () {
            $('#langTransSearchKeyword').val('');
            $('#langTransSearchLangType').val('');
            table.reload('langTransTableId', { where: {_action: 'list'}, page: {curr: 1} });
        });
        $(document).on('click.admLang', '#langTransRefreshBtn', function () {
            table.reload('langTransTableId', { where: {_action: 'list'} });
        });

        // 勾选联动（批量删除按钮启用态）
        table.on('checkbox(langTransTable)', function () {
            var checked = table.checkStatus('langTransTableId').data.length > 0;
            $('[lay-event="batchDelete"]').toggleClass('em-disabled-btn', !checked);
        });

        // 头部工具栏
        table.on('toolbar(langTransTable)', function (obj) {
            switch (obj.event) {
                case 'add': openPopup('添加翻译'); break;
                case 'batchSave': saveAllModified(); break;
                case 'batchDelete': batchDelete(); break;
            }
        });

        // 行内事件
        table.on('tool(langTransTable)', function (obj) {
            var data = obj.data;
            switch (obj.event) {
                case 'edit':
                    openPopup('编辑翻译', data.id);
                    break;
                case 'del':
                    layer.confirm('确定要删除翻译「' + emEsc(data.translate) + '」吗？', function (idx) {
                        $.ajax({
                            url: '/admin/lang.php',
                            type: 'POST',
                            dataType: 'json',
                            data: {csrf_token: csrfToken, _action: 'delete', id: data.id},
                            success: function (res) {
                                if (res.code === 200) {
                                    csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                    layer.msg(res.msg || '删除成功');
                                    obj.del();
                                    delete modifiedRows[data.id];
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

        // 内联编辑交互：点击文本进入输入框，失焦/回车保存到 modifiedRows 待批量提交
        function bindInlineEdit() {
            $(document).off('click.langInline').on('click.langInline', '.lang-trans-value-cell', function () {
                var $cell = $(this);
                if ($cell.find('input').length > 0) return;

                var original = $cell.data('original');
                var id = $cell.data('id');
                var currentText = $cell.find('.lang-trans-value-text').text();

                var $input = $('<input type="text" class="layui-input lang-trans-inline-input">');
                $input.val(currentText);
                $cell.find('.lang-trans-value-text').hide();
                $cell.append($input);
                $input.focus().select();

                function saveInline() {
                    var newVal = $input.val();
                    $input.remove();
                    $cell.find('.lang-trans-value-text').text(newVal).show();
                    if (newVal !== original) {
                        modifiedRows[id] = { oldVal: original, newVal: newVal };
                        $cell.closest('tr').addClass('lang-trans-modified');
                    }
                }
                $input.on('blur', saveInline);
                $input.on('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); saveInline(); }
                    if (e.key === 'Escape') { $input.val(currentText); saveInline(); }
                });
            });
        }

        // 保存所有修改
        function saveAllModified() {
            var ids = Object.keys(modifiedRows);
            if (ids.length === 0) { layer.msg('没有需要保存的修改'); return; }

            var pairs = {};
            ids.forEach(function (id) { pairs[id] = modifiedRows[id].newVal; });

            var loadIndex = layer.load(1);
            $.ajax({
                url: '/admin/lang.php',
                type: 'POST',
                dataType: 'json',
                data: { csrf_token: csrfToken, _action: 'batchSave', pairs: pairs },
                success: function (res) {
                    if (res.code === 200) {
                        csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                        layer.msg(res.msg || '保存成功');
                        modifiedRows = {};
                        $('.lang-trans-modified').removeClass('lang-trans-modified');
                        table.reload('langTransTableId', { where: {_action: 'list'} });
                    } else {
                        layer.msg(res.msg || '保存失败');
                    }
                },
                error: function () { layer.msg('网络异常'); },
                complete: function () { layer.close(loadIndex); }
            });
        }

        // 批量删除
        function batchDelete() {
            var checkStatus = table.checkStatus('langTransTableId');
            var data = checkStatus.data;
            if (data.length === 0) { layer.msg('请先选择要删除的翻译'); return; }

            var ids = data.map(function(item) { return item.id; });
            var translates = data.map(function(item) { return item.translate; }).join('、');
            if (translates.length > 100) translates = translates.substring(0, 100) + '...';

            layer.confirm('确定要删除以下 ' + data.length + ' 条翻译吗？<br><span style="color:#9ca3af;font-size:12px;">' + emEsc(translates) + '</span>', function (idx) {
                var loadIndex = layer.load(1);
                $.ajax({
                    url: '/admin/lang.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { csrf_token: csrfToken, _action: 'batchDelete', ids: ids },
                    success: function (res) {
                        if (res.code === 200) {
                            csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                            layer.msg(res.msg || '删除成功');
                            modifiedRows = {};
                            $('.lang-trans-modified').removeClass('lang-trans-modified');
                            table.reload('langTransTableId', { where: {_action: 'list'} });
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
            var url = '/admin/lang.php?_popup=1';
            if (editId) url += '&id=' + encodeURIComponent(editId);
            var isEdit = !!editId;
            var popupHeight = isEdit ? '420px' : '700px';

            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 800 ? '520px' : '95%', window.innerHeight >= 800 ? popupHeight : '90%'],
                shadeClose: true,
                content: url,
                end: function () {
                    if (window._langTransPopupSaved) {
                        window._langTransPopupSaved = false;
                        table.reload('langTransTableId', { where: {_action: 'list'} });
                    }
                }
            });
        }
    });
});
</script>
