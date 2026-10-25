<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

// 应用收费由中心服务端统一以人民币结算，这里固定用 ¥ 不读站点主货币
// 分类 tabs 由 PHP 直接渲染(基于 PluginModel::MAIN_PLUGIN_CATEGORIES);列表走
// /admin/appstore.php?_action=list（服务端 app-list），可购状态来自响应的 can_buy

// 应用图片（封面 / 内容图）统一基于授权服务器线路拼接；以此保证资源 URL
// 在全站稳定、可被浏览器缓存
$__appstoreLines = LicenseClient::lines();
$appstoreAssetHost = $__appstoreLines ? rtrim($__appstoreLines[0]['url'], '/') : '';
$csrfToken = $csrfToken ?? Csrf::token();
?>
<style>
/* 和控制台 / 模板管理 / 资源管理一致：去掉 .admin-page 默认白底，内容块浮在灰底画布 */
.admin-page-appstore { padding: 8px 4px 40px; background: unset; }

/* ===== 顶部工具条：左(刷新) 右(搜索)，与表格留出间距 ===== */
/* 用 .admin-page-appstore 前缀提高特异性，覆盖 style.css 里 form.em-list-search{margin:0} */
.admin-page-appstore .appstore-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin: 0 0 12px;
}
.appstore-search {
    display: flex;
    align-items: center;
    width: 300px;
    height: 34px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    transition: border-color .15s ease, box-shadow .15s ease;
}
.appstore-search:focus-within {
    border-color: #6366f1;
    box-shadow: 0 0 0 3px rgba(99,102,241,.1);
}
.appstore-search i.fa-search {
    margin-left: 12px;
    color: #9ca3af;
    font-size: 12px;
    flex-shrink: 0;
}
.appstore-search input {
    flex: 1;
    min-width: 0;
    height: 100%;
    padding: 0 8px;
    font-size: 13px;
    color: #1f2937;
    background: transparent;
    border: 0;
    outline: none;
}
.appstore-search input::placeholder { color: #9ca3af; }
.appstore-search__clear {
    flex-shrink: 0;
    display: none;
    width: 20px; height: 20px;
    margin-right: 6px;
    border: none;
    border-radius: 50%;
    background: #e5e7eb;
    color: #6b7280;
    cursor: pointer;
    font-size: 10px;
    align-items: center;
    justify-content: center;
    transition: background .15s;
}
.appstore-search__clear:hover { background: #ef4444; color: #fff; }
.appstore-search input:not(:placeholder-shown) ~ .appstore-search__clear { display: inline-flex; }

/* 搜索按钮：内嵌在搜索框右侧 */
.appstore-search__btn {
    flex-shrink: 0;
    height: 26px;
    margin-right: 4px;
    padding: 0 12px;
    border: none;
    border-radius: 6px;
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    color: #fff;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: filter .15s;
}
.appstore-search__btn:hover { filter: brightness(.95); }
.appstore-search__btn:active { filter: brightness(.9); }

/* ===== 封面小图（表格第一列） ===== */
.appstore-cover {
    width: 44px; height: 44px;
    display: inline-block;
    border-radius: 8px;
    object-fit: cover;
    background: #f3f4f6;
    box-shadow: 0 1px 3px rgba(15,23,42,.08);
    vertical-align: middle;
}
.appstore-cover--empty {
    display: inline-flex; align-items: center; justify-content: center;
    color: #9ca3af; font-size: 18px;
}
.appstore-cover--zoom { cursor: zoom-in; transition: transform .15s ease, box-shadow .15s ease; }
.appstore-cover--zoom:hover {
    transform: scale(1.08);
    box-shadow: 0 4px 12px rgba(15,23,42,.18);
}

/* ===== 类型 tag（前置到名称行） ===== */
.appstore-type {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px;
    font-size: 11px; font-weight: 500;
    border-radius: 4px;
    line-height: 18px;
    flex-shrink: 0;
}
.appstore-type i { font-size: 10px; }
.appstore-type--template { background: #ecfeff; color: #0891b2; }
.appstore-type--plugin   { background: rgba(99,102,241,.08); color: #4f46e5; }

/* ===== 名称行 ===== */
.appstore-title__row {
    display: flex; align-items: center; gap: 8px;
    min-width: 0;
}
.appstore-title__name {
    color: #111827;
    line-height: 1.35;
    flex: 1; min-width: 0;
    overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
}
.appstore-title__desc {
    font-size: 12px; color: #9ca3af;
    margin-top: 3px;
    font-weight: 400;
    line-height: 1.4;
    display: -webkit-box; -webkit-line-clamp: 1; line-clamp: 1;
    -webkit-box-orient: vertical; overflow: hidden;
}

/* ===== 价格 chip（免费/付费/不可用） ===== */
.appstore-chip {
    display: inline-block;
    min-width: 56px;
    padding: 4px 12px;
    font-size: 12px; font-weight: 600;
    border-radius: 5px;
    letter-spacing: .2px;
    line-height: 18px;
    border: 1px solid transparent;
}
.appstore-chip__cur { font-size: 10px; opacity: .7; margin-right: 1px; }

.appstore-chip--free {
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    color: #047857;
    border-color: rgba(16,185,129,.28);
    box-shadow: 0 1px 2px rgba(16,185,129,.08);
}
.appstore-chip--paid {
    background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%);
    color: #be123c;
    border-color: rgba(225,29,72,.28);
    box-shadow: 0 1px 2px rgba(225,29,72,.08);
}
.appstore-chip--na {
    background: #f9fafb;
    color: #cbd5e1;
    border: 1px dashed #e5e7eb;
    font-weight: 400;
}

/* 刷新按钮（放在搜索框右侧） */
.appstore-refresh-btn i { margin-right: 4px; }

/* 窄屏：搜索框拉满，工具条允许换行 */
@media (max-width: 640px) {
    .admin-page-appstore .appstore-toolbar { flex-wrap: wrap; gap: 8px; }
    .appstore-search { width: 100%; }
}
</style>

<div class="admin-page admin-page-appstore">
    <h1 class="admin-page__title">应用商店</h1>

    <!--
        分类选项卡：全部由 PHP 渲染 —— "全部" + PluginModel::MAIN_PLUGIN_CATEGORIES +
        硬编码的"未归类" / "已购买"。
        分类 tab 的 data-filter 带 cat:1 标记（不能只看 id>0：未归类的 id 就是 0，
        和"全部"撞号，靠标记才能区分出"要按未归类筛"）。
    -->
    <div class="em-tabs" id="appstoreTabs">
        <a class="em-tabs__item is-active" data-filter='{"type":"all","id":0}'>
            <i class="fa fa-th-large"></i>全部<em class="em-tabs__count"></em>
        </a>
        <?php foreach (PluginModel::MAIN_PLUGIN_CATEGORIES as $__cid => $__cname): ?>
        <a class="em-tabs__item" data-filter='<?= htmlspecialchars(json_encode(['cat' => 1, 'id' => (int) $__cid]), ENT_QUOTES, 'UTF-8') ?>'>
            <i class="fa fa-folder-o"></i><?= htmlspecialchars((string) $__cname, ENT_QUOTES, 'UTF-8') ?><em class="em-tabs__count"></em>
        </a>
        <?php endforeach; ?>
        <a class="em-tabs__item" data-filter='{"cat":1,"id":0}'>
            <i class="fa fa-folder-o"></i>未归类<em class="em-tabs__count"></em>
        </a>
        <a class="em-tabs__item" data-filter='{"type":"all","id":0,"list_mode":"purchased"}'>
            <i class="fa fa-check-circle"></i>已购买<em class="em-tabs__count"></em>
        </a>
    </div>

    <!-- 工具条：左(刷新) 右(搜索) -->
    <form class="appstore-toolbar em-list-search" id="appstoreSearchForm" autocomplete="off" data-em-search-btn="#appstoreSearchSubmit">
        <button type="button" class="em-btn em-sm-btn em-reset-btn appstore-refresh-btn" id="appstoreRefreshBtn">
            <i class="fa fa-refresh"></i>刷新
        </button>
        <div class="appstore-search">
            <i class="fa fa-search"></i>
            <input type="search" id="appstoreSearch" placeholder="搜索应用名称 / 描述…" enterkeyhint="search">
            <button type="button" class="appstore-search__clear" id="appstoreSearchClear" title="清空">
                <i class="fa fa-times"></i>
            </button>
            <button type="button" class="appstore-search__btn" id="appstoreSearchSubmit" title="搜索">搜索</button>
        </div>
    </form>

    <table id="appstoreTable" lay-filter="appstoreTable"></table>
</div>

<!-- 名称 + 类型 tag + 描述 -->
<script type="text/html" id="appstoreTitleTpl">
    <div>
        <div class="appstore-title__row">
            <span class="appstore-type appstore-type--{{ d.type === 'template' ? 'template' : 'plugin' }}">
                <i class="fa {{ d.type === 'template' ? 'fa-paint-brush' : 'fa-puzzle-piece' }}"></i>
                {{ d.type === 'template' ? '模板' : '插件' }}
            </span>
            <span class="appstore-title__name">{{ d.name_cn || d.name_en || '-' }}</span>
        </div>
        <div class="appstore-title__desc" title="{{ (d.description || '').replace(/<[^>]+>/g, '').trim() || '该应用未配置描述信息' }}">
            {{ (d.description || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim() || '该应用未配置描述信息' }}
        </div>
    </div>
</script>

<script type="text/html" id="appstoreAuthorTpl">
    {{ (d.author || d.developer || d.publisher || d.author_name || d.developer_name || d.publisher_name || '-') }}
</script>

<script type="text/html" id="appstoreVersionTpl">
    <span style="font-family:Menlo,Consolas,monospace;color:#374151;">{{ d.version || '-' }}</span>
</script>

<!--
    档位价格：price_vip / price_svip 是两档的价格；至尊档全场免费，固定显示「免费」。
-->
<!-- 至尊价格（至尊档全场免费，硬编码） -->
<script type="text/html" id="appstorePriceSupremeTpl">
    <span class="appstore-chip appstore-chip--free">免费</span>
</script>

<!-- SVIP 价格 -->
<script type="text/html" id="appstorePriceSvipTpl">
    {{# if(parseFloat(d.price_svip || 0) <= 0){ }}
        <span class="appstore-chip appstore-chip--free">免费</span>
    {{# } else { }}
        <span class="appstore-chip appstore-chip--paid">
            <span class="appstore-chip__cur">¥</span>{{ parseFloat(d.price_svip).toFixed(2) }}
        </span>
    {{# } }}
</script>

<!-- VIP 价格 -->
<script type="text/html" id="appstorePriceVipTpl">
    {{# if(parseFloat(d.price_vip || 0) <= 0){ }}
        <span class="appstore-chip appstore-chip--free">免费</span>
    {{# } else { }}
        <span class="appstore-chip appstore-chip--paid">
            <span class="appstore-chip__cur">¥</span>{{ parseFloat(d.price_vip).toFixed(2) }}
        </span>
    {{# } }}
</script>

<!--
    操作按钮分支（判定以服务端为准，不再靠前端推断）:
    - 已装                            → 灰色"已安装"
    - 未装 · 已购买(is_pay)           → 蓝色"已购买，安装"
    - 未装 · 付费 · can_buy=false     → 紫色"限授权用户安装"（未授权，或授权码绑的不是当前域名）
    - 未装 · 免费                     → 蓝色"安装"
    - 未装 · 付费 · can_buy=true      → 红色"购买 ¥price"
    优先级：已装 > 已购买 > 限授权 > 免费 > 购买。已购买排在价格/授权之前 —— 买过的应用
    无论当前 price 与 can_buy 是什么都该直接给安装入口，不能再让用户付一次钱。

    注意"付费应用"的判定不能用 price：price 是**按你当前档位算出来的实际价**，未授权时
    服务端会退回 VIP 门槛价，可能正好是 0（实测：SVIP 档 1.00 的应用在未授权时 price=0.00），
    拿它判免费会让付费应用直接掉进"安装"分支、绕过授权。所以用档位原价
    price_vip / price_svip 判付费；真·免费应用（三个价都是 0）仍不需要授权码。

    坑：laytpl 默认 condense，编译前会把整份模板的换行/缩进压成一个空格，所以代码块里
    绝对不能写 // 行注释 —— 压行后它会把它后面的全部内容（含变量声明）一并注释掉，
    表现为莫名其妙的 "xxx is not defined"。注释写在这里，或在块内用 /* */。
    另外 is_pay 是服务端给的"是否购买过"（JSON 布尔，这里顺带容忍 1 / '1' / 'true'）。
-->
<script type="text/html" id="appstoreActionTpl">
    {{# var L = { installed: '已安装', install: '安装', buy: '购买' };
       var free = parseFloat(d.price || 0) <= 0;
       var canBuy = (d.can_buy === true || d.can_buy === 1 || d.can_buy === '1');
       var isPay = (d.is_pay === true || d.is_pay === 1 || d.is_pay === '1' || d.is_pay === 'true');
       var paidApp = (parseFloat(d.price_vip || 0) > 0 || parseFloat(d.price_svip || 0) > 0); }}
    {{# if (d.is_installed == 1) { }}
        <a class="em-btn em-sm-btn em-reset-btn em-disabled-btn"><i class="fa fa-check"></i>{{ L.installed }}</a>
    {{# } else if (isPay) { }}
        <a class="em-btn em-sm-btn em-save-btn" lay-event="install"><i class="fa fa-download"></i>已购买，安装</a>
    {{# } else if (!canBuy && (paidApp || !free)) { }}
        <a class="em-btn em-sm-btn em-purple-btn" lay-event="needLicense"><i class="fa fa-shield"></i>限授权用户安装</a>
    {{# } else if (free) { }}
        <a class="em-btn em-sm-btn em-save-btn" lay-event="install"><i class="fa fa-download"></i>{{ L.install }}</a>
    {{# } else { }}
        <a class="em-btn em-sm-btn em-red-btn" lay-event="buy"><i class="fa fa-shopping-cart"></i>{{ L.buy }} ¥{{ parseFloat(d.price || 0).toFixed(2) }}</a>
    {{# } }}
</script>

<script>
// HTML 转义（顶层作用域，供本页各处使用）。
// 应用名、封面地址等来自中心服务器 / 应用作者，属于外部可控数据；
// 而 layer.msg / layer.confirm 与 HTML 属性都是按 HTML 渲染的，必须转义。
function emEsc(v) {
    if (v === null || v === undefined) return '';
    return String(v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// 资源 host（由 PHP 注入）：授权服务器地址
// 注意：只用于展示类资源（封面图）；应用包下载地址不要用它拼，
// 由后端 appstore_resolve_download_url() 解析
var APPSTORE_ASSET_HOST = <?= json_encode($appstoreAssetHost, JSON_UNESCAPED_SLASHES) ?>;
// 是否已授权不看本地了 —— 服务端在列表响应里给了 license + can_buy，按钮判定用 can_buy
// CSRF token（安装 action 校验）
var APPSTORE_CSRF = <?= json_encode($csrfToken) ?>;
// 当前 tab(main / merchant):决定调服务端哪个货架接口、装到主站本地还是落分站市场
// templet 通过 window.APPSTORE_TAB 读取以切换按钮文案
window.APPSTORE_TAB = 'main';

function appstoreAbsUrl(url) {
    if (!url) return '';
    if (/^https?:\/\//i.test(url)) return url;
    return APPSTORE_ASSET_HOST + (url.charAt(0) === '/' ? '' : '/') + url;
}

$(function () {
    layui.use(['layer', 'table', 'util'], function () {
        var layer = layui.layer;
        var table = layui.table;

        var $tabs = $('#appstoreTabs');

        // ---------- 当前 Tab 过滤参数 ----------
        function currentFilter() {
            var raw = $tabs.find('.em-tabs__item.is-active').attr('data-filter');
            try { return JSON.parse(raw || '{}'); } catch (e) { return { type: 'all', id: 0, list_mode: '' }; }
        }
        function buildWhere() {
            var f = currentFilter();
            var where = {
                keyword: ($('#appstoreSearch').val() || '').trim(),
                tab:     window.APPSTORE_TAB || 'main'
            };
            if (String(f.list_mode || '') === 'purchased') {
                where.list_mode = 'purchased';
                return where;
            }
            // 分类 tab：看显式标记，不看 id>0 —— 未归类的 id 就是 0
            if (f.cat) {
                where.category_id = parseInt(f.id, 10) || 0;
                return where;
            }
            if (f.type && f.type !== 'all') where.type = f.type;
            return where;
        }

        // ---------- 分页每页条数：localStorage 记忆，刷新后保持 ----------
        var PAGE_LIMITS = [10, 20, 50];
        var pageLimitKey = 'appstore_page_limit';
        function getSavedLimit() {
            var v = parseInt(localStorage.getItem(pageLimitKey), 10);
            return PAGE_LIMITS.indexOf(v) !== -1 ? v : 10;
        }

        // ---------- 服务端分页表格 ----------
        table.render({
            elem: '#appstoreTable',
            id: 'appstoreTableId',
            url: '/admin/appstore.php?_action=list',
            method: 'GET',
            where: buildWhere(),
            page: true,
            limit: getSavedLimit(),
            limits: PAGE_LIMITS,
            cellMinWidth: 80,
            lineStyle: 'height: 62px;',
            parseData: function (res) {
                var d = res && res.data ? res.data : {};
                var meta = d.meta || {};
                return {
                    code: res.code === 200 ? 0 : (res.code || 500),
                    msg:  res.msg || '',
                    count: meta.total || 0,
                    data:  d.data || []
                };
            },
            request: { pageName: 'page', limitName: 'limit' },
            cols: [[
                {
                    field: 'screenshots', title: '封面', width: 80, align: 'center', unresize: true,
                    templet: function (d) {
                        var shots = Array.isArray(d.screenshots) ? d.screenshots : [];
                        var urls = shots.map(function (s) { return (s && s.url) ? s.url : ''; }).filter(Boolean);
                        if (!urls.length) {
                            return '<span class="appstore-cover appstore-cover--empty"><i class="fa fa-cube"></i></span>';
                        }
                        var imgs = urls.map(appstoreAbsUrl);
                        return '<img class="appstore-cover appstore-cover--zoom" src="' + emEsc(imgs[0]) +
                               '" alt="" data-imgs="' + emEsc(encodeURIComponent(JSON.stringify(imgs))) + '">';
                    }
                },
                { field: 'name_cn', title: '应用名称', minWidth: 240, templet: '#appstoreTitleTpl' },
                { field: 'author', title: '作者', width: 140, templet: '#appstoreAuthorTpl', align: 'center' },
                { field: 'version', title: '版本号', width: 100, templet: '#appstoreVersionTpl', align: 'center' },
                { title: '至尊价格', width: 120, templet: '#appstorePriceSupremeTpl', align: 'center' },
                { field: 'price_vip', title: 'VIP 价格', width: 130, templet: '#appstorePriceVipTpl', align: 'center' },
                { field: 'price_svip', title: 'SVIP 价格', width: 130, templet: '#appstorePriceSvipTpl', align: 'center' },
                { title: '操作', width: 200, align: 'center', toolbar: '#appstoreActionTpl' }
            ]]
        });

        function reloadTable() {
            table.reload('appstoreTableId', {
                where: buildWhere(),
                page: { curr: 1 }
            });
        }
        function refreshTable() {
            table.reload('appstoreTableId', {
                where: buildWhere()
            });
        }

        // 分页每页条数变更时写入 localStorage（layui 分页下拉无 table.on 事件，用委托监听 change）
        $(document).on('change.admAppstore', '.layui-laypage-limits select', function () {
            var v = parseInt($(this).val(), 10);
            if (PAGE_LIMITS.indexOf(v) !== -1) {
                localStorage.setItem(pageLimitKey, v);
            }
        });

        // ---------- em-tabs 切换 ----------
        $tabs.on('click', '.em-tabs__item', function () {
            var $item = $(this);
            if ($item.hasClass('is-active')) return;
            $item.addClass('is-active').siblings().removeClass('is-active');
            reloadTable();
        });

        // ---------- 搜索（输入即过滤，防抖 300ms） + 清空按钮 ----------
        var searchTimer;
        $('#appstoreSearch').on('input', function () {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(reloadTable, 300);
        });
        $(document).on('em:search', '#appstoreSearchForm', function () {
            clearTimeout(searchTimer);
            reloadTable();
        });
        // 搜索按钮（点击 / Enter 均走这里，Enter 由 em-list-search 全局转为点击本按钮）
        $('#appstoreSearchSubmit').on('click', function () {
            clearTimeout(searchTimer);
            reloadTable();
        });
        $('#appstoreSearchClear').on('click', function () {
            $('#appstoreSearch').val('').trigger('input').focus();
        });
        $('#appstoreRefreshBtn').on('click', function () {
            refreshTable();
        });

        // ---------- 封面点击放大（Viewer.js，全局已加载） ----------
        $(document).off('click.appstoreCover').on('click.appstoreCover', '.appstore-cover--zoom', function () {
            var raw = $(this).attr('data-imgs');
            var imgs = [];
            try { imgs = JSON.parse(decodeURIComponent(raw || '')); } catch (e) {}
            if (!imgs.length) return;

            var $container = $('<div style="display:none;"></div>');
            // 这些 url 来自 data-imgs（服务端下发的外部封面地址），进属性前必须转义，
            // 否则一个带引号的地址就能逃出 src 属性注入事件处理器
            imgs.forEach(function (url) { $container.append('<img src="' + emEsc(url) + '">'); });
            $('body').append($container);

            var viewer = new Viewer($container[0], {
                navbar: imgs.length > 1,
                title: false,
                toolbar: true,
                hidden: function () { viewer.destroy(); $container.remove(); }
            });
            viewer.show();
        });

        // ---------- 安装 ----------
        function installApp(d) {
            var displayName = d.name_cn || d.name_en || d.id;
            var typeLabel = d.type === 'template' ? '模板' : '插件';
            var actionLabel = '安装';
            var loadingIdx = EmToast.loading({ shade: 0.3, color: '#000', type: 2 });
            $.post('/admin/appstore.php', {
                _action:    'install',
                csrf_token: APPSTORE_CSRF,
                name:       d.name_en,
                type:       d.type === 'template' ? 'template' : 'plugin',
                // 原样传服务端给的（可能是相对路径）—— 由后端补授权服务器域名，
                // 前端不要用 APPSTORE_ASSET_HOST 拼，那个是给展示类资源用的
                package_url: d.package_url || '',
                version:    d.version || '',
                min_version: d.min_version || '',
                // tab=merchant 时后端会走 MainAppPurchaseService 落 em_app_market
                tab:           window.APPSTORE_TAB || 'main',
                cost_per_unit: Math.round((parseFloat(d.price || 0)) * 1000000),
                remote_app_id: d.id || 0
            }).done(function (res) {
                EmToast.close(loadingIdx);
                if (res && (res.code === 200 || res.code === 0)) {
                    if (res.data && res.data.csrf_token) APPSTORE_CSRF = res.data.csrf_token;
                    EmToast.ok('已安装：' + emEsc(displayName));
                    // 只刷当前页：reloadTable 会带 page.curr=1 把人甩回第一页
                    refreshTable();
                } else {
                    EmToast.err((res && res.msg) || (actionLabel + '失败'));
                }
            }).fail(function (xhr) {
                EmToast.close(loadingIdx);
                var msg = actionLabel + '请求失败';
                try {
                    var j = JSON.parse(xhr.responseText || '{}');
                    if (j && j.msg) msg = j.msg;
                } catch (e) {}
                EmToast.err(msg);
            });
        }

        function escapeHtml(text) {
            return String(text == null ? '' : text).replace(/[&<>"']/g, function (char) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char];
            });
        }

        function ensurePayDialogStyle() {
            if (document.getElementById('appstorePayDialogStyle')) return;
            var css = ''
                + '.appstore-pay-dialog{padding:12px 14px 8px;}'
                + '.appstore-pay-dialog__meta{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 12px;margin-bottom:12px;}'
                + '.appstore-pay-dialog__line{display:flex;justify-content:space-between;align-items:center;gap:10px;font-size:13px;color:#334155;line-height:1.8;}'
                + '.appstore-pay-dialog__line strong{font-weight:600;color:#0f172a;}'
                + '.appstore-pay-dialog__line--amount strong{color:#dc2626;font-size:16px;font-family:Menlo,Consolas,monospace;}'
                + '.appstore-pay-dialog__title{font-size:13px;color:#475569;margin:2px 0 10px;}'
                + '.appstore-pay-dialog__methods{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:12px;}'
                + '.appstore-pay-dialog__method{position:relative;display:flex;align-items:center;gap:8px;border:1px solid #e2e8f0;border-radius:10px;padding:10px;cursor:pointer;background:#fff;transition:all .15s ease;}'
                + '.appstore-pay-dialog__method:hover{border-color:#c7d2fe;background:#f8faff;}'
                + '.appstore-pay-dialog__method.is-active{border-color:#6366f1;background:#eef2ff;box-shadow:0 1px 8px rgba(99,102,241,.18);}'
                + '.appstore-pay-dialog__icon{width:26px;height:26px;object-fit:contain;flex-shrink:0;}'
                + '.appstore-pay-dialog__icon--fa{display:inline-flex;align-items:center;justify-content:center;font-size:16px;color:#9ca3af;}'
                + '.appstore-pay-dialog__name{font-size:13px;color:#0f172a;font-weight:500;}'
                + '.appstore-pay-dialog__empty{padding:16px 10px;text-align:center;font-size:12px;color:#94a3b8;background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;margin-bottom:12px;}'
                + '.appstore-pay-dialog__actions{display:flex;justify-content:flex-end;gap:8px;}'
                + '.appstore-pay-dialog__confirm{min-width:190px;}'
                + '.appstore-pay-dialog__confirm-price{font:inherit;margin-left:6px;}';
            $('head').append('<style id="appstorePayDialogStyle">' + css + '</style>');
        }

        // 支付通道弹窗：订单信息、收款页地址、通道清单全部来自下单响应，
        // 通道 logo 与 pay_url 由后端补成绝对地址，前端不做拼域名的事
        function openChannelDialog(order) {
            ensurePayDialogStyle();
            order = order || {};
            var channels = Array.isArray(order.channels) ? order.channels : [];
            var appMeta  = order.app || {};
            var orderNo  = String(order.order_no || '');
            var payUrl   = String(order.pay_url || '');
            var amountNum = parseFloat(order.amount);
            var priceText = isNaN(amountNum) ? '¥--' : '¥' + amountNum.toFixed(2);
            // 默认选中第一个收款通道
            var selectedId = channels.length ? channels[0].id : '';

            var methodsHtml;
            if (!channels.length) {
                methodsHtml = '<div class="appstore-pay-dialog__empty">当前订单未返回可用支付通道</div>';
            } else {
                methodsHtml = channels.map(function (ch) {
                    var logo = String(ch.logo || '');
                    var name = String(ch.name || ch.type_label || '支付通道');
                    var icon = logo
                        ? '<img class="appstore-pay-dialog__icon" src="' + escapeHtml(logo) + '" alt="">'
                        : '<i class="fa fa-credit-card appstore-pay-dialog__icon appstore-pay-dialog__icon--fa"></i>';
                    return '<div class="appstore-pay-dialog__method' + (String(ch.id) === String(selectedId) ? ' is-active' : '') + '"'
                        + ' data-id="' + escapeHtml(ch.id) + '">'
                        + icon
                        + '<span class="appstore-pay-dialog__name">' + escapeHtml(name) + '</span>'
                        + '</div>';
                }).join('');
            }

            var lines = ''
                + '<div class="appstore-pay-dialog__line"><span>订单号</span><strong>' + escapeHtml(orderNo || '-') + '</strong></div>'
                + '<div class="appstore-pay-dialog__line"><span>应用</span><strong>' + escapeHtml(appMeta.name || '-')
                +   (appMeta.type_label ? '（' + escapeHtml(appMeta.type_label) + '）' : '') + '</strong></div>';
            if (order.license_type_label) {
                lines += '<div class="appstore-pay-dialog__line"><span>授权档位</span><strong>' + escapeHtml(order.license_type_label) + '</strong></div>';
            }
            lines += '<div class="appstore-pay-dialog__line appstore-pay-dialog__line--amount"><span>订单金额</span><strong>' + escapeHtml(priceText) + '</strong></div>';
            if (order.expires_at) {
                lines += '<div class="appstore-pay-dialog__line"><span>支付有效期至</span><strong>' + escapeHtml(order.expires_at) + '</strong></div>';
            }

            var html = ''
                + '<div class="appstore-pay-dialog">'
                +   '<div class="appstore-pay-dialog__meta">' + lines + '</div>'
                +   '<div class="appstore-pay-dialog__title">请选择支付通道</div>'
                +   '<div class="appstore-pay-dialog__methods">' + methodsHtml + '</div>'
                +   '<div class="appstore-pay-dialog__actions">'
                +     '<button type="button" class="em-btn em-save-btn appstore-pay-dialog__confirm"' + (!channels.length ? ' disabled' : '') + '>'
                +       '<i class="fa fa-shopping-cart"></i>去支付'
                +       '<span class="appstore-pay-dialog__confirm-price">' + escapeHtml(priceText) + '</span>'
                +     '</button>'
                +   '</div>'
                + '</div>';

            layer.open({
                type: 1,
                title: '选择支付通道',
                skin: 'admin-modal appstore-pay-modal',
                area: [window.innerWidth >= 640 ? '480px' : '92%', 'auto'],
                shadeClose: false,
                maxmin: false,
                content: html,
                success: function (layero, index) {
                    var $layer = $(layero);
                    $layer.on('click', '.appstore-pay-dialog__method', function () {
                        var $item = $(this);
                        selectedId = String($item.data('id') || '');
                        $item.addClass('is-active').siblings('.appstore-pay-dialog__method').removeClass('is-active');
                    });
                    $layer.on('click', '.appstore-pay-dialog__confirm', function () {
                        if (!orderNo) {
                            EmToast.err('订单号缺失，无法跳转支付');
                            return;
                        }
                        if (!payUrl) {
                            EmToast.err('未返回收款页地址，无法跳转支付');
                            return;
                        }
                        // 通道选择落在收银台上：pay_url 补 ?channel={通道 id}
                        var target = payUrl;
                        if (selectedId !== '' && selectedId !== null && selectedId !== undefined) {
                            target += (target.indexOf('?') === -1 ? '?' : '&') + 'channel=' + encodeURIComponent(selectedId);
                        }
                        // Safari 等浏览器在 noopener/noreferrer 场景可能返回 null（即使已成功打开新标签），
                        // 先打开 about:blank 再赋值 URL，避免误判导致当前页也跳转。
                        var payWin = window.open('about:blank', '_blank');
                        if (payWin) {
                            try { payWin.opener = null; } catch (e) {}
                            payWin.location.href = target;
                        } else {
                            window.location.href = target;
                        }
                    });
                }
            });
        }

        // ---------- 购买：创建订单 ----------
        function createOrder(app) {
            var id = parseInt(app.id, 10) || 0;
            if (!id) { EmToast.err('应用标识缺失，无法发起购买'); return; }
            var loadingIdx = EmToast.loading({ shade: 0.3, color: '#000', type: 2 });
            $.post('/admin/appstore.php', {
                _action: 'app_buy',
                csrf_token: APPSTORE_CSRF,
                app_id: id,
                tab: window.APPSTORE_TAB || 'main'
            }).done(function (res) {
                EmToast.close(loadingIdx);
                if (res && (res.code === 200 || res.code === 0)) {
                    if (res.data && res.data.csrf_token) APPSTORE_CSRF = res.data.csrf_token;
                    openChannelDialog((res && res.data) || {});
                } else {
                    EmToast.err((res && res.msg) || '创建订单失败');
                }
            }).fail(function (xhr) {
                EmToast.close(loadingIdx);
                var msg = '创建订单请求失败';
                try {
                    var j = JSON.parse(xhr.responseText || '{}');
                    if (j && j.msg) msg = j.msg;
                } catch (e) {}
                EmToast.err(msg);
            });
        }

        // ---------- 行操作 ----------
        table.on('tool(appstoreTable)', function (obj) {
            var d = obj.data;
            if (obj.event === 'install')          installApp(d);
            else if (obj.event === 'buy')         createOrder(d);
            else if (obj.event === 'needLicense') {
                layer.confirm('付费应用需先激活正版授权。是否前往激活？', { icon: 3, title: '提示' }, function (idx) {
                    layer.close(idx);
                    if ($.pjax) $.pjax({ url: '/admin/license.php', container: '#adminContent' });
                    else location.href = '/admin/license.php';
                });
            }
        });

    });
});
</script>
