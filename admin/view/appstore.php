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

/* ===== 「激活后安装」引导弹窗 ===== */
.asn { padding: 26px 24px 20px; text-align: center; }
.asn__icon {
    width: 56px; height: 56px;
    margin: 0 auto 14px;
    border-radius: 16px;
    background: linear-gradient(135deg, #6366f1, #0ea5e9);
    color: #fff; font-size: 24px;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.28);
}
.asn__title { font-size: 18px; font-weight: 700; color: #0f172a; letter-spacing: 0.3px; }
.asn__sub { margin-top: 8px; font-size: 12.5px; color: #6b7280; line-height: 1.7; }
.asn__btn {
    flex: 1; height: 40px;
    border-radius: 10px;
    font-size: 13.5px; font-weight: 600;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease, filter 0.15s ease;
}
.asn__btn--primary {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: #fff; border: 0;
    box-shadow: 0 6px 16px rgba(79, 70, 229, 0.28);
}
.asn__btn--primary:hover { filter: brightness(1.06); }
.asn__note { margin-top: 16px; font-size: 11.5px; color: #9ca3af; }
.asn__note i { margin-right: 4px; }
.asn__note a { color: #6366f1; text-decoration: none; margin-left: 2px; }
.asn__note a:hover { text-decoration: underline; }
/* 激活表单 */
.asn__field { position: relative; margin-top: 18px; text-align: left; }
.asn__field > i {
    position: absolute; left: 14px; top: 50%;
    transform: translateY(-50%);
    color: #9ca3af; font-size: 13px;
    pointer-events: none;
}
.asn__input {
    width: 100%; height: 46px;
    padding: 0 14px 0 38px;
    border: 1.5px solid #e5e7eb; border-radius: 12px;
    background: #fafbfc;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    font-size: 13.5px; letter-spacing: 0.5px;
    outline: none; box-sizing: border-box;
    transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
}
.asn__input::placeholder { color: #9ca3af; letter-spacing: 0.3px; }
.asn__input:focus {
    border-color: #6366f1; background: #fff;
    box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.12);
}
/* 激活按钮内嵌在输入框右侧 */
.asn__field--inline .asn__input { padding-right: 108px; }
.asn__inline-btn {
    position: absolute; top: 5px; right: 5px; bottom: 5px;
    padding: 0 16px;
    border: 0; border-radius: 9px;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: #fff;
    font-size: 13px; font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    box-shadow: 0 4px 10px rgba(79, 70, 229, 0.28);
    transition: filter 0.15s ease;
}
.asn__inline-btn:hover { filter: brightness(1.06); }
.asn__inline-btn:disabled { opacity: 0.65; cursor: not-allowed; box-shadow: none; }

/* ===== 获取正版激活码弹窗（与后台首页同一套设计） ===== */
.asn--buy { padding: 22px 24px 18px; }
.asn--buy .asn__title { margin-bottom: 6px; }
.asn__perks {
    display: flex; flex-wrap: wrap; justify-content: center; gap: 6px;
    margin: 14px 0 16px;
}
.asn__perks span {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 9px;
    font-size: 11.5px; color: #4f46e5;
    background: #eef2ff;
    border-radius: 999px;
}
.asn__perks i { font-size: 9px; }
.asn__plans { display: flex; flex-direction: column; gap: 8px; }
.asn__plan {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    border: 1px solid #e5e7eb; border-radius: 12px;
    background: #fafbfc;
    text-decoration: none;
    text-align: left; /* 弹窗整体居中，列表里的名称/链接保持左对齐 */
    transition: border-color 0.15s ease, background 0.15s ease, box-shadow 0.15s ease;
}
.asn__plan:hover {
    border-color: #a5b4fc;
    background: #fff;
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.12);
}
.asn__plan-ico {
    width: 36px; height: 36px; flex-shrink: 0;
    border-radius: 10px;
    background: #eef2ff; color: #6366f1;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 15px;
}
.asn__plan-body {
    flex: 1; min-width: 0;
    display: flex; flex-direction: column; gap: 3px;
}
.asn__plan-name { font-size: 13.5px; font-weight: 600; color: #111827; }
.asn__plan-url {
    font-size: 11.5px; color: #9ca3af;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.asn__plan-arrow {
    flex-shrink: 0;
    font-size: 12px; color: #cbd5e1;
    transition: color 0.15s ease;
}
.asn__plan:hover .asn__plan-arrow { color: #6366f1; }
.asn__notice {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 12px; margin-bottom: 10px;
    font-size: 12px; color: #92400e;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
}
.asn__agent-empty {
    padding: 32px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
    background: #f8fafc;
    border: 1px dashed #e5e7eb;
    border-radius: 10px;
}
.asn__agent-empty i {
    display: block; margin-bottom: 8px;
    font-size: 24px; color: #d1d5db;
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
    - 未装 · 付费 · can_buy=false     → 紫色"激活后安装"（未授权，或激活码绑的不是当前域名）
    - 未装 · 免费                     → 蓝色"安装"
    - 未装 · 付费 · can_buy=true      → 红色"购买 ¥price"
    优先级：已装 > 已购买 > 限授权 > 免费 > 购买。已购买排在价格/授权之前 —— 买过的应用
    无论当前 price 与 can_buy 是什么都该直接给安装入口，不能再让用户付一次钱。

    注意"付费应用"的判定不能用 price：price 是**按你当前档位算出来的实际价**，未授权时
    服务端会退回 VIP 门槛价，可能正好是 0（实测：SVIP 档 1.00 的应用在未授权时 price=0.00），
    拿它判免费会让付费应用直接掉进"安装"分支、绕过授权。所以用档位原价
    price_vip / price_svip 判付费；真·免费应用（三个价都是 0）仍不需要激活码。

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
        <a class="em-btn em-sm-btn em-purple-btn" lay-event="needLicense"><i class="fa fa-shield"></i>激活后安装</a>
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
                var msg = (res && res.msg) || '';

                /* 未登录时把接口里的「登录官方账号」做成可点击链接 */
                if (msg.indexOf('登录官方账号') !== -1) {
                    msg = msg.replace(
                        '登录官方账号',
                        '<a href="javascript:void(0);" class="appstore-login-link">登录官方账号</a>'
                    );
                }

                return {
                    code: res.code === 200 ? 0 : (res.code || 500),
                    msg:  msg,
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
                + '.appstore-qr-pay{padding:0 0 18px;text-align:center;background:#f8fafc;}'
                + '.appstore-qr-pay__hero{position:relative;overflow:hidden;margin:14px 14px 0;padding:16px;border-radius:14px;background:#fff;border:1px solid #e8eefb;box-shadow:0 6px 18px rgba(15,23,42,.05);text-align:left;}'
                + '.appstore-qr-pay__hero-main{display:flex;align-items:center;justify-content:space-between;gap:12px;}'
                + '.appstore-qr-pay__hero-info{min-width:0;}'
                + '.appstore-qr-pay__price{flex-shrink:0;display:flex;flex-direction:column;align-items:flex-end;gap:1px;}'
                + '.appstore-qr-pay__price-label{font-size:10.5px;color:#94a3b8;letter-spacing:.5px;}'
                + '.appstore-qr-pay__price-amount{display:flex;align-items:baseline;gap:2px;font-family:Menlo,Consolas,monospace;color:#e11d48;}'
                + '.appstore-qr-pay__price-amount small{font-size:17px;font-weight:600;color:#e11d48;}'
                + '.appstore-qr-pay__price-amount span{font-size:20px;font-weight:700;letter-spacing:.3px;line-height:1.1;}'
                + '.appstore-qr-pay__app{font-size:13.5px;font-weight:600;color:#0f172a;line-height:1.4;word-break:break-all;}'
                + '.appstore-qr-pay__body{padding:16px 20px 0;}'
                + '.appstore-qr-pay__qrwrap{position:relative;width:200px;height:200px;margin:0 auto;padding:16px;box-sizing:border-box;display:flex;align-items:center;justify-content:center;background:#fff;border:1px solid #e6edf7;border-radius:14px;box-shadow:0 10px 26px rgba(15,23,42,.08);}'
                + '.appstore-qr-pay__qrwrap::before,.appstore-qr-pay__qrwrap::after{content:"";position:absolute;width:16px;height:16px;border:2px solid #1677ff;opacity:.55;}'
                + '.appstore-qr-pay__qrwrap::before{top:-3px;left:-3px;border-right:0;border-bottom:0;border-top-left-radius:9px;}'
                + '.appstore-qr-pay__qrwrap::after{bottom:-3px;right:-3px;border-left:0;border-top:0;border-bottom-right-radius:9px;}'
                + '.appstore-qr-pay__box{position:relative;width:168px;height:168px;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;}'
                + '.appstore-qr-pay__box canvas,.appstore-qr-pay__box img{display:block;}'
                + '.appstore-qr-pay__mask{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;background:rgba(255,255,255,.96);font-size:12.5px;color:#64748b;text-align:center;padding:0 14px;border-radius:8px;}'
                + '.appstore-qr-pay__mask.is-ok i{color:#10b981;}'
                + '.appstore-qr-pay__tip{font-size:12px;color:#64748b;margin-top:12px;}'
                + '.appstore-qr-pay__tip i{color:#1677ff;margin-right:5px;}'
                + '.appstore-qr-pay__order{margin-top:5px;font-size:11.5px;color:#64748b;font-family:\'JetBrains Mono\',Consolas,Monaco,monospace;}'
                + '.appstore-qr-pay__countdown{margin-top:8px;font-size:11.5px;color:#9ca3af;}'
                + '.appstore-qr-pay__countdown i{margin-right:4px;}'
                + '.appstore-qr-pay__countdown em{font-style:normal;}'
                + '.appstore-qr-pay__channels{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin-top:16px;}'
                + '.appstore-qr-pay__channel{display:inline-flex;align-items:center;gap:6px;padding:9px 15px;border:1px solid #e8edf5;border-radius:5px;background:#fff;cursor:pointer;font-size:13px;color:#475569;transition:all .15s ease;}'
                + '.appstore-qr-pay__channel img{width:18px;height:18px;object-fit:contain;}'
                + '.appstore-qr-pay__channel:hover{border-color:#c7d2fe;background:#fff;color:#4338ca;}'
                + '.appstore-qr-pay__channel.is-active{border-color:#a5b4fc;background:#eef2ff;color:#4338ca;font-weight:500;box-shadow:0 0 0 2px rgba(99,102,241,.12);}'
                + '.appstore-qr-pay__channels-empty{padding:10px;font-size:12px;color:#94a3b8;}';
            $('head').append('<style id="appstorePayDialogStyle">' + css + '</style>');
        }

        // 站内扫码支付：先选支付通道，再按通道取二维码，直接在本页展示并轮询结果。
        function openQrPayDialog(order) {
            ensurePayDialogStyle();
            order = order || {};
            var appMeta   = order.app || {};
            var orderNo   = String(order.order_no || '');
            var amountNum = parseFloat(order.amount);
            var amountText = isNaN(amountNum) ? '--' : amountNum.toFixed(2);
            // 「VIP 应付金额」/「SVIP 应付金额」；没有档位就是「应付金额」
            var levelLabel = String(order.license_type_label || '').trim();
            var payLabel = (levelLabel !== '' ? levelLabel : '') + '应付金额';
            var appText = escapeHtml(appMeta.name || '应用')
                + (appMeta.type_label ? ' · ' + escapeHtml(appMeta.type_label) : '');

            // 支付通道来自下单响应；默认选中第一个
            var channels = Array.isArray(order.channels) ? order.channels : [];
            var selectedChannelId = channels.length ? String(channels[0].id) : '';
            var channelsHtml = channels.length
                ? channels.map(function (ch) {
                    var logo = String(ch.logo || '');
                    return '<button type="button" class="appstore-qr-pay__channel'
                        + (String(ch.id) === selectedChannelId ? ' is-active' : '') + '"'
                        + ' data-id="' + escapeHtml(ch.id) + '">'
                        + (logo
                            ? '<img src="' + escapeHtml(logo) + '" alt="">'
                            : '<i class="fa fa-credit-card" style="color:#94a3b8;"></i>')
                        + '<span>' + escapeHtml(String(ch.name || ch.type_label || '支付通道')) + '</span>'
                        + '</button>';
                }).join('')
                : '<div class="appstore-qr-pay__channels-empty">当前订单没有可用的支付通道</div>';

            var html = ''
                + '<div class="appstore-qr-pay">'
                +   '<div class="appstore-qr-pay__hero">'
                +     '<div class="appstore-qr-pay__hero-main">'
                +       '<div class="appstore-qr-pay__hero-info">'
                +         '<div class="appstore-qr-pay__app">' + appText + '</div>'
                +         '<div class="appstore-qr-pay__order">订单号 ' + escapeHtml(orderNo || '-') + '</div>'
                +       '</div>'
                +       '<div class="appstore-qr-pay__price">'
                +         '<span class="appstore-qr-pay__price-label">' + escapeHtml(payLabel) + '</span>'
                +         '<span class="appstore-qr-pay__price-amount"><small>¥</small><span>' + escapeHtml(amountText) + '</span></span>'
                +       '</div>'
                +     '</div>'
                +   '</div>'
                +   '<div class="appstore-qr-pay__body">'
                +   '<div class="appstore-qr-pay__qrwrap">'
                +     '<div class="appstore-qr-pay__box" id="appstoreQrBox">'
                +       '<div class="appstore-qr-pay__mask" id="appstoreQrMask">'
                +         '<i class="fa fa-spinner fa-spin" style="font-size:22px;color:#6366f1;"></i>'
                +         '<span>正在生成支付二维码…</span>'
                +       '</div>'
                +     '</div>'
                +   '</div>'
                +   '<div class="appstore-qr-pay__tip"><i class="fa fa-mobile" style="font-size:14px;"></i>请使用手机扫一扫完成支付</div>'
                +   '<div class="appstore-qr-pay__countdown"><i class="fa fa-clock-o"></i><em id="appstoreQrExpire"></em></div>'
                +   '<div class="appstore-qr-pay__channels" id="appstoreQrChannels">' + channelsHtml + '</div>'
                +   '</div>'
                + '</div>';

            var pollTimer = null;
            var expireTimer = null;
            var finished = false;

            var layerIdx = layer.open({
                type: 1,
                title: '扫码支付',
                skin: 'admin-modal',
                area: [window.innerWidth >= 640 ? '420px' : '92%', 'auto'],
                shadeClose: false,
                maxmin: false,
                content: html,
                end: function () {
                    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                    if (expireTimer) { clearInterval(expireTimer); expireTimer = null; }
                }
            });

            function showMask(iconHtml, text, ok) {
                $('#appstoreQrMask').toggleClass('is-ok', !!ok).show().html(iconHtml + '<span>' + escapeHtml(text) + '</span>');
            }

            function stopPolling() {
                if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
            }

            function onPaid() {
                if (finished) return;
                finished = true;
                stopPolling();
                showMask('<i class="fa fa-check-circle" style="font-size:28px;"></i>', '支付成功', true);
                EmToast.ok('支付成功');
                setTimeout(function () {
                    layer.close(layerIdx);
                    refreshTable();
                }, 1200);
            }

            function startExpireCountdown(expiresAt) {
                var end = new Date(String(expiresAt).replace(' ', 'T')).getTime();
                if (isNaN(end)) return;
                function tick() {
                    var left = Math.floor((end - Date.now()) / 1000);
                    if (left <= 0) {
                        $('#appstoreQrExpire').text('已过期');
                        return;
                    }
                    // 超过 1 小时用「x 小时 y 分」，否则「x 分 y 秒」/「x 秒」，比 mm:ss 好懂
                    if (left >= 3600) {
                        $('#appstoreQrExpire').text('剩余 ' + Math.floor(left / 3600) + ' 小时 ' + Math.floor((left % 3600) / 60) + ' 分');
                    } else if (left >= 60) {
                        $('#appstoreQrExpire').text('剩余 ' + Math.floor(left / 60) + ' 分 ' + (left % 60) + ' 秒');
                    } else {
                        $('#appstoreQrExpire').text('剩余 ' + left + ' 秒');
                    }
                }
                tick();
                expireTimer = setInterval(tick, 1000);
            }

            function startPolling() {
                pollTimer = setInterval(function () {
                    $.post('/admin/appstore.php', {
                        _action: 'app_order_status',
                        csrf_token: APPSTORE_CSRF,
                        order_no: orderNo
                    }).done(function (res) {
                        if (finished || !res || (res.code !== 200 && res.code !== 0)) return;
                        var d = res.data || {};
                        if (d.state === 'paid') {
                            onPaid();
                            return;
                        }
                        if (d.state === 'expired' || d.state === 'closed') {
                            stopPolling();
                            showMask('<i class="fa fa-clock-o" style="font-size:26px;color:#9ca3af;"></i>', '订单已失效，请重新购买');
                        }
                    });
                }, 2500);
            }

            // 每次换通道都重新出码；用序号丢弃过期的响应（快速切换时不会串码）
            var qrSeq = 0;

            function loadQr() {
                if (finished) return;
                stopPolling();
                if (expireTimer) { clearInterval(expireTimer); expireTimer = null; }
                $('#appstoreQrBox').find('canvas, img').remove();
                $('#appstoreQrExpire').text('');
                $('#appstoreQrMask').removeClass('is-ok').show().html(
                    '<i class="fa fa-spinner fa-spin" style="font-size:22px;color:#6366f1;"></i>' +
                    '<span>正在生成支付二维码…</span>'
                );

                var seq = ++qrSeq;
                $.post('/admin/appstore.php', {
                    _action: 'app_pay_qrcode',
                    csrf_token: APPSTORE_CSRF,
                    order_no: orderNo,
                    channel_id: selectedChannelId
                }).done(function (res) {
                    if (seq !== qrSeq) return;
                    if (res && (res.code === 200 || res.code === 0)) {
                        if (res.data && res.data.csrf_token) APPSTORE_CSRF = res.data.csrf_token;
                        var d = res.data || {};

                        if (!d.qr_code) {
                            showMask('<i class="fa fa-exclamation-circle" style="font-size:26px;color:#f59e0b;"></i>', '未返回支付二维码');
                            return;
                        }
                        if (!window.QRCode) {
                            showMask('<i class="fa fa-exclamation-circle" style="font-size:26px;color:#f59e0b;"></i>', '二维码组件未加载，请刷新页面重试');
                            return;
                        }

                        $('#appstoreQrMask').hide().empty();
                        new QRCode(document.getElementById('appstoreQrBox'), {
                            text: String(d.qr_code),
                            width: 168,
                            height: 168,
                            colorDark: '#0f172a',
                            colorLight: '#ffffff',
                            correctLevel: QRCode.CorrectLevel.M
                        });

                        if (d.expires_at) startExpireCountdown(d.expires_at);
                        startPolling();
                        return;
                    }
                    showMask(
                        '<i class="fa fa-exclamation-circle" style="font-size:26px;color:#f59e0b;"></i>',
                        (res && res.msg) || '二维码生成失败'
                    );
                }).fail(function () {
                    if (seq !== qrSeq) return;
                    showMask('<i class="fa fa-exclamation-circle" style="font-size:26px;color:#f59e0b;"></i>', '网络异常，请稍后重试');
                });
            }

            $('#appstoreQrChannels').on('click', '.appstore-qr-pay__channel', function () {
                var id = String($(this).data('id') || '');
                if (id === selectedChannelId) return;
                selectedChannelId = id;
                $(this).addClass('is-active').siblings().removeClass('is-active');
                loadQr();
            });

            if (channels.length) {
                loadQr();
            } else {
                showMask('<i class="fa fa-exclamation-circle" style="font-size:26px;color:#f59e0b;"></i>', '当前订单没有可用的支付通道');
            }
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
                    openQrPayDialog((res && res.data) || {});
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

        // 获取正版激活码：与后台首页完全一致（460px、auto 高度、同样的卡片设计）。
        // 数据走 /admin/license.php 的 agent_links（与首页同一个 base-data），本页缓存一份。
        var __agentLinksCache = null;

        function openAgentConfigPopup() {
            if (__agentLinksCache) { renderAgentPopup(__agentLinksCache); return; }

            var loadIdx = layer.load(2);
            $.post('/admin/license.php', {
                _action: 'agent_links',
                csrf_token: APPSTORE_CSRF
            }).done(function (res) {
                layer.close(loadIdx);
                if (res && res.code === 200) {
                    if (res.data && res.data.csrf_token) APPSTORE_CSRF = res.data.csrf_token;
                    __agentLinksCache = res.data || {};
                    renderAgentPopup(__agentLinksCache);
                    return;
                }
                layer.msg((res && res.msg) || '获取购买渠道失败');
            }).fail(function () {
                layer.close(loadIdx);
                layer.msg('网络异常，请稍后重试');
            });
        }

        function renderAgentPopup(data) {
            var links   = (data && data.buy_links) || [];
            var contact = (data && data.contact) || {};

            // 底部联系方式（与首页一致：客服QQ / 官方交流QQ群）
            var parts = [];
            if (contact.qq_service) parts.push('客服QQ：' + escapeHtml(contact.qq_service));
            if (contact.qq_group)   parts.push('官方交流QQ群：' + escapeHtml(contact.qq_group));
            var note = parts.length
                ? '<div class="asn__note"><i class="fa fa-headphones"></i>有任何问题，请联系' + parts.join('　') + '</div>'
                : '';

            var body;
            if (!links.length) {
                body = '<div class="asn__agent-empty"><i class="fa fa-inbox"></i>暂未提供购买渠道，请联系客服获取</div>';
            } else {
                var notice = data.used_fallback
                    ? '<div class="asn__notice"><i class="fa fa-info-circle"></i>当前身份标识未匹配到代理商，以下为官方通用配置</div>'
                    : '';
                body = notice + '<div class="asn__plans">' + links.map(function (u) {
                    return '<a href="' + escapeHtml(u.url) + '" target="_blank" rel="noopener noreferrer" class="asn__plan">' +
                               '<span class="asn__plan-ico"><i class="fa fa-shopping-cart"></i></span>' +
                               '<span class="asn__plan-body">' +
                                   '<span class="asn__plan-name">' + escapeHtml(u.name || u.url) + '</span>' +
                                   '<span class="asn__plan-url">' + escapeHtml(u.url) + '</span>' +
                               '</span>' +
                               '<i class="fa fa-arrow-right asn__plan-arrow"></i>' +
                           '</a>';
                }).join('') + '</div>';
            }

            layer.open({
                type: 1,
                title: '获取正版激活码',
                skin: 'admin-modal',
                area: [window.innerWidth >= 640 ? '460px' : '94%', 'auto'],
                shadeClose: true,
                content:
                    '<div class="asn asn--buy">' +
                        '<div class="asn__icon"><i class="fa fa-shopping-bag"></i></div>' +
                        '<div class="asn__title">获取正版激活码</div>' +
                        '<div class="asn__sub">选择下方渠道购买，拿到激活码后回到本页激活</div>' +
                        '<div class="asn__perks">' +
                            '<span><i class="fa fa-check"></i>专属模板</span>' +
                            '<span><i class="fa fa-check"></i>付费插件</span>' +
                            '<span><i class="fa fa-check"></i>应用商店</span>' +
                            '<span><i class="fa fa-check"></i>优先支持</span>' +
                        '</div>' +
                        '<div class="asn__buy-body">' + body + '</div>' +
                        note +
                    '</div>'
            });
        }

        // 立即激活弹窗（与后台首页一致）：激活「后安装」按钮直接弹这个
        function openActivatePopup() {
            layer.open({
                type: 1,
                title: '立即激活',
                skin: 'admin-modal',
                area: [window.innerWidth >= 640 ? '460px' : '94%', 'auto'],
                shadeClose: false,
                content:
                    '<div class="asn">' +
                        '<div class="asn__icon"><i class="fa fa-shield"></i></div>' +
                        '<div class="asn__title">激活正版 EMSHOP</div>' +
                        '<div class="asn__sub">输入授权许可证（License Key），激活后即可安装付费应用</div>' +
                        '<div class="asn__field asn__field--inline">' +
                            '<i class="fa fa-key"></i>' +
                            '<input type="text" id="asnLicenseCode" class="asn__input" ' +
                                   'placeholder="请输入您的激活码" maxlength="64" autocomplete="off" spellcheck="false">' +
                            '<button type="button" class="asn__inline-btn" id="asnActivate">立即激活</button>' +
                        '</div>' +
                        '<div class="asn__note">还没有激活码？' +
                            '<a href="javascript:void(0);" id="asnGetCode">获取正版授权</a>' +
                        '</div>' +
                    '</div>'
            });

            function submitActivate() {
                var code = $.trim($('#asnLicenseCode').val() || '');
                if (code === '') { $('#asnLicenseCode').focus(); layer.msg('请输入激活码'); return; }

                var $btn = $('#asnActivate');
                var origHtml = $btn.html();
                $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 激活中');
                $.ajax({
                    url: '/admin/license.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _action: 'activate',
                        license_code: code,
                        csrf_token: window.adminCsrfToken || ''
                    }
                }).done(function (res) {
                    if (res && res.code === 200) {
                        if (res.data && res.data.csrf_token) window.adminCsrfToken = res.data.csrf_token;
                        layer.msg('激活成功：' + ((res.data && res.data.level_label) || ''));
                        setTimeout(function () { location.reload(); }, 900);
                        return;
                    }
                    layer.msg((res && res.msg) || '激活失败');
                    $btn.prop('disabled', false).html(origHtml);
                }).fail(function () {
                    layer.msg('网络异常');
                    $btn.prop('disabled', false).html(origHtml);
                });
            }

            $('#asnActivate').on('click', submitActivate);
            $('#asnLicenseCode').on('keydown', function (e) {
                if (e.key === 'Enter') submitActivate();
            }).focus();
            // 购买渠道叠在上层，激活窗口保持打开
            $('#asnGetCode').on('click', function () {
                openAgentConfigPopup();
            });
        }

        // ---------- 行操作 ----------
        table.on('tool(appstoreTable)', function (obj) {
            var d = obj.data;
            if (obj.event === 'install')          installApp(d);
            else if (obj.event === 'buy')         createOrder(d);
            else if (obj.event === 'needLicense') openActivatePopup();
        });

    });
});
</script>

<?php if (empty($officialLoggedIn)): ?>
<?php include __DIR__ . '/appstore_login_guard.php'; ?>
<?php endif; ?>
