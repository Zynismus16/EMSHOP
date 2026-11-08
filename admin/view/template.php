<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
?>
<style>
/* 模板管理页面样式：沿用插件管理的卡片风格，并增加 PC / 手机双端状态展示。 */
/* 和控制台（home.php）一致：去掉 .admin-page 默认白底，让卡片浮在灰底画布上 */
.admin-page-template { padding: 8px 4px 40px; background: unset; }
.template-filter-bar { display:flex; align-items:center; gap:8px; margin-bottom:16px; }
.template-appstore-btn { height:32px; padding:0 14px; border:1px solid rgba(99,102,241,.3); border-radius:8px; background:rgba(99,102,241,.06); font-size:13px; font-weight:500; color:#6366f1; cursor:pointer; display:flex; align-items:center; gap:6px; transition:all .15s; }
.template-appstore-btn:hover { background:rgba(99,102,241,.12); border-color:rgba(99,102,241,.5); }

/* ===== 骨架屏 ===== */
.template-skeleton-icon { width:56px; height:56px; border-radius:10px; background:#f0f0f0; flex-shrink:0; }
.template-skeleton-line { height:12px; background:#f0f0f0; border-radius:6px; }
.template-skeleton-line--short { width:60%; }
@keyframes skeleton-pulse { 0%,100%{opacity:1;} 50%{opacity:.5;} }

/* ===== 卡片网格 ===== */
.template-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:16px;}
@media (min-width:1400px) { .template-grid { grid-template-columns:repeat(4, 1fr); } }

/* ===== 模板卡片 ===== */
.template-card { background:#fff; border:1px solid #e8e8ec; border-radius:14px; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 2px 12px rgba(0,0,0,.08); transition:border-color .2s,box-shadow .2s,transform .2s; }
.template-card:hover { border-color:#a5b4fc; box-shadow:0 4px 20px rgba(99,102,241,.1); transform:translateY(-1px); }
.template-card__header { display:flex; gap:12px; padding:16px 16px 12px; align-items:center; }
.template-card__icon-wrap { flex-shrink:0; cursor:pointer; }
.template-card__icon, .template-card__icon--default { width:56px; height:56px; border-radius:10px; object-fit:cover; }
.template-card__icon--default { background:linear-gradient(135deg, rgba(99,102,241,.1), rgba(129,140,248,.08)); display:flex; align-items:center; justify-content:center; color:#4f46e5; font-size:24px; }
.template-card__info { flex:1; min-width:0; }
.template-card__title { font-size:15px; font-weight:600; color:#111827; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.template-card__meta { display:flex; align-items:center; gap:6px; margin-top:4px; flex-wrap:wrap; }
.template-card__version { font-size:12px; color:#9ca3af; }
.template-card__status { display:inline-flex; align-items:center; font-size:12px; font-weight:500; padding:2px 8px; border-radius:5px; }
.template-card__status--pc { background:rgba(59,130,246,.1); color:#2563eb; }
.template-card__status--mobile { background:rgba(59,130,246,.1); color:#2563eb; }
.template-card__status--installed { background:rgba(5,150,105,.1); color:#059669; }
.template-card__status--new { background:rgba(0,0,0,.05); color:#6b7280; }
.template-card__status--both { background:rgba(99,102,241,.12); color:#4f46e5; }
.template-card__body { padding:0 16px 8px; flex:1; }
.template-card__author { display:inline-flex; align-items:center; gap:5px; font-size:12px; color:#9ca3af; margin-bottom:8px; }
.template-card__author a { color:#4f46e5; text-decoration:none; }
.template-card__author a:hover { text-decoration:underline; }
.template-card__desc { font-size:13px; color:#6b7280; line-height:1.6; }
.template-card__footer { display:flex; align-items:center; gap:8px; padding:12px 16px; padding-top:8px; border-top:1px solid #f5f5f7; flex-wrap:wrap; position:relative; z-index:1; }
.template-card__actions-left { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.template-card__actions-left--install { width:100%; justify-content:flex-end; }
.template-card__actions-right { margin-left:auto; display:flex; align-items:center; }
.template-card__action { height:32px; padding:0 12px; border:none; border-radius:7px; font-size:12px; font-weight:500; cursor:pointer; display:inline-flex; align-items:center; gap:5px; transition:all .15s; }
.template-card__action--install { background:rgba(99,102,241,.1); color:#4f46e5; }
.template-card__action--install:hover { background:rgba(99,102,241,.18); }
.template-card__action--pc { background:rgba(0,0,0,.04); color:#6b7280; }
.template-card__action--pc:hover { background:rgba(59,130,246,.08); color:#2563eb; }
.template-card__action--pc.is-active { background:rgba(59,130,246,.08); color:#2563eb; }
.template-card__action--mobile { background:rgba(0,0,0,.04); color:#6b7280; }
.template-card__action--mobile:hover { background:rgba(59,130,246,.08); color:#2563eb; }
.template-card__action--mobile.is-active { background:rgba(59,130,246,.08); color:#2563eb; }
.template-card__action--settings { background:rgba(245,158,11,.1); color:#d97706; }
.template-card__action--settings:hover { background:rgba(245,158,11,.18); color:#b45309; }
/* 有新版本时用实色橙按钮提示更新，与应用商店里"更新 vX.X.X"同一视觉语言 */
.template-card__action--update { background:#f59e0b; color:#fff; }
.template-card__action--update:hover { background:#d97706; color:#fff; }
.template-card__action--disabled { background:rgba(0,0,0,.04)!important; color:#c9cdd4!important; cursor:not-allowed!important; }
.template-card__more { flex-shrink:0; position:relative; }
.template-card__more-btn { width:28px; height:28px; border:none; background:transparent; border-radius:6px; cursor:pointer; color:#d1d5db; font-size:16px; display:flex; align-items:center; justify-content:center; transition:all .15s; }
.template-card__more-btn:hover { background:#f5f5f7; color:#6b7280; }
.template-card__more-dropdown { position:absolute; top:calc(100% + 4px); right:0; background:#fff; border:1px solid #e8e8ec; border-radius:8px; box-shadow:0 4px 16px rgba(0,0,0,.1); min-width:130px; z-index:200; overflow:hidden; display:none; }
.template-card__more-dropdown--open { display:block; }
.template-card__more-item { display:flex; align-items:center; gap:8px; width:100%; border:none; background:transparent; padding:9px 12px; font-size:13px; color:#374151; text-align:left; cursor:pointer; transition:background .1s; }
.template-card__more-item:hover { background:#f5f5f7; }
.template-card__more-item--danger { color:#dc2626; }
.template-card__more-item--danger:hover { background:rgba(220,38,38,.04); }
.template-empty { text-align:center; padding:60px 20px; color:#9ca3af; display:none; }
.template-empty__icon { width:72px; height:72px; border-radius:20px; background:linear-gradient(135deg, rgba(99,102,241,.08), rgba(129,140,248,.05)); display:flex; align-items:center; justify-content:center; margin:0 auto 20px; font-size:32px; color:#a5b4fc; }
.fa-mobile{ font-size:17px; }
</style>

<div class="admin-page admin-page-template">
    <h1 class="admin-page__title">模板管理</h1>

    <div class="template-filter-bar">
        <a class="em-btn em-reset-btn" id="templateRefreshBtn"><i class="fa fa-refresh"></i>刷新</a>
        <button class="template-appstore-btn" id="appstoreBtn" type="button"><i class="fa fa-shopping-basket"></i> 去逛逛应用商店</button>
    </div>

<div id="templateWrapper" style="display:none;">
        <div class="template-grid" id="templateSkeleton">
            <div class="template-card" style="animation:skeleton-pulse 1.5s ease-in-out infinite;">
                <div class="template-card__header">
                    <div class="template-card__icon-wrap">
                        <div class="template-skeleton-icon"></div>
                    </div>
                    <div class="template-card__info">
                        <div class="template-skeleton-line template-skeleton-line--short" style="height:15px;margin-bottom:6px;"></div>
                        <div class="template-card__meta">
                            <div class="template-skeleton-line" style="width:60px;height:20px;"></div>
                        </div>
                    </div>
                </div>
                <div class="template-card__body">
                    <div class="template-skeleton-line" style="width:40%;height:12px;margin-bottom:10px;"></div>
                    <div class="template-skeleton-line" style="width:100%;height:13px;margin-bottom:6px;"></div>
                    <div class="template-skeleton-line" style="width:75%;height:13px;"></div>
                </div>
                <div class="template-card__footer">
                    <div class="template-skeleton-line" style="width:80px;height:32px;border-radius:7px;"></div>
                    <div class="template-skeleton-line" style="width:80px;height:32px;border-radius:7px;"></div>
                </div>
            </div>
            <div class="template-card" style="animation:skeleton-pulse 1.5s ease-in-out infinite;animation-delay:.2s;">
                <div class="template-card__header">
                    <div class="template-card__icon-wrap">
                        <div class="template-skeleton-icon"></div>
                    </div>
                    <div class="template-card__info">
                        <div class="template-skeleton-line template-skeleton-line--short" style="height:15px;margin-bottom:6px;"></div>
                        <div class="template-card__meta">
                            <div class="template-skeleton-line" style="width:60px;height:20px;"></div>
                        </div>
                    </div>
                </div>
                <div class="template-card__body">
                    <div class="template-skeleton-line" style="width:40%;height:12px;margin-bottom:10px;"></div>
                    <div class="template-skeleton-line" style="width:100%;height:13px;margin-bottom:6px;"></div>
                    <div class="template-skeleton-line" style="width:75%;height:13px;"></div>
                </div>
                <div class="template-card__footer">
                    <div class="template-skeleton-line" style="width:80px;height:32px;border-radius:7px;"></div>
                    <div class="template-skeleton-line" style="width:80px;height:32px;border-radius:7px;"></div>
                </div>
            </div>
            <div class="template-card" style="animation:skeleton-pulse 1.5s ease-in-out infinite;animation-delay:.4s;">
                <div class="template-card__header">
                    <div class="template-card__icon-wrap">
                        <div class="template-skeleton-icon"></div>
                    </div>
                    <div class="template-card__info">
                        <div class="template-skeleton-line template-skeleton-line--short" style="height:15px;margin-bottom:6px;"></div>
                        <div class="template-card__meta">
                            <div class="template-skeleton-line" style="width:60px;height:20px;"></div>
                        </div>
                    </div>
                </div>
                <div class="template-card__body">
                    <div class="template-skeleton-line" style="width:40%;height:12px;margin-bottom:10px;"></div>
                    <div class="template-skeleton-line" style="width:100%;height:13px;margin-bottom:6px;"></div>
                    <div class="template-skeleton-line" style="width:75%;height:13px;"></div>
                </div>
                <div class="template-card__footer">
                    <div class="template-skeleton-line" style="width:80px;height:32px;border-radius:7px;"></div>
                    <div class="template-skeleton-line" style="width:80px;height:32px;border-radius:7px;"></div>
                </div>
            </div>
            <div class="template-card" style="animation:skeleton-pulse 1.5s ease-in-out infinite;animation-delay:.6s;">
                <div class="template-card__header">
                    <div class="template-card__icon-wrap">
                        <div class="template-skeleton-icon"></div>
                    </div>
                    <div class="template-card__info">
                        <div class="template-skeleton-line template-skeleton-line--short" style="height:15px;margin-bottom:6px;"></div>
                        <div class="template-card__meta">
                            <div class="template-skeleton-line" style="width:60px;height:20px;"></div>
                        </div>
                    </div>
                </div>
                <div class="template-card__body">
                    <div class="template-skeleton-line" style="width:40%;height:12px;margin-bottom:10px;"></div>
                    <div class="template-skeleton-line" style="width:100%;height:13px;margin-bottom:6px;"></div>
                    <div class="template-skeleton-line" style="width:75%;height:13px;"></div>
                </div>
                <div class="template-card__footer">
                    <div class="template-skeleton-line" style="width:80px;height:32px;border-radius:7px;"></div>
                    <div class="template-skeleton-line" style="width:80px;height:32px;border-radius:7px;"></div>
                </div>
            </div>
        </div>

        <!-- 中心服务不可达时的红色告警条；有内容才显示 -->
        <div id="templateLicenseAlert" style="display:none;padding:12px 16px; margin: 0 15px; margin-bottom:16px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;border-radius:6px;font-size:13px;">
            <i class="fa fa-exclamation-triangle"></i> <span id="templateLicenseAlertMsg"></span>
        </div>

        <div class="template-grid" id="templateGrid"></div>

        <!-- 空：本地磁盘/中心服务校验后无任何已授权模板 -->
        <div class="template-empty" id="templateEmpty">
            <div class="template-empty__icon"><i class="fa fa-paint-brush"></i></div>
            <div>暂无可用模板</div>
            <div style="margin-top:8px;line-height:1.7;">
                请在 <a href="/admin/appstore.php" data-pjax="#adminContent" style="color:#4f46e5;">应用商店</a> 购买后安装，<br>
                或把本地开发的模板放入 <code>content/template/</code>，并在 <code>header.php</code> 顶部加 <code>Custom: true</code>
            </div>
        </div>
    </div>
</div>

<script>
'use strict';

var csrfToken = <?php echo json_encode($csrfToken); ?>;
var allTemplates = [];
var loading = false;
var actionLock = false;
var openLayers = {};

function escHtml(str) {
    if (str == null) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function showLoading() {
    $('#templateWrapper').show();
    $('#templateSkeleton').show();
    $('#templateGrid, #templateEmpty').hide();
}

function showGrid() {
    $('#templateSkeleton, #templateEmpty').hide();
    $('#templateGrid').show();
}

function showEmpty() {
    $('#templateSkeleton, #templateGrid').hide();
    $('#templateEmpty').show();
}

function renderTemplates(items) {
    var $grid = $('#templateGrid');
    $grid.empty();

    if (!items.length) {
        showEmpty();
        return;
    }

    showGrid();
    items.forEach(function (item) {
        $grid.append(buildCard(item));
    });
}

function buildCard(item) {
    // return '';
    var previewHtml = item.preview
        ? '<img src="' + escHtml(item.preview) + '" class="template-card__icon" alt="">'
        : '<div class="template-card__icon--default"><i class="fa fa-paint-brush"></i></div>';

    var authorHtml = '';
    if (item.author) {
        authorHtml = item.author_url
            ? '<span class="template-card__author"><i class="fa fa-user-o"></i><a href="' + escHtml(item.author_url) + '" target="_blank">' + escHtml(item.author) + '</a></span>'
            : '<span class="template-card__author"><i class="fa fa-user-o"></i>' + escHtml(item.author) + '</span>';
    }

    var installStatusHtml = item.is_installed
        ? '<span class="template-card__status template-card__status--installed">已安装</span>'
        : '<span class="template-card__status template-card__status--new">未安装</span>';

    // 右侧按钮：有新版本时优先展示"更新 vX.X.X"（覆盖配置按钮）；无新版本则按原逻辑展示"配置"
    var rightBtn;
    if (item.has_update && item.latest_version) {
        rightBtn = '<button class="template-card__action template-card__action--update" '
                 +   'data-action="update" '
                 +   'data-name="' + escHtml(item.name) + '" '
                 +   'data-file-path="' + escHtml(item.latest_file_path || '') + '" '
                 +   'data-version="' + escHtml(item.latest_version) + '" '
                 +   'type="button"><i class="fa fa-cloud-download"></i> 更新 v' + escHtml(item.latest_version) + '</button>';
    } else {
        rightBtn = item.setting_file
            ? '<button class="template-card__action template-card__action--settings" data-action="settings" data-name="' + escHtml(item.name) + '" type="button"><i class="fa fa-gear"></i> 配置</button>'
            : '<button class="template-card__action template-card__action--settings template-card__action--disabled" type="button" disabled data-tip="该模板无配置项"><i class="fa fa-gear"></i> 配置</button>';
    }

    var footerHtml = '';
    if (!item.is_installed) {
        footerHtml = '';
    } else {
        var pcClass = item.is_active_pc ? ' is-active' : '';
        var mobileClass = item.is_active_mobile ? ' is-active' : '';
        footerHtml = '<div class="template-card__actions-left">'
            + '<button class="template-card__action template-card__action--pc' + pcClass + '" data-action="activate_pc" data-name="' + escHtml(item.name) + '"><i class="fa fa-desktop"></i> PC启用</button>'
            + '<button class="template-card__action template-card__action--mobile' + mobileClass + '" data-action="activate_mobile" data-name="' + escHtml(item.name) + '"><i class="fa fa-mobile"></i> 手机启用</button>'
            + '</div>'
            + '<div class="template-card__actions-right">' + rightBtn + '</div>';
    }

    var html = '';
    html += '<div class="template-card" data-name="' + escHtml(item.name) + '">';
    html += '  <div class="template-card__header">';
    html += '      <div class="template-card__icon-wrap">' + previewHtml + '</div>';
    html += '      <div class="template-card__info">';
    html += '          <div class="template-card__title">' + escHtml(item.title || item.name) + '</div>';
    html += '          <div class="template-card__meta"><span class="template-card__version">v' + escHtml(item.version || '1.0.0') + '</span>' + installStatusHtml + '</div>';
    html += '      </div>';
    html += '      <div class="template-card__more">';
    html += '          <button class="template-card__more-btn"><i class="fa fa-ellipsis-h"></i></button>';
    html += '          <div class="template-card__more-dropdown">';
    if (item.is_installed) {
        html += '              <button class="template-card__more-item" data-action="uninstall" data-name="' + escHtml(item.name) + '"><i class="fa fa-trash"></i> 卸载</button>';
    }
    html += '          </div>';
    html += '      </div>';
    html += '  </div>';
    html += '  <div class="template-card__body">';
    html +=        authorHtml;
    html += '      <div class="template-card__desc">' + escHtml(item.description || '暂无描述') + '</div>';
    html += '  </div>';
    html += '  <div class="template-card__footer">';
    html +=        footerHtml;
    html += '  </div>';
    html += '</div>';
    return html;
}

function loadTemplates() {
    if (loading) return;
    loading = true;
    $('#templateEmpty, #templateLicenseAlert').hide();
    showLoading();

    $.ajax({
        url: '/admin/template.php?_action=list',
        type: 'GET',
        dataType: 'json',
        success: function (res) {
            if (res.code === 0 || res.code === 200) {
                csrfToken = res.csrf_token || csrfToken;
                allTemplates = res.data || [];
                renderTemplates(allTemplates);
            } else {
                layui.layer.msg(res.msg || '加载失败', {icon: 2});
                showEmpty();
            }
        },
        error: function () {
            layui.layer.msg('加载模板列表失败', {icon: 2});
            showEmpty();
        },
        complete: function () {
            loading = false;
        }
    });
}

function doRequest(action, name, extraData, done) {
    if (actionLock) return;
    actionLock = true;
    var postData = {csrf_token: csrfToken, _action: action, name: name};
    if (extraData) {
        for (var k in extraData) postData[k] = extraData[k];
    }
    $.ajax({
        url: '/admin/template.php',
        type: 'POST',
        dataType: 'json',
        data: postData,
        success: function (res) {
            if (res.code === 0 || res.code === 200) {
                csrfToken = (res.data && res.data.csrf_token) ? res.data.csrf_token : csrfToken;
                layui.layer.msg(res.msg || '操作成功', {icon: 1});
                loadTemplates();
            } else {
                layui.layer.msg(res.msg || '操作失败', {icon: 2});
            }
            if (done) done();
        },
        error: function () {
            layui.layer.msg('网络异常', {icon: 2});
            if (done) done();
        },
        complete: function () {
            actionLock = false;
        }
    });
}

/**
 * 模板更新：复用应用商店的 update action（/admin/appstore.php）
 * 该 action 会下载最新 zip → 解压覆盖 content/template/{name} → 更新 em_template.version
 * 注意：appstore.php 的 update 接口成功时返回新的 csrf_token,需同步到本地 csrfToken 避免后续 template.php 的 POST 失效
 */
function doUpdateTemplate(name, filePath, version) {
    if (actionLock) return;
    actionLock = true;
    var loadingIdx = layui.layer.load(2, { shade: [0.3, '#000'] });
    $.ajax({
        url: '/admin/appstore.php',
        type: 'POST',
        dataType: 'json',
        data: {
            csrf_token: csrfToken,
            _action:    'update',
            name:       name,
            type:       'template',
            file_path:  filePath,
            version:    version
        },
        success: function (res) {
            layui.layer.close(loadingIdx);
            if (res && (res.code === 0 || res.code === 200)) {
                if (res.data && res.data.csrf_token) {
                    csrfToken = res.data.csrf_token;
                }
                layui.layer.msg('模板已更新至 v' + emEsc(version));
                loadTemplates();
            } else {
                layui.layer.msg((res && res.msg) || '更新失败');
            }
        },
        error: function () {
            layui.layer.close(loadingIdx);
            layui.layer.msg('网络异常');
        },
        complete: function () {
            actionLock = false;
        }
    });
}

function doActivateRequest(action, name) {
    if (actionLock) return;
    actionLock = true;
    var $btn = $('.template-card__action[data-action="' + action + '"][data-name="' + name + '"]');
    var isPc = (action === 'activate_pc');
    var targetKey = isPc ? 'is_active_pc' : 'is_active_mobile';
    var $card = $btn.closest('.template-card');

    // 乐观更新：先切换 UI 状态
    var wasActive = $btn.hasClass('is-active');
    var willBeActive = !wasActive;

    if (willBeActive) {
        $btn.addClass('is-active');
    } else {
        $btn.removeClass('is-active');
    }

    // 如果是激活操作，UI 上先把同类型已激活的其他按钮也切换状态
    if (willBeActive) {
        $('.template-card__action[data-action="' + action + '"].is-active').not($btn).each(function () {
            var $other = $(this);
            if ($other.data('name') !== name) {
                $other.removeClass('is-active');
                $other.html('<i class="fa fa-' + (isPc ? 'desktop' : 'mobile') + '"></i> ' + (isPc ? 'PC启用' : '手机启用'));
            }
        });
    }

    $.ajax({
        url: '/admin/template.php',
        type: 'POST',
        dataType: 'json',
        data: {csrf_token: csrfToken, _action: action, name: name},
        success: function (res) {
            if (res.code === 0 || res.code === 200) {
                csrfToken = (res.data && res.data.csrf_token) ? res.data.csrf_token : csrfToken;
                layui.layer.msg(res.msg || '操作成功');
                // 更新内存中的数据
                allTemplates.forEach(function (t) {
                    if (willBeActive) {
                        t[targetKey] = false;
                    }
                });
                var template = allTemplates.find(function (t) { return t.name === name; });
                if (template) {
                    template[targetKey] = willBeActive;
                }
            } else {
                layui.layer.msg(res.msg || '操作失败', {icon: 2});
                // 失败时还原 UI
                if (wasActive) {
                    $btn.addClass('is-active');
                } else {
                    $btn.removeClass('is-active');
                }
            }
        },
        error: function () {
            layui.layer.msg('网络异常', {icon: 2});
            // 失败时还原 UI
            if (wasActive) {
                $btn.addClass('is-active');
            } else {
                $btn.removeClass('is-active');
            }
        },
        complete: function () {
            actionLock = false;
        }
    });
}

function openSettings(name) {
    var layerId = 'template_settings_' + name;
    if (openLayers[layerId]) {
        try { layui.layer.restore(openLayers[layerId]); } catch (e) {}
        return;
    }
    layui.layer.open({
        type: 2,
        title: '模板设置',
        skin: 'admin-modal',
        maxmin: true,
        area: [window.innerWidth >= 800 ? '600px' : '95%', window.innerHeight >= 600 ? '75%' : '90%'],
        shadeClose: true,
        content: '/admin/template.php?_popup=1&name=' + encodeURIComponent(name),
        id: layerId,
        success: function (layero, index) {
            openLayers[layerId] = index;
        },
        end: function () {
            delete openLayers[layerId];
        }
    });
}

// ===== 事件绑定（使用 off/on 确保 PJAX 切换后不重复绑定）=====
$(function () {
    layui.use('layer', function () {
        // 刷新
        $(document).off('click.templateRefresh').on('click.templateRefresh', '#templateRefreshBtn', function () { loadTemplates(); });

        // 应用商店按钮点击
        $(document).off('click.appstoreBtn').on('click.appstoreBtn', '#appstoreBtn', function () {
            $.pjax({ url: '/admin/appstore.php', container: '#adminContent' });
        });

        // 三点菜单
        $(document).off('click.moreBtn').on('click.moreBtn', '.template-card__more-btn', function (e) {
            e.stopPropagation();
            var $dropdown = $(this).siblings('.template-card__more-dropdown');
            $('.template-card__more-dropdown').not($dropdown).removeClass('template-card__more-dropdown--open');
            $dropdown.toggleClass('template-card__more-dropdown--open');
        });

        // 点击其他地方关闭菜单
        $(document).off('click.closeDropdown').on('click.closeDropdown', function () {
            $('.template-card__more-dropdown').removeClass('template-card__more-dropdown--open');
        });

        // tips 提示
        $(document).off('mouseenter.moreTip mouseenter.moreTip mouseenter.moreTip').on('mouseenter.moreTip', '[data-tip]', function () {
            layui.layer.tips($(this).data('tip'), this, {tips: [1, '#555'], time: 0, closeBtn: true});
        }).off('mouseleave.moreTip').on('mouseleave.moreTip', '[data-tip]', function () {
            layui.layer.closeAll('tips');
        });

        // 图片点击放大
        $(document).off('click.imgPreview').on('click.imgPreview', '.template-card__icon-wrap img', function () {
            var src = $(this).attr('src');
            if (!src) return;
            layui.layer.photos({ photos: { title: '', id: 0, start: 0, data: [{alt: '模板预览', pid: 0, src: src}] }, anim: 5 });
        });

        // 卡片操作按钮（footer 区域的按钮）
        $(document).off('click.cardAction').on('click.cardAction', '.template-card__action', function (e) {
            e.stopPropagation();
            var $btn = $(this);
            var action = $btn.data('action');
            var name = $btn.data('name');
            if (!action || !name) return;
            if (action === 'settings') {
                openSettings(name);
                return;
            }
            if (action === 'activate_pc' || action === 'activate_mobile') {
                doActivateRequest(action, name);
                return;
            }
            if (action === 'update') {
                // 走应用商店的 update 流程（复用下载+解压+注册逻辑）
                doUpdateTemplate(name, $btn.data('file-path') || '', String($btn.data('version') || ''));
                return;
            }
            doRequest(action, name);
        });

        // 三点菜单下拉项（uninstall / delete 等）
        $(document).off('click.moreItem').on('click.moreItem', '.template-card__more-item', function (e) {
            e.stopPropagation();
            var action = $(this).data('action');
            var name = $(this).data('name');
            if (!action || !name) return;
            if (action === 'uninstall') {
                layui.layer.confirm('卸载将清除模板的配置数据并删除磁盘文件，无法恢复。确认卸载？', {icon: 3, title: '卸载确认', skin: 'admin-modal'}, function (idx) {
                    doRequest(action, name, null, function () { layui.layer.close(idx); });
                });
                return;
            }
            doRequest(action, name);
        });

        // 初始加载
        loadTemplates();
    });
});
</script>
