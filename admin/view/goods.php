<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();
?>
<!-- 搜索条件（自定义样式：白底卡片 + 网格布局，不再用 layui-collapse） -->
<div class="em-filter" id="goodsFilter">
    <div class="em-filter__head" id="goodsFilterHead">
        <span class="em-filter__title"><i class="fa fa-filter"></i>搜索条件</span>
        <span class="em-filter__toggle"><i class="fa fa-angle-down"></i><span class="em-filter__toggle-text">展开</span></span>
    </div>
    <div class="em-filter__body">
        <form class="em-filter__form" data-em-search-btn="#goodsSearchBtn" autocomplete="off">
        <div class="em-filter__grid">
            <div class="em-filter__field">
                <label>商品名称 / 编码</label>
                <input type="search" id="goodsSearchKeyword" placeholder="标题 / 编码 / 简介" enterkeyhint="search">
            </div>
            <div class="em-filter__field">
                <label>商品分类</label>
                <select id="goodsSearchCategory">
                    <option value="">全部分类</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>"><?= str_repeat('—', $cat['parent_id'] ? 1 : 0) . htmlspecialchars($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="em-filter__field">
                <label>商品类型</label>
                <select id="goodsSearchType">
                    <option value="">全部</option>
                    <?php foreach ($goodsTypes as $type => $config): ?>
                        <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($config['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="em-filter__field">
                <label>推荐商品</label>
                <select id="goodsSearchRecommended">
                    <option value="">全部</option>
                    <option value="1">已推荐</option>
                    <option value="0">未推荐</option>
                </select>
            </div>
            <div class="em-filter__field">
                <label>状态</label>
                <select id="goodsSearchStatus">
                    <option value="">全部</option>
                    <option value="1">正常</option>
                    <option value="0">已禁用</option>
                </select>
            </div>
        </div>
        <div class="em-filter__actions">
            <button type="button" class="em-btn em-reset-btn" id="goodsResetBtn"><i class="fa fa-undo mr-5"></i>重置</button>
            <button type="button" class="em-btn em-save-btn" id="goodsSearchBtn"><i class="fa fa-search mr-5"></i>搜索</button>
        </div>
        </form>
    </div>
</div>

<!-- 选项卡：按上架状态筛选（独立白底卡片，不与表格白底融合） -->
<div class="em-tabs" id="goodsTabs">
    <a class="em-tabs__item is-active" data-sale=""><i class="fa fa-cubes"></i>全部商品</a>
    <a class="em-tabs__item" data-sale="1"><i class="fa fa-eye"></i>上架中</a>
    <a class="em-tabs__item" data-sale="0"><i class="fa fa-eye-slash"></i>已下架</a>
</div>

<div class="admin-page">
    <h1 class="admin-page__title">商品管理</h1>
    <table id="goodsTable" lay-filter="goodsTable"></table>
</div>

<!-- 工具栏模板：全部按钮统一 em-btn 体系
     批量操作类按钮（删除/上架/推荐/置顶）初始挂 em-disabled-btn，选中行后 JS 再切掉 -->
<script type="text/html" id="goodsToolbarTpl">
    <div class="em-table-toolbar">
        <div class="em-table-toolbar__actions layui-btn-container">
            <a class="em-btn em-reset-btn" id="goodsRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
            <a class="em-btn em-save-btn" lay-event="add"><i class="fa fa-plus-circle"></i>添加商品</a>
            <a class="em-btn em-red-btn em-disabled-btn" lay-event="batchDelete"><i class="fa fa-trash"></i>批量删除</a>
            <a class="em-btn em-reset-btn em-disabled-btn" id="saleDropdownBtn">
                <i class="fa fa-eye"></i>上架/下架
                <i class="layui-icon layui-icon-down layui-font-12"></i>
            </a>
            <a class="em-btn em-reset-btn em-disabled-btn" id="recommendDropdownBtn">
                <i class="fa fa-star"></i>批量推荐
                <i class="layui-icon layui-icon-down layui-font-12"></i>
            </a>
            <a class="em-btn em-reset-btn em-disabled-btn" id="moreActionDropdownBtn">
                <i class="fa fa-tag"></i>分类置顶
                <i class="layui-icon layui-icon-down layui-font-12"></i>
            </a>
        </div>
        <form class="em-quick-search" id="goodsQuickSearchForm" autocomplete="off">
            <i class="fa fa-search em-quick-search__ico"></i>
            <input type="search" id="goodsQuickSearch" placeholder="输入商品名称后回车搜索" enterkeyhint="search">
            <button type="button" class="em-quick-search__clear" id="goodsQuickClear" title="清空">
                <i class="fa fa-times"></i>
            </button>
        </form>
    </div>
</script>

<!-- 行内操作按钮模板 -->
<script type="text/html" id="goodsRowActionTpl">
    <div class="layui-clear-space">
        <a class="em-btn em-sm-btn em-save-btn" lay-event="edit"><i class="fa fa-pencil"></i> 编辑</a>
        <a class="em-btn em-sm-btn em-purple-btn" lay-event="stock"><i class="fa fa-cubes"></i> 库存</a>
        <a class="em-btn em-sm-btn em-reset-btn goods-more-btn" data-id="{{d.id}}" data-code="{{d.code}}"><i class="fa fa-ellipsis-h"></i></a>
    </div>
</script>

<!-- 封面图片模板（只展示一张，点击查看所有） -->
<script type="text/html" id="goodsCoverTpl">
    {{# var covers = []; try { covers = JSON.parse(d.cover_images || '[]'); } catch(e){} }}
    {{# if(covers.length > 0){ }}
        <img src="{{ covers[0] }}" style="width:40px;height:40px;object-fit:cover;border-radius:4px;cursor:pointer;" class="goods-cover-img" data-images='{{ d.cover_images }}'>
    {{# } else { }}
        <span style="color:#ccc;">无图</span>
    {{# } }}
</script>

<!-- 价格区间模板 -->
<script type="text/html" id="goodsPriceTpl">
    {{# if(d.min_price == d.max_price){ }}
        <span>¥{{ d.min_price }}</span>
    {{# } else { }}
        <span>¥{{ d.min_price }} ~ ¥{{ d.max_price }}</span>
    {{# } }}
</script>

<!-- 商品类型模板 -->
<script type="text/html" id="goodsTypeTpl">
    {{# var typeText = goodsTypeMap[d.goods_type] || d.goods_type; }}
    <span class="em-tag em-tag--purple">{{ typeText }}</span>
</script>

<!-- 上架状态：点击即切换（左侧小圆点取胶囊文字色，Tailwind 风） -->
<script type="text/html" id="goodsSaleTpl">
    {{# if(d.is_on_sale == 1){ }}
    <span class="em-tag em-tag--on em-tag--clickable" lay-event="toggleSale" title="点击下架">
        <span class="em-tag__dot"></span>上架中
    </span>
    {{# } else { }}
    <span class="em-tag em-tag--muted em-tag--clickable" lay-event="toggleSale" title="点击上架">
        <span class="em-tag__dot"></span>已下架
    </span>
    {{# } }}
</script>

<!-- 推荐开关模板 -->
<script type="text/html" id="goodsRecommendTpl">
    <input type="checkbox" name="is_recommended" value="{{ d.id }}" lay-skin="switch" lay-text="是|否" lay-filter="goodsRecommendFilter" {{ d.is_recommended == 1 ? 'checked' : '' }}>
</script>

<!-- 商品名称模板（两行截断，推荐商品前显示推荐图标） -->
<script type="text/html" id="goodsTitleTpl">
    <span class="goods-title-clamp" title="{{ d.title }}">{{# if(d.is_recommended == 1){ }}<span class="goods-recommend-tag">推荐</span>{{# } }}{{ d.title }}</span>
</script>
<style>
.goods-title-clamp {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    text-overflow: ellipsis;
    /* 中英文混排：允许在任意字符间断行，避免整段英文按「单词」整块换行 */
    overflow-wrap: anywhere;
    word-break: break-all;
    line-height: 1.4;
}

/* em-filter / em-quick-search 样式已提到 admin/static/css/style.css 公共组件里 */
</style>

<!-- 库存显示模板 -->
<script type="text/html" id="goodsStockTpl">
    {{# if(d.total_stock === 0 || d.total_stock === '0'){ }}
        <span style="color:#ff4d4f;font-weight:600;">0</span>
    {{# } else if(d.total_stock <= 10){ }}
        <span style="color:#fa8c16;font-weight:600;">{{ d.total_stock }}</span>
    {{# } else { }}
        {{ d.total_stock }}
    {{# } }}
</script>

<!-- 发货类型模板（delivery_type 由服务端按商品实际配置计算） -->
<script type="text/html" id="goodsDeliveryTypeTpl">
    {{# if(d.delivery_type === 'auto'){ }}
        <span class="em-tag em-tag--blue"><i class="fa fa-bolt" style="margin-right:3px;"></i>自动发货</span>
    {{# } else { }}
        <span class="em-tag em-tag--amber"><i class="fa fa-user" style="margin-right:3px;"></i>人工发货</span>
    {{# } }}
</script>

<!-- 创建时间：日期加粗 + 时间浅色等宽（和用户/日志列表同风格） -->
<script type="text/html" id="goodsCreatedAtTpl">
    {{# if(d.created_at){ }}
    {{# var dt = d.created_at.replace('T', ' ').substring(0, 19); var parts = dt.split(' '); }}
    <span style="display:inline-flex;flex-direction:column;align-items:center;line-height:1.3;">
        <span style="color:#374151;font-weight:500;font-size:12.5px;">{{parts[0]}}</span>
        <span style="color:#9ca3af;font-size:11.5px;font-family:Menlo,Consolas,monospace;">{{parts[1] || ''}}</span>
    </span>
    {{# } else { }}
    <span class="em-tag em-tag--muted">无</span>
    {{# } }}
</script>

<!-- 商品类型标识 → 中文名映射 -->
<script>var goodsTypeMap = <?php echo json_encode(array_map(function($c){ return $c['name']; }, $goodsTypes), JSON_UNESCAPED_UNICODE); ?>;</script>

<script>
$(function(){
    // PJAX 防重复绑定：清掉本页历史 .admGoods handler，避免事件成倍触发
    $(document).off('.admGoods');
    $(window).off('.admGoods');

    'use strict';

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var table, dropdown;

    function updateCsrf(token) {
        if (token) csrfToken = token;
    }
    window.updateCsrf = updateCsrf;

    layui.use(['layer', 'form', 'table', 'dropdown'], function () {
        var layer = layui.layer;
        var form = layui.form;
        table = layui.table;
        dropdown = layui.dropdown;

        form.render('select');

        // ============================================================
        // 搜索面板展开/收起（自己做，不用 layui.element.collapse）
        // localStorage 记忆展开状态
        // ============================================================
        var $filter = $('#goodsFilter');
        var filterOpenKey = 'goods_filter_open';
        function setFilterOpen(open) {
            $filter.toggleClass('is-open', open);
            $('.em-filter__toggle-text', $filter).text(open ? '收起' : '展开');
            localStorage.setItem(filterOpenKey, open ? 'y' : 'n');
        }
        setFilterOpen(localStorage.getItem(filterOpenKey) === 'y');
        $('#goodsFilterHead').on('click', function () {
            setFilterOpen(!$filter.hasClass('is-open'));
        });

        // ============================================================
        // 选项卡当前选中值（按 data-sale 过滤：'' 全部 / '1' 上架 / '0' 下架）
        // ============================================================
        var currentSaleTab = '';
        var $goodsTabs = $('#goodsTabs');

        // ============================================================
        // 分页每页条数：localStorage 记忆，刷新后保持
        // ============================================================
        var PAGE_LIMITS = [10, 20, 50, 100];
        var pageLimitKey = 'goods_page_limit';
        function getSavedLimit() {
            var v = parseInt(localStorage.getItem(pageLimitKey), 10);
            return PAGE_LIMITS.indexOf(v) !== -1 ? v : 10;
        }

        // ============================================================
        // 渲染表格
        // 列顺序：商品分类、封面图、商品名称、商品类型、价格区间、库存、销量、浏览量、推荐、上架、创建时间、操作
        // ============================================================
        table.render({
            elem: '#goodsTable',
            id: 'goodsTableId',
            url: '/admin/goods.php?_action=list',
            headers: {csrf: csrfToken},
            method: 'POST',
            toolbar: '#goodsToolbarTpl',
            defaultToolbar: [],
            lineStyle: 'height: 70px;',
            page: true,
            limit: getSavedLimit(),
            limits: PAGE_LIMITS,
            cellMinWidth: 80,
            cols: [[
                {type: 'checkbox', width: 50},
                {field: 'category_name', title: '商品分类', width: 110},
                {field: 'cover_images', title: '封面图', width: 80, templet: '#goodsCoverTpl', align: 'center'},
                {field: 'title', title: '商品名称', minWidth: 200, templet: '#goodsTitleTpl'},
                {field: 'goods_type', title: '商品类型', width: 110, templet: '#goodsTypeTpl', align: 'center'},
                {field: 'goods_type', title: '发货类型', width: 110, templet: '#goodsDeliveryTypeTpl', align: 'center'},
                {field: 'total_stock', title: '库存', width: 80, align: 'center', templet: '#goodsStockTpl'},
                {field: 'total_sales', title: '销量', width: 80, align: 'center'},
                {field: 'views_count', title: '浏览量', width: 80, align: 'center'},
                {field: 'is_on_sale', title: '上架', width: 99, templet: '#goodsSaleTpl', align: 'center', unresize: true},
                {field: 'created_at', title: '创建时间', width: 113, templet: '#goodsCreatedAtTpl', align: 'center'},
                {title: '操作', width: 215, align: 'center', toolbar: '#goodsRowActionTpl'}
            ]],
            done: function (res) {
                if (res.csrf_token) {
                    csrfToken = res.csrf_token;
                }
                // table reload 会重建工具栏 DOM，需重新绑定下拉菜单
                initToolbarDropdowns();
                // 初始化行内"更多"下拉菜单
                initRowDropdowns();
                $('#goodsQuickSearch').val($('#goodsSearchKeyword').val() || '');
            }
        });

        // ============================================================
        // 工具栏下拉菜单初始化（抽为函数，table reload 后重新绑定）
        // ============================================================
        function initToolbarDropdowns() {
            // 上架/下架 下拉
            dropdown.render({
                elem: '#saleDropdownBtn',
                data: [
                    {title: '批量上架', templet: '<i class="fa fa-eye"></i> {{= d.title }}', id: 'batchOnSale'},
                    {title: '批量下架', templet: '<i class="fa fa-eye-slash"></i> {{= d.title }}', id: 'batchOffSale'}
                ],
                click: function(obj) {
                    var checkStatus = table.checkStatus('goodsTableId');
                    var data = checkStatus.data;
                    if (data.length === 0) { layer.msg('请选择商品'); return; }
                    batchAction(obj.id === 'batchOnSale' ? 'on_sale' : 'off_sale', data);
                }
            });

            // 推荐 下拉
            dropdown.render({
                elem: '#recommendDropdownBtn',
                data: [
                    {title: '批量推荐', templet: '<i class="fa fa-star"></i> {{= d.title }}', id: 'batchRecommend'},
                    {title: '取消推荐', templet: '<i class="fa fa-star-o"></i> {{= d.title }}', id: 'batchUnrecommend'}
                ],
                click: function(obj) {
                    var checkStatus = table.checkStatus('goodsTableId');
                    var data = checkStatus.data;
                    if (data.length === 0) { layer.msg('请选择商品'); return; }
                    batchAction(obj.id === 'batchRecommend' ? 'recommend' : 'unrecommend', data);
                }
            });

            // 分类置顶 下拉
            dropdown.render({
                elem: '#moreActionDropdownBtn',
                data: [
                    {title: '批量分类置顶', templet: '<i class="fa fa-tag"></i> {{= d.title }}', id: 'batchTopCategory'},
                    {title: '取消分类置顶', templet: '<i class="fa fa-tag"></i> {{= d.title }}', id: 'batchUntopCategory'}
                ],
                click: function(obj) {
                    var checkStatus = table.checkStatus('goodsTableId');
                    var data = checkStatus.data;
                    if (data.length === 0) { layer.msg('请选择商品'); return; }
                    if (obj.id === 'batchTopCategory') {
                        batchAction('top_category', data);
                    } else if (obj.id === 'batchUntopCategory') {
                        batchAction('untop_category', data);
                    }
                }
            });
        }
        // 首次初始化
        initToolbarDropdowns();

        // ============================================================
        // 选项卡点击切换（em-tabs：改 is-active 类，不需要滑块动画）
        // ============================================================
        $goodsTabs.on('click', '.em-tabs__item', function () {
            var $this = $(this);
            if ($this.hasClass('is-active')) return;
            $goodsTabs.find('.em-tabs__item').removeClass('is-active');
            $this.addClass('is-active');
            currentSaleTab = $this.data('sale');
            // 复用统一的 where 收集器，保持和搜索/快捷搜索一致
            doSearchReload();
        });

        // 表格复选框联动：有选中时启用批量按钮，无选中时禁用
        table.on('checkbox(goodsTable)', function () {
            var checked = table.checkStatus('goodsTableId').data.length > 0;
            var $btns = $('[lay-event="batchDelete"], #saleDropdownBtn, #recommendDropdownBtn, #moreActionDropdownBtn');
            $btns.toggleClass('em-disabled-btn', !checked);
        });

        // 分页每页条数变更时写入 localStorage（layui 分页下拉无 table.on 事件，用委托监听 change）
        $(document).on('change.admGoods', '.layui-laypage-limits select', function () {
            var v = parseInt($(this).val(), 10);
            if (PAGE_LIMITS.indexOf(v) !== -1) {
                localStorage.setItem(pageLimitKey, v);
            }
        });

        // ============================================================
        // 行内"更多"下拉菜单（每次表格渲染后初始化，逐个绑定以捕获 data 属性）
        // ============================================================
        function initRowDropdowns() {
            $('.goods-more-btn').each(function() {
                var $btn = $(this);
                var goodsId = $btn.data('id');
                var goodsCode = $btn.data('code');
                dropdown.render({
                    elem: this,
                    align: 'right',
                    data: [
                        {title: '复制商品ID', templet: '<i class="fa fa-clipboard"></i> {{= d.title }}', id: 'copyId'},
                        {title: '复制商品编码', templet: '<i class="fa fa-clipboard"></i> {{= d.title }}', id: 'copyCode'},
                        {title: '一键克隆', templet: '<i class="fa fa-copy"></i> {{= d.title }}', id: 'clone'},
                        {title: '删除商品', templet: '<i class="fa fa-trash" style="color:#FF5722;"></i> <span style="color:#FF5722;">{{= d.title }}</span>', id: 'delete'}
                    ],
                    click: function(obj) {
                        if (obj.id === 'copyId') {
                            copyToClipboard(String(goodsId));
                            layer.msg('已复制商品ID：' + emEsc(goodsId));
                        } else if (obj.id === 'copyCode') {
                            copyToClipboard(goodsCode);
                            layer.msg('已复制商品编码：' + emEsc(goodsCode));
                        } else if (obj.id === 'clone') {
                            cloneGoods(goodsId);
                        } else if (obj.id === 'delete') {
                            deleteGoods(goodsId);
                        }
                    }
                });
            });
        }

        // 复制到剪贴板
        function copyToClipboard(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                var $temp = $('<input>').val(text).appendTo('body').select();
                document.execCommand('copy');
                $temp.remove();
            }
        }

        // ============================================================
        // 搜索：完整搜索 + 快捷搜索共用一个重载函数
        // 所有筛选字段集中从 DOM 读；keyword 在 quick 和 full 两处同步
        // ============================================================
        function collectWhere() {
            return {
                keyword: $.trim($('#goodsSearchKeyword').val() || ''),
                category_id: $('#goodsSearchCategory').val() || '',
                is_on_sale: currentSaleTab === '' ? '' : String(currentSaleTab),
                goods_type: $('#goodsSearchType').val() || '',
                is_recommended: $('#goodsSearchRecommended').val() || '',
                status: $('#goodsSearchStatus').val() || ''
            };
        }
        function doSearchReload() {
            table.reload('goodsTableId', { where: collectWhere(), page: {curr: 1} });
        }

        // 完整搜索（详细条件面板内的按钮）
        $(document).on('click.admGoods', '#goodsSearchBtn', function () {
            // 同步到快捷搜索输入框（保持两端显示一致）
            $('#goodsQuickSearch').val($('#goodsSearchKeyword').val() || '');
            doSearchReload();
        });

        // 重置：清所有条件 + 同步快捷搜索框
        $(document).on('click.admGoods', '#goodsResetBtn', function () {
            $('#goodsSearchKeyword').val('');
            $('#goodsSearchCategory').val('');
            $('#goodsSearchType').val('');
            $('#goodsSearchRecommended').val('');
            $('#goodsSearchStatus').val('');
            $('#goodsQuickSearch').val('');
            doSearchReload();
        });

        // 快捷搜索（工具栏内）：回车触发；同步关键词到完整搜索面板
        $(document).on('em:search.admGoods', '#goodsQuickSearchForm', function () {
            $('#goodsSearchKeyword').val($(this).find('#goodsQuickSearch').val());
            doSearchReload();
        });

        // 快捷搜索清空按钮：清空输入 + 同步 + 立即刷新列表
        $(document).on('click.admGoods', '#goodsQuickClear', function () {
            $('#goodsQuickSearch').val('').focus();
            $('#goodsSearchKeyword').val('');
            doSearchReload();
        });

        $(document).on('click.admGoods', '#goodsRefreshBtn', function () {
            table.reload('goodsTableId');
        });

        // ============================================================
        // 工具栏事件（仅处理 lay-event 按钮）
        // ============================================================
        table.on('toolbar(goodsTable)', function (obj) {
            var checkStatus = table.checkStatus('goodsTableId');
            var data = checkStatus.data;

            switch (obj.event) {
                case 'add':
                    openEditPopup('添加商品');
                    break;
                case 'batchDelete':
                    if (data.length === 0) { layer.msg('请选择商品'); return; }
                    layer.confirm('确定要删除选中的 ' + data.length + ' 个商品吗？此操作不可恢复。', function (idx) {
                        batchAction('delete', data, idx);
                    });
                    break;
            }
        });

        // ============================================================
        // 行内工具栏事件
        // ============================================================
        table.on('tool(goodsTable)', function (obj) {
            var data = obj.data;
            switch (obj.event) {
                case 'edit':
                    openEditPopup('编辑商品', data.id);
                    break;
                case 'toggleSale':
                    // 上下架标签点击：调 toggle_sale，成功后只原地切换标签样式，不 reload 表格
                    var $tag = $(this);
                    if ($tag.hasClass('is-loading')) return;
                    $tag.addClass('is-loading');
                    $.ajax({
                        url: '/admin/goods.php?_action=toggle_sale',
                        type: 'POST',
                        dataType: 'json',
                        data: {csrf_token: csrfToken, id: data.id},
                        success: function (res) {
                            if (res.code === 200) {
                                csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                                if ($tag.hasClass('em-tag--on')) {
                                    $tag.removeClass('em-tag--on').addClass('em-tag--muted')
                                        .attr('title', '点击上架')
                                        .html('<span class="em-tag__dot"></span>已下架');
                                } else {
                                    $tag.removeClass('em-tag--muted').addClass('em-tag--on')
                                        .attr('title', '点击下架')
                                        .html('<span class="em-tag__dot"></span>上架中');
                                }
                                layer.msg(res.msg || '操作成功');
                            } else {
                                layer.msg(res.msg || '操作失败');
                            }
                        },
                        error: function () { layer.msg('网络异常'); },
                        complete: function () { $tag.removeClass('is-loading'); }
                    });
                    break;
                case 'stock':
                    window._stockPopupSaved = false;
                    layer.open({
                        type: 2,
                        title: '<i class="fa fa-cubes"></i> 库存管理',
                        skin: 'admin-modal',
                        maxmin: true,
                        area: [window.innerWidth >= 960 ? '960px' : '95%', window.innerHeight >= 800 ? '85%' : '95%'],
                        shadeClose: true,
                        content: '/admin/goods_edit.php?_action=stock_manager&goods_id=' + data.id + '&_popup=1',
                        end: function () {
                            if (window._stockPopupSaved) {
                                table.reloadData('goodsTable');
                            }
                        }
                    });
                    break;
            }
        });

        // ============================================================
        // 封面图点击放大（Viewer.js，带缩略图导航）
        // ============================================================
        $(document).on('click.admGoods', '.goods-cover-img', function() {
            var imagesJson = $(this).attr('data-images');
            var covers = [];
            try { covers = JSON.parse(imagesJson || '[]'); } catch(e) {}
            if (covers.length === 0) return;

            // 创建临时容器，填充图片供 Viewer.js 使用
            var $container = $('<div style="display:none;"></div>');
            covers.forEach(function(url) {
                $container.append('<img src="' + url + '">');
            });
            $('body').append($container);

            var viewer = new Viewer($container[0], {
                navbar: true,    // 底部缩略图导航
                title: false,
                toolbar: true,
                hidden: function () {
                    viewer.destroy();
                    $container.remove();
                }
            });
            viewer.show();
        });

        // ============================================================
        // 推荐开关
        // ============================================================
        form.on('switch(goodsRecommendFilter)', function (obj) {
            var id = this.value;
            toggleSwitch(obj, '/admin/goods.php?_action=toggle_recommend', {csrf_token: csrfToken, id: id});
        });

        // 通用开关切换 AJAX
        function toggleSwitch(obj, url, postData) {
            var $switch = $(obj.elem);
            var $wrap = $switch.closest('.layui-unselect');
            var $switchSpan = $wrap.find('.layui-form-switch');

            $switchSpan.css('position', 'relative').append('<i class="layui-icon layui-icon-loading layui-anim layui-anim-rotate layui-anim-loop" style="position:absolute;left:0;top:0;width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,0.85);font-size:16px;"></i>');
            $switch.prop('disabled', true);

            $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: postData,
                success: function (res) {
                    if (res.code === 200) {
                        csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                        layer.msg(res.msg || '操作成功');
                        table.reload('goodsTableId');
                    } else {
                        obj.elem.checked = !obj.elem.checked;
                        form.render('switch');
                        $switchSpan.find('i').removeClass().addClass('layui-icon layui-icon-close').fadeOut(600, function(){ $(this).remove(); });
                        layer.msg(res.msg || '操作失败');
                    }
                },
                error: function () {
                    obj.elem.checked = !obj.elem.checked;
                    form.render('switch');
                    layer.msg('网络异常');
                },
                complete: function () { $switch.prop('disabled', false); }
            });
        }

        // ============================================================
        // 批量操作
        // ============================================================
        function batchAction(action, data, closeIdx, extraData) {
            var ids = data.map(function(item) { return item.id; });
            var postData = {csrf_token: csrfToken, batch_action: action, ids: ids};
            if (extraData) $.extend(postData, extraData);

            $.ajax({
                url: '/admin/goods.php?_action=batch',
                type: 'POST',
                dataType: 'json',
                data: postData,
                success: function (res) {
                    if (res.code === 200) {
                        csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                        layer.msg(res.msg || '操作成功');
                        table.reload('goodsTableId');
                    } else {
                        layer.msg(res.msg || '操作失败');
                    }
                },
                error: function () { layer.msg('网络异常'); },
                complete: function () { if (closeIdx) layer.close(closeIdx); }
            });
        }

        // ============================================================
        // 删除商品（从行内更多菜单调用）
        // ============================================================
        function deleteGoods(id) {
            layer.confirm('确定要删除该商品吗？', function (idx) {
                $.ajax({
                    url: '/admin/goods.php?_action=delete',
                    type: 'POST',
                    dataType: 'json',
                    data: {csrf_token: csrfToken, id: id},
                    success: function (res) {
                        if (res.code === 200) {
                            csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                            layer.msg(res.msg || '删除成功');
                            table.reload('goodsTableId');
                        } else {
                            layer.msg(res.msg || '删除失败');
                        }
                    },
                    error: function () { layer.msg('网络异常'); },
                    complete: function () { layer.close(idx); }
                });
            });
        }

        // ============================================================
        // 克隆商品
        // ============================================================
        function cloneGoods(id) {
            layer.confirm('确定要克隆该商品吗？', function (idx) {
                $.ajax({
                    url: '/admin/goods.php?_action=clone',
                    type: 'POST',
                    dataType: 'json',
                    data: {csrf_token: csrfToken, id: id},
                    success: function (res) {
                        if (res.code === 200) {
                            csrfToken = res.data && res.data.csrf_token ? res.data.csrf_token : csrfToken;
                            layer.msg(res.msg || '克隆成功');
                            table.reload('goodsTableId');
                        } else {
                            layer.msg(res.msg || '克隆失败');
                        }
                    },
                    error: function () { layer.msg('网络异常'); },
                    complete: function () { layer.close(idx); }
                });
            });
        }

        // ============================================================
        // 打开编辑弹窗
        // ============================================================
        function openEditPopup(title, editId) {
            var url = '/admin/goods_edit.php?_popup=1';
            if (editId) url += '&id=' + encodeURIComponent(editId);
            layer.open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                maxmin: true,
                area: [window.innerWidth >= 900 ? '838px' : '95%', window.innerHeight >= 800 ? '90%' : '95%'],
                shadeClose: true,
                content: url,
                end: function () {
                    if (window._goodsPopupSaved) {
                        window._goodsPopupSaved = false;
                        table.reload('goodsTableId');
                    }
                }
            });
        }
    });
});
</script>
