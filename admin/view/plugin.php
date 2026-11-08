<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
?>
<style>
/* 和控制台 / 模板管理一致：去掉 .admin-page 默认白底，卡片浮在灰底画布上 */
.admin-page-plugin { padding: 8px 4px 40px; background: unset; }

/* 顶部工具条：刷新 + 应用商店（参考模板管理）；不再有搜索 */
.plugin-filter-bar { display:flex; align-items:center; gap:8px; margin-bottom:16px; }
.plugin-appstore-btn { height:32px; padding:0 14px; border:1px solid rgba(99,102,241,.3); border-radius:8px; background:rgba(99,102,241,.06); font-size:13px; font-weight:500; color:#6366f1; cursor:pointer; display:flex; align-items:center; gap:6px; transition:all .15s; }
.plugin-appstore-btn:hover { background:rgba(99,102,241,.12); border-color:rgba(99,102,241,.5); }

/* 选项卡行：em-tabs 本身已有边框圆角，margin-bottom 已经在公共样式里 */

/* ===== 加载骨架屏 ===== */
.plugin-skeleton {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}
.plugin-skeleton-card {
    background: #fff;
    border: 1px solid #f0f0f0;
    border-radius: 14px;
    padding: 16px;
    animation: skeleton-pulse 1.5s ease-in-out infinite;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
}
.skeleton-header { display: flex; gap: 12px; margin-bottom: 12px; }
.skeleton-icon { width: 48px; height: 48px; border-radius: 10px; background: #f0f0f0; flex-shrink: 0; }
.skeleton-lines { flex: 1; }
.skeleton-line { height: 12px; background: #f0f0f0; border-radius: 6px; margin-bottom: 8px; }
.skeleton-line--short { width: 60%; }
.skeleton-line--medium { width: 80%; }
.skeleton-desc { height: 10px; background: #f0f0f0; border-radius: 5px; margin-bottom: 6px; }
@keyframes skeleton-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }

/* ===== 卡片网格 ===== */
.plugin-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; }

/* ===== 插件卡片 ===== */
.plugin-card {
    background: #fff;
    border: 1px solid #e8e8ec;
    border-radius: 14px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    position: relative;
    transition: border-color .2s, box-shadow .2s, transform .2s;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
}
.plugin-card:hover { border-color: #a5b4fc; box-shadow: 0 4px 20px rgba(99, 102, 241, 0.1); transform: translateY(-1px); }

/* 卡片头部 */
.plugin-card__header { display: flex; align-items: center; gap: 12px; padding: 16px 16px 12px; }
.plugin-card__icon-wrap { flex-shrink: 0; position: relative; cursor: pointer; }
.plugin-card__icon { width: 48px; height: 48px; border-radius: 10px; object-fit: cover; }
.plugin-card__icon--default { width: 48px; height: 48px; border-radius: 5px; background: linear-gradient(135deg, rgba(99, 102, 241, 0.1), rgba(129, 140, 248, 0.08)); display: flex; align-items: center; justify-content: center; color: #4f46e5; font-size: 22px; }
.plugin-card__info { flex: 1; min-width: 0; }
.plugin-card__title-row { display: flex; align-items: center; gap: 8px; }
.plugin-card__title { font-size: 15px; font-weight: 600; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.plugin-card__meta { display: flex; align-items: center; gap: 6px; margin-top: 4px; flex-wrap: wrap; }
.plugin-card__version { font-size: 12px; color: #9ca3af; }
.plugin-card__category { display: inline-flex; align-items: center; font-size: 12px; font-weight: 500; color: #9ca3af; background: rgba(0, 0, 0, 0.05); padding: 2px 8px; border-radius: 5px; flex-shrink: 0; }

/* 更多操作按钮 */
.plugin-card__more { position: relative; flex-shrink: 0; }
.plugin-card__more-btn { width: 28px; height: 28px; border: none; background: transparent; border-radius: 6px; cursor: pointer; color: #d1d5db; font-size: 16px; display: flex; align-items: center; justify-content: center; transition: all .15s; }
.plugin-card__more-btn:hover { background: #f5f5f7; color: #6b7280; }
.plugin-card__more-dropdown { position: absolute; top: calc(100% + 4px); right: 0; background: #fff; border: 1px solid #e8e8ec; border-radius: 8px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1); min-width: 120px; z-index: 100; overflow: hidden; display: none; }
.plugin-card__more-dropdown--open { display: block; }
.plugin-card__more-item { display: flex; align-items: center; gap: 8px; padding: 9px 12px; font-size: 13px; color: #374151; cursor: pointer; border: none; background: transparent; width: 100%; text-align: left; transition: background .1s; border-radius: 0; }
.plugin-card__more-item:hover { background: #f5f5f7; }
.plugin-card__more-item--danger { color: #dc2626; }
.plugin-card__more-item--danger:hover { background: rgba(220, 38, 38, 0.04); }
.plugin-card__more-item i { width: 14px; text-align: center; font-size: 12px; }

/* 卡片内容 */
.plugin-card__body { padding: 0 16px 12px; flex: 1; }
.plugin-card__author { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: #9ca3af; }
.plugin-card__author a { color: #4f46e5; text-decoration: none; }
.plugin-card__author a:hover { text-decoration: underline; }
.plugin-card__meta-row { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.plugin-card__status { display: inline-flex; align-items: center; font-size: 12px; font-weight: 500; padding: 2px 8px; border-radius: 5px; flex-shrink: 0; }
.plugin-card__status--enabled { background: rgba(5, 150, 105, 0.9); color: #fff; animation: statusBreath 2s ease-in-out infinite; }
.plugin-card__status--disabled { background: rgba(0, 0, 0, 0.05); color: #9ca3af; }
@keyframes statusBreath {
    0%, 100% { background: rgba(5, 150, 105, 0.9); box-shadow: 0 0 0 0 rgba(5, 150, 105, 0); }
    50% { background: rgba(5, 150, 105, 0.98); box-shadow: 0 0 0 3px rgba(5, 150, 105, 0.15); }
}
.plugin-card__desc { font-size: 13px; color: #6b7280; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 2; line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }

/* 卡片底部操作栏 */
.plugin-card__footer { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 16px; border-top: 1px solid #f5f5f7; }

/* 开关 */
.plugin-toggle { position: relative; width: 40px; height: 22px; flex-shrink: 0; }
.plugin-toggle input { opacity: 0; width: 0; height: 0; }
.plugin-toggle__slider { position: absolute; inset: 0; background: #e5e7eb; border-radius: 22px; cursor: pointer; transition: background .2s; }
.plugin-toggle__slider::before { content: ''; position: absolute; width: 16px; height: 16px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: transform .2s; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15); }
.plugin-toggle input:checked + .plugin-toggle__slider { background: #4f46e5; }
.plugin-toggle input:checked + .plugin-toggle__slider::before { transform: translateX(18px); }
.plugin-toggle input:disabled + .plugin-toggle__slider { opacity: 0.5; cursor: not-allowed; }

/* 底部主操作按钮 */
.plugin-card__action { height: 32px; padding: 0 14px; border: none; border-radius: 7px; font-size: 12px; font-weight: 500; cursor: pointer; transition: all .15s; font-family: inherit; display: flex; align-items: center; gap: 5px; }
.plugin-card__action--install { background: rgba(99, 102, 241, 0.1); color: #4f46e5; }
.plugin-card__action--install:hover { background: rgba(99, 102, 241, 0.18); }
.plugin-card__action--settings { background: rgba(59, 130, 246, 0.08); color: #2563eb; }
.plugin-card__action--settings:hover { background: rgba(59, 130, 246, 0.15); }
.plugin-card__action--update { background: #f59e0b; color: #fff; }
.plugin-card__action--update:hover { background: #d97706; color: #fff; }
.plugin-card__action--price { background: rgba(245, 158, 11, 0.1); color: #d97706; }
.plugin-card__action--price:hover { background: rgba(245, 158, 11, 0.18); }
.plugin-card__action--disabled { background: rgba(0, 0, 0, 0.04) !important; color: #c9cdd4 !important; cursor: not-allowed !important; }
.plugin-card__price { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; color:#92400e; background:#fffbeb; border:1px solid #fde68a; border-radius:7px; padding:6px 9px; }
.plugin-card__price i { font-size:11px; }

/* ===== 空状态：与插件卡片同款白卡，浮在灰底画布上 ===== */
.plugin-empty { display: flex; justify-content: center; padding: 32px 20px 72px; }
.plugin-empty__panel {
    width: 100%;
    max-width: 460px;
    padding: 40px 32px 36px;
    text-align: center;
    background: #fff;
    border: 1px solid #e8e8ec;
    border-radius: 14px;
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
    animation: pluginEmptyIn .26s ease-out both;
}
@keyframes pluginEmptyIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

/* 插画：渐变圆角块 + 光晕 + 装饰点 */
.plugin-empty__art { position: relative; width: 96px; height: 96px; margin: 0 auto 22px; }
.plugin-empty__art-ring { position: absolute; inset: 0; border-radius: 50%; background: radial-gradient(circle at 50% 42%, rgba(99, 102, 241, 0.16), rgba(99, 102, 241, 0) 70%); }
.plugin-empty__art-core {
    position: absolute; left: 50%; top: 50%;
    width: 64px; height: 64px; margin: -32px 0 0 -32px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 20px;
    background: linear-gradient(135deg, #6366f1, #818cf8);
    color: #fff; font-size: 26px;
    box-shadow: 0 8px 20px rgba(99, 102, 241, 0.28);
}
.plugin-empty__dot { position: absolute; border-radius: 50%; background: #c7d2fe; }
.plugin-empty__dot--1 { width: 9px; height: 9px; left: 0; top: 30px; }
.plugin-empty__dot--2 { width: 6px; height: 6px; right: 4px; top: 12px; opacity: .75; }
.plugin-empty__dot--3 { width: 11px; height: 11px; right: 10px; bottom: 4px; opacity: .5; }

.plugin-empty__text { font-size: 16px; font-weight: 600; color: #111827; margin-bottom: 8px; }
.plugin-empty__sub { font-size: 13px; color: #9ca3af; line-height: 1.7; margin-bottom: 26px; }
.plugin-empty__sub code { background: #f5f5f7; padding: 2px 8px; border-radius: 5px; font-family: 'Courier New', monospace; font-size: 12px; color: #4f46e5; }

/* 空状态操作按钮 */
.plugin-empty__actions { display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap; }
.plugin-empty__btn {
    height: 36px; padding: 0 18px;
    border: none; border-radius: 8px;
    font-family: inherit; font-size: 13px; font-weight: 500;
    display: inline-flex; align-items: center; gap: 6px;
    cursor: pointer;
    transition: background .15s, border-color .15s, color .15s, box-shadow .15s;
}
.plugin-empty__btn--primary { background: linear-gradient(135deg, #4f46e5, #6366f1); color: #fff; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.25); }
.plugin-empty__btn--primary:hover { background: linear-gradient(135deg, #4338ca, #4f46e5); }
.plugin-empty__btn--ghost { background: #fff; border: 1px solid #e5e7eb; color: #4b5563; }
.plugin-empty__btn--ghost:hover { background: #f8f9ff; border-color: #c7d2fe; color: #4f46e5; }
</style>

<div class="admin-page admin-page-plugin">
    <h1 class="admin-page__title">插件管理</h1>

    <!-- 工具条：刷新 + 应用商店 -->
    <div class="plugin-filter-bar">
        <a class="em-btn em-reset-btn" id="pluginRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <button class="plugin-appstore-btn" id="pluginAppstoreBtn" type="button"><i class="fa fa-shopping-basket"></i> 去逛逛应用商店</button>
    </div>

    <!-- 分类筛选（em-tabs + em-tabs__count，动态填数字） -->
    <div class="em-tabs" id="pluginTabs">
        <a class="em-tabs__item is-active" data-filter="all"><i class="fa fa-puzzle-piece"></i>我的插件<em class="em-tabs__count" id="countAll"></em></a>
        <a class="em-tabs__item" data-filter="enabled"><i class="fa fa-bolt"></i>已启用<em class="em-tabs__count" id="countEnabled"></em></a>
        <a class="em-tabs__item" data-filter="disabled"><i class="fa fa-ban"></i>已禁用<em class="em-tabs__count" id="countDisabled"></em></a>
        <a class="em-tabs__item" data-filter="merchant"><i class="fa fa-cubes"></i>分站插件<em class="em-tabs__count" id="countMerchant"></em></a>
    </div>

    <!-- 骨架屏 -->
    <div class="plugin-skeleton" id="pluginSkeleton">
        <?php for ($i = 0; $i < 3; $i++): ?>
        <div class="plugin-skeleton-card">
            <div class="skeleton-header">
                <div class="skeleton-icon"></div>
                <div class="skeleton-lines">
                    <div class="skeleton-line skeleton-line--short"></div>
                    <div class="skeleton-line skeleton-line--medium"></div>
                </div>
            </div>
            <div class="skeleton-desc" style="width:90%"></div>
            <div class="skeleton-desc" style="width:70%"></div>
        </div>
        <?php endfor; ?>
    </div>

    <!-- 插件卡片网格 -->
    <div class="plugin-grid" id="pluginGrid" style="display:none;"></div>

    <!-- 空状态：主站无插件 / 分站未上架两套文案，由 setEmptyState() 切换 -->
    <div class="plugin-empty" id="pluginEmpty" style="display:none;">
        <div class="plugin-empty__panel">
            <div class="plugin-empty__art">
                <span class="plugin-empty__art-ring"></span>
                <span class="plugin-empty__art-core"><i class="fa fa-puzzle-piece"></i></span>
                <span class="plugin-empty__dot plugin-empty__dot--1"></span>
                <span class="plugin-empty__dot plugin-empty__dot--2"></span>
                <span class="plugin-empty__dot plugin-empty__dot--3"></span>
            </div>
            <div class="plugin-empty__text">暂未安装任何插件</div>
            <div class="plugin-empty__sub">请将插件包解压后放入 <code>content/plugin/</code> 目录</div>
            <div class="plugin-empty__actions">
                <button type="button" class="plugin-empty__btn plugin-empty__btn--primary" id="pluginEmptyAppstoreBtn"><i class="fa fa-shopping-basket"></i> 去应用商店安装</button>
                <button type="button" class="plugin-empty__btn plugin-empty__btn--ghost" id="pluginEmptyRefreshBtn"><i class="fa fa-refresh"></i> 刷新页面</button>
            </div>
        </div>
    </div>
</div>

<script>
layui.use(function(){
    'use strict';
    var $ = layui.$;
    var layer = layui.layer;

    var csrfToken = <?php echo json_encode($csrfToken); ?>;
    var allPlugins = [];
    var merchantPlugins = [];
    var merchantCount = 0;
    var merchantLoaded = false;
    var currentFilter = 'all';
    var loading = false;

    function updateCsrf(token) { if (token) csrfToken = token; }
    window.updateCsrf = updateCsrf;

    // ===== 渲染统计（em-tabs__count 空值时自动隐藏，所以 0 时不填数字）=====
    function renderStats() {
        var total = allPlugins.length;
        var enabled = 0, disabled = 0;
        allPlugins.forEach(function(p) {
            if (p.is_enabled) enabled++;
            else disabled++;
        });
        $('#countAll').text(total || '');
        $('#countEnabled').text(enabled || '');
        $('#countDisabled').text(disabled || '');
        $('#countMerchant').text(merchantCount || '');
    }

    // ===== 启停后只刷新本卡片状态 + 内存 + 计数(不重拉列表) =====
    function applyToggleState(name, isEnabled) {
        var $card = $('.plugin-card[data-name="' + name + '"]');
        $card.toggleClass('plugin-card--enabled', isEnabled);
        var $status = $card.find('.plugin-card__status');
        if ($status.length) {
            $status
                .removeClass('plugin-card__status--enabled plugin-card__status--disabled')
                .addClass(isEnabled ? 'plugin-card__status--enabled' : 'plugin-card__status--disabled')
                .text(isEnabled ? '生效中' : '未启用');
        }
        // 同步内存数组,保证切 filter / 后续重渲染时状态正确
        for (var i = 0; i < allPlugins.length; i++) {
            if (allPlugins[i].name === name) {
                allPlugins[i].is_enabled = isEnabled;
                break;
            }
        }
        renderStats();
    }

    // ===== 空状态文案：主站「无插件」/ 分站「未上架」两套 =====
    function setEmptyState(variant) {
        var $empty = $('#pluginEmpty');
        var isMerchant = variant === 'merchant';
        $empty.find('.plugin-empty__art-core i').attr('class', isMerchant ? 'fa fa-cubes' : 'fa fa-puzzle-piece');
        $empty.find('.plugin-empty__text').text(isMerchant ? '暂未上架分站插件' : '暂未安装任何插件');
        $empty.find('.plugin-empty__sub').html(isMerchant
            ? '请先前往 <code>应用商店 · 分站货架</code> 采购并上架插件'
            : '请前往 <code>应用商店</code> 安装插件');
        $empty.find('#pluginEmptyAppstoreBtn').html(isMerchant
            ? '<i class="fa fa-shopping-basket"></i> 前往分站货架采购'
            : '<i class="fa fa-shopping-basket"></i> 去应用商店安装');
    }

    // ===== 渲染插件卡片 =====
    function renderPlugins(plugins) {
        var $grid = $('#pluginGrid');
        var $empty = $('#pluginEmpty');
        var $skeleton = $('#pluginSkeleton');

        $grid.empty();
        $skeleton.hide();

        var filtered = filterPlugins(plugins);
        if (filtered.length === 0) {
            $grid.hide();
            setEmptyState(currentFilter === 'merchant' ? 'merchant' : 'main');
            $empty.show();
            return;
        }
        $empty.hide();
        $grid.show();

        filtered.forEach(function(p) {
            $grid.append(buildCard(p));
        });
    }

    function filterPlugins(plugins) {
        if (currentFilter === 'merchant') return plugins;
        return plugins.filter(function(p) {
            return (currentFilter === 'all') ||
                (currentFilter === 'enabled' && p.is_enabled) ||
                (currentFilter === 'disabled' && !p.is_enabled);
        });
    }

    function buildCard(p) {
        var isMerchant = !!p.is_merchant;
        var isInstalled = p.is_installed;
        var isEnabled = p.is_enabled;
        var hasSettings = !!p.setting_file && !isMerchant;
        var hasUpdate = !!p.has_update && !!p.latest_version;
        var marketId = parseInt(p.market_id || p.id || 0, 10) || 0;

        var cls = 'plugin-card';
        if (isInstalled && isEnabled) cls += ' plugin-card--enabled';

        var iconHtml;
        if (p.preview) {
            iconHtml = '<img src="' + escHtml(p.preview) + '" class="plugin-card__icon" alt="">';
        } else if (p.icon) {
            iconHtml = '<img src="' + escHtml(p.icon) + '" class="plugin-card__icon" alt="">';
        } else {
            iconHtml = '<div class="plugin-card__icon plugin-card__icon--default"><i class="fa fa-puzzle-piece"></i></div>';
        }

        var authorHtml = '';
        if (p.author) {
            if (p.author_url) {
                authorHtml = '<span class="plugin-card__author"><i class="fa fa-user-o"></i><a href="' + escHtml(p.author_url) + '" target="_blank">' + escHtml(p.author) + '</a></span>';
            } else {
                authorHtml = '<span class="plugin-card__author"><i class="fa fa-user-o"></i>' + escHtml(p.author) + '</span>';
            }
        }

        var statusHtml = '';
        if (isMerchant) {
            statusHtml = '<span class="plugin-card__status ' + (p.is_listed == 1 ? 'plugin-card__status--enabled' : 'plugin-card__status--disabled') + '">' + (p.is_listed == 1 ? '已上架' : '已下架') + '</span>';
        } else if (isInstalled) {
            statusHtml = '<span class="plugin-card__status ' + (isEnabled ? 'plugin-card__status--enabled' : 'plugin-card__status--disabled') + '">' + (isEnabled ? '生效中' : '未启用') + '</span>';
        }

        var categoryHtml = '';
        if (p.category) {
            categoryHtml = '<span class="plugin-card__category">' + escHtml(p.category) + '</span>';
        }

        var actionHtml = '';
        if (isMerchant) {
            actionHtml = '<button class="plugin-card__action plugin-card__action--price" data-action="merchantPrice" data-name="' + escHtml(p.name) + '" data-market-id="' + escHtml(marketId) + '"><i class="fa fa-cny"></i> 设置售价</button>';
        } else if (!isInstalled) {
            actionHtml = '<button class="plugin-card__action plugin-card__action--install" data-action="install" data-name="' + escHtml(p.name) + '"><i class="fa fa-download"></i> 安装</button>';
        } else if (hasUpdate) {
            actionHtml = '<button class="plugin-card__action plugin-card__action--update" data-action="update" data-name="' + escHtml(p.name) + '" data-file-path="' + escHtml(p.latest_file_path || '') + '" data-version="' + escHtml(p.latest_version || '') + '"><i class="fa fa-cloud-download"></i> 更新 v' + escHtml(p.latest_version || '') + '</button>';
        } else if (hasSettings) {
            actionHtml = '<button class="plugin-card__action plugin-card__action--settings" data-action="settings" data-name="' + escHtml(p.name) + '"><i class="fa fa-gear"></i> 配置</button>';
        } else {
            actionHtml = '<button class="plugin-card__action plugin-card__action--settings plugin-card__action--disabled" data-tip="该插件无需配置"><i class="fa fa-gear"></i> 配置</button>';
        }

        var footerLeftHtml = isMerchant
            ? '<span class="plugin-card__price"><i class="fa fa-cny"></i> ' + fmtMoney(p.retail_price) + '</span>'
            : '<div class="plugin-toggle">' +
                '<input type="checkbox" id="toggle_' + escHtml(p.name) + '"' + (!isInstalled ? ' disabled' : '') + (isEnabled ? ' checked' : '') + ' data-action="toggle" data-name="' + escHtml(p.name) + '">' +
                '<label class="plugin-toggle__slider" for="toggle_' + escHtml(p.name) + '"' + (!isInstalled ? ' data-tip="插件未安装，该功能不可用"' : '') + '></label>' +
              '</div>';

        var moreHtml = '';
        if (isMerchant) {
            var nextListed = (parseInt(p.is_listed || 0, 10) === 1) ? 0 : 1;
            var toggleText = nextListed === 1 ? '上架' : '下架';
            var toggleIcon = nextListed === 1 ? 'fa-eye' : 'fa-eye-slash';
            moreHtml = '<div class="plugin-card__more">' +
                    '<button class="plugin-card__more-btn"><i class="fa fa-ellipsis-h"></i></button>' +
                    '<div class="plugin-card__more-dropdown">' +
                        '<button class="plugin-card__more-item" data-action="merchantToggleList" data-name="' + escHtml(p.name) + '" data-market-id="' + escHtml(marketId) + '" data-target-listed="' + escHtml(nextListed) + '"><i class="fa ' + toggleIcon + '"></i> ' + toggleText + '</button>' +
                        '<button class="plugin-card__more-item plugin-card__more-item--danger" data-action="uninstall" data-name="' + escHtml(p.name) + '" data-market-id="' + escHtml(marketId) + '"><i class="fa fa-trash"></i> 卸载</button>' +
                    '</div>' +
                '</div>';
        } else {
            moreHtml = '<div class="plugin-card__more">' +
                    '<button class="plugin-card__more-btn"><i class="fa fa-ellipsis-h"></i></button>' +
                    '<div class="plugin-card__more-dropdown">' +
                        (isInstalled
                            ? '<button class="plugin-card__more-item plugin-card__more-item--danger" data-action="uninstall" data-name="' + escHtml(p.name) + '"><i class="fa fa-trash"></i> 卸载</button>'
                              + (hasUpdate ? '<button class="plugin-card__more-item" data-action="update" data-name="' + escHtml(p.name) + '" data-file-path="' + escHtml(p.latest_file_path || '') + '" data-version="' + escHtml(p.latest_version || '') + '"><i class="fa fa-arrow-up"></i> 升级 v' + escHtml(p.latest_version || '') + '</button>' : '')
                            : ''
                        ) +
                    '</div>' +
                '</div>';
        }

        var card = $('<div>', {class: cls, 'data-name': p.name});
        card.html('<div class="plugin-card__header">' +
                '<div class="plugin-card__icon-wrap">' + iconHtml + '</div>' +
                '<div class="plugin-card__info">' +
                    '<div class="plugin-card__title-row">' +
                        '<div class="plugin-card__title">' + escHtml(p.title || p.name) + '</div>' +
                    '</div>' +
                    '<div class="plugin-card__meta">' +
                        '<span class="plugin-card__version">v' + escHtml(p.version || '1.0') + '</span>' +
                        categoryHtml +
                        statusHtml +
                    '</div>' +
                '</div>' +
                moreHtml +
            '</div>' +
            '<div class="plugin-card__body">' +
                (authorHtml ? '<div class="plugin-card__meta-row">' + authorHtml + '</div>' : '') +
                '<div class="plugin-card__desc" data-tip="' + escHtml(p.description || '') + '">' + escHtml(p.description || '暂无描述') + '</div>' +
            '</div>' +
            '<div class="plugin-card__footer">' +
                footerLeftHtml +
                actionHtml +
            '</div>'
        );
        return card;
    }

    function escHtml(str) {
        if (str == null) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function fmtMoney(micro) {
        var n = parseInt(micro || 0, 10);
        if (isNaN(n) || n < 0) n = 0;
        return (n / 1000000).toFixed(2);
    }

    // ===== 加载插件列表 =====
    function loadPlugins() {
        if (loading) return;
        loading = true;
        var listScope = currentFilter === 'merchant' ? 'merchant' : 'main';
        $('#pluginSkeleton').show();
        $('#pluginGrid, #pluginEmpty').hide();

        $.ajax({
            url: '/admin/plugin.php?_action=list&scope=' + listScope,
            type: 'GET',
            dataType: 'json',
            success: function(res) {
                if (res.code === 0 || res.code === 200) {
                    csrfToken = res.csrf_token || csrfToken;
                    merchantCount = parseInt(res.merchant_count || 0, 10) || 0;
                    if (listScope === 'merchant') {
                        merchantPlugins = res.data || [];
                        merchantLoaded = true;
                        renderStats();
                        renderPlugins(merchantPlugins);
                    } else {
                        allPlugins = res.data || [];
                        renderStats();
                        renderPlugins(allPlugins);
                    }
                }
            },
            error: function() {
                layer.msg('加载插件列表失败');
                $('#pluginSkeleton').hide();
            },
            complete: function() { loading = false; }
        });
    }

    // ===== 操作处理 =====
    function handleAction(action, name, extraData) {
        if (action === 'toggle') {
            action = $('#toggle_' + name).prop('checked') ? 'enable' : 'disable';
        }
        if (action === 'settings') { openSettings(name); return; }
        if (action === 'update' || action === 'upgrade') {
            doUpdatePlugin(name, extraData && extraData.file_path, String(extraData && extraData.version || ''));
            return;
        }
        if (action === 'merchantPrice') { openMerchantPrice(extraData && extraData.market_id); return; }
        if (action === 'merchantToggleList') {
            openMerchantToggleList(extraData && extraData.market_id, extraData && extraData.target_listed);
            return;
        }

        if (action === 'uninstall') {
            var marketId = parseInt(extraData && extraData.market_id ? extraData.market_id : 0, 10) || 0;
            var isMerchantUninstall = marketId > 0;
            if (currentFilter === 'merchant' && !isMerchantUninstall) {
                layui.layer.msg('market_id 非法，请刷新后重试');
                return;
            }
            var pluginTitle = name;
            var postData = {clear_data: false};
            var confirmMsg = '';

            if (isMerchantUninstall) {
                var mp = findMerchantPlugin(marketId);
                if (mp) pluginTitle = mp.title || mp.name || name;
                postData = {market_id: marketId};
                confirmMsg = '卸载分站插件「' + pluginTitle + '」后，将从分站货架移除并清空剩余库存记录。若已有分站购买记录，请改为下架。确认卸载？';
            } else {
                for (var i = 0; i < allPlugins.length; i++) {
                    if (allPlugins[i].name === name) { pluginTitle = allPlugins[i].title || name; break; }
                }
                confirmMsg = '卸载将清除插件「' + pluginTitle + '」的配置数据并删除磁盘文件，无法恢复。确认卸载？';
            }

            layer.confirm(confirmMsg,
                {icon: 3, title: '卸载确认', skin: 'admin-modal'},
                function(idx){
                    doRequest(action, name, postData, function(){ layer.close(idx); });
                });
            return;
        }

        doRequest(action, name, extraData);
    }

    function findMerchantPlugin(marketId) {
        marketId = parseInt(marketId, 10) || 0;
        for (var i = 0; i < merchantPlugins.length; i++) {
            if ((parseInt(merchantPlugins[i].market_id || merchantPlugins[i].id || 0, 10) || 0) === marketId) {
                return merchantPlugins[i];
            }
        }
        return null;
    }

    function openMerchantPrice(marketId) {
        var plugin = findMerchantPlugin(marketId);
        if (!plugin) {
            layui.layer.msg('分站插件不存在');
            return;
        }
        var current = fmtMoney(plugin.retail_price);
        var html = '<div style="padding:18px 20px;">' +
                   '  <div style="margin-bottom:10px;color:#6b7280;font-size:13px;">' +
                   '    插件:<b style="color:#0f172a;">' + escHtml(plugin.title || plugin.name) + '</b>' +
                   '  </div>' +
                   '  <div style="margin-bottom:6px;color:#6b7280;font-size:12px;">分站售价(元 / 次)</div>' +
                   '  <input id="pluginMerchantPriceInput" class="layui-input" type="number" min="0" step="0.01" value="' + current + '" autofocus>' +
                   '  <div style="margin-top:6px;color:#94a3b8;font-size:12px;">主站采购成本约 ¥' + fmtMoney(plugin.cost_price) + ' / 次</div>' +
                   '</div>';
        layui.layer.open({
            type: 1,
            title: '设置分站插件售价',
            area: ['380px', 'auto'],
            shadeClose: false,
            content: html,
            btn: ['确定', '取消'],
            yes: function (idx) {
                var v = parseFloat($('#pluginMerchantPriceInput').val());
                if (isNaN(v) || v < 0) {
                    layui.layer.msg('价格非法');
                    return;
                }
                $.post('/admin/plugin.php', {
                    _action: 'set_merchant_price',
                    csrf_token: csrfToken,
                    market_id: marketId,
                    retail_price_micro: Math.round(v * 1000000)
                }).done(function (res) {
                    if (res && (res.code === 0 || res.code === 200)) {
                        csrfToken = (res.data && res.data.csrf_token) ? res.data.csrf_token : csrfToken;
                        layui.layer.msg(res.msg || '售价已更新');
                        layui.layer.close(idx);
                        merchantLoaded = false;
                        loadPlugins();
                    } else {
                        layui.layer.msg((res && res.msg) || '更新失败');
                    }
                }).fail(function () {
                    layui.layer.msg('网络异常');
                });
            }
        });
    }

    function openMerchantToggleList(marketId, targetListed) {
        var plugin = findMerchantPlugin(marketId);
        if (!plugin) {
            layui.layer.msg('分站插件不存在');
            return;
        }
        var target = parseInt(targetListed, 10);
        var listed = isNaN(target)
            ? ((parseInt(plugin.is_listed || 0, 10) !== 1))
            : (target === 1);
        var title = plugin.title || plugin.name || '';
        var tip = listed
            ? '确定要上架分站插件「' + title + '」吗？'
            : '确定要下架分站插件「' + title + '」吗？<br><small style="color:#94a3b8;">下架不影响已购分站,但分站市场不可见、不再售出。</small>';
        layer.confirm(tip, {icon: 3, title: listed ? '上架确认' : '下架确认', skin: 'admin-modal'}, function(idx){
            doRequest('set_merchant_listed', plugin.name || '', {
                market_id: marketId,
                is_listed: listed ? 1 : 0
            }, function(){ layer.close(idx); });
        });
    }

    function doRequest(action, name, extraData, callback) {
        var postData = {csrf_token: csrfToken, _action: action, name: name};
        if (extraData) { for (var k in extraData) postData[k] = extraData[k]; }
        $.ajax({
            url: '/admin/plugin.php',
            type: 'POST',
            dataType: 'json',
            data: postData,
            success: function(res) {
                if (res.code === 0 || res.code === 200) {
                    csrfToken = (res.data && res.data.csrf_token) ? res.data.csrf_token : csrfToken;
                    layui.layer.msg(res.msg || '操作成功');
                    // 启停只局部刷新当前卡片 + 计数,不重拉列表(避免页面闪动)
                    // 其它操作(uninstall / delete / install / upgrade)涉及结构性变化,仍走全量刷新
                    if (action === 'enable' || action === 'disable') {
                        applyToggleState(name, action === 'enable');
                    } else {
                        loadPlugins();
                    }
                    if (callback) callback();
                } else {
                    layui.layer.msg(res.msg || '操作失败');
                    if (action === 'enable' || action === 'disable') {
                        $('#toggle_' + name).prop('checked', action === 'disable');
                    }
                }
            },
            error: function() {
                layui.layer.msg('网络异常');
                if (action === 'enable' || action === 'disable') {
                    $('#toggle_' + name).prop('checked', action === 'disable');
                }
                if (callback) callback();
            }
        });
    }

    function doUpdatePlugin(name, filePath, version) {
        var path = String(filePath || '').trim();
        var ver = String(version || '').trim();
        if (path === '' || ver === '') {
            layer.msg('更新参数缺失，请刷新后重试');
            return;
        }
        var loadingIdx = layui.layer.load(2, { shade: [0.3, '#000'] });
        $.ajax({
            url: '/admin/appstore.php',
            type: 'POST',
            dataType: 'json',
            data: { csrf_token: csrfToken, _action: 'update', name: name, type: 'plugin', file_path: path, version: ver },
            success: function(res) {
                layui.layer.close(loadingIdx);
                if (res && (res.code === 0 || res.code === 200)) {
                    csrfToken = (res.data && res.data.csrf_token) ? res.data.csrf_token : csrfToken;
                    layer.msg('插件已更新至 v' + emEsc(ver));
                    loadPlugins();
                } else {
                    layer.msg((res && res.msg) || '插件更新失败');
                }
            },
            error: function() {
                layui.layer.close(loadingIdx);
                layer.msg('网络异常');
            }
        });
    }

    function openSettings(name) {
        layer.open({
            type: 2,
            title: '插件设置',
            skin: 'admin-modal',
            maxmin: true,
            area: [window.innerWidth >= 800 ? '600px' : '95%', window.innerHeight >= 600 ? '75%' : '90%'],
            shadeClose: true,
            content: '/admin/plugin.php?_popup=1&name=' + encodeURIComponent(name),
            id: 'plugin_settings_' + name
        });
    }

    // ===== 事件绑定 =====

    // 刷新
    $('#pluginRefreshBtn').on('click', function(){ loadPlugins(); });

    // 应用商店（工具条按钮 + 空状态主按钮走同一入口）
    function goAppstore(){
        var url = currentFilter === 'merchant' ? '/admin/appstore.php?tab=merchant' : '/admin/appstore.php';
        $.pjax({ url: url, container: '#adminContent' });
    }
    $('#pluginAppstoreBtn, #pluginEmptyAppstoreBtn').on('click', goAppstore);

    // 空状态：重新检测
    $('#pluginEmptyRefreshBtn').on('click', function(){ loadPlugins(); });

    // em-tabs 分类筛选：同款切换（是否已激活跳过）
    $('#pluginTabs').on('click', '.em-tabs__item', function(){
        var $item = $(this);
        if ($item.hasClass('is-active')) return;
        $item.addClass('is-active').siblings().removeClass('is-active');
        currentFilter = $item.data('filter');
        if (currentFilter === 'merchant') {
            if (merchantLoaded) {
                renderPlugins(merchantPlugins);
            } else {
                loadPlugins();
            }
        } else {
            if (allPlugins.length > 0) {
                renderPlugins(allPlugins);
            } else {
                loadPlugins();
            }
        }
    });

    // 解绑旧事件防止 PJAX 重复绑定
    $(document).off('.emPlugin');

    // 禁用按钮的 tips 提示
    $(document).on('mouseenter.emPlugin', '[data-tip]', function(){
        layer.tips($(this).data('tip'), this, { tips: [1, '#555'], time: 0, closeBtn: true });
    }).on('mouseleave.emPlugin', '[data-tip]', function(){
        layer.closeAll('tips');
    });

    // 插件图标点击放大：Viewer.js（全局已加载）
    $(document).on('click.emPlugin', '.plugin-card__icon-wrap img', function(){
        var src = $(this).attr('src');
        if (!src) return;
        var $tmp = $('<div style="display:none;"><img src="' + src + '"></div>').appendTo('body');
        var viewer = new Viewer($tmp[0], {
            navbar: false, title: false, toolbar: true,
            hidden: function () { viewer.destroy(); $tmp.remove(); }
        });
    });

    // 卡片内按钮点击
    $(document).on('click.emPlugin', '.plugin-card__action, .plugin-card__more-item', function(e){
        e.stopPropagation();
        var action = $(this).data('action');
        var name = $(this).data('name');
        handleAction(action, name, {
            market_id: $(this).data('market-id'),
            target_listed: $(this).data('target-listed'),
            file_path: $(this).data('file-path'),
            version: $(this).data('version')
        });
    });

    // 开关切换
    $(document).on('change.emPlugin', '.plugin-toggle input', function(){
        var name = $(this).data('name');
        var action = $(this).prop('checked') ? 'enable' : 'disable';
        handleAction(action, name);
    });

    // 三点菜单
    $(document).on('click.emPlugin', '.plugin-card__more-btn', function(e){
        e.stopPropagation();
        var $dropdown = $(this).siblings('.plugin-card__more-dropdown');
        $('.plugin-card__more-dropdown').not($dropdown).removeClass('plugin-card__more-dropdown--open');
        $dropdown.toggleClass('plugin-card__more-dropdown--open');
    });

    // 点击其他地方关闭菜单
    $(document).on('click.emPlugin', function(){
        $('.plugin-card__more-dropdown').removeClass('plugin-card__more-dropdown--open');
    });

    // 初始加载
    loadPlugins();
});
</script>
