<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var array<string, mixed> $officialAccount 见 LicenseService::officialAccount()，未登录为空数组 */
/** @var string $csrfToken */

$pageTitle = '管理官方账号';

$esc = static function (?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
};

$logged = $officialAccount !== [];

$accName     = '';
$accUsername = '';
$accEmail    = '';
$accRole     = '';
$accInitial  = '';
$isAgent     = false;
if ($logged) {
    $accName     = trim((string) ($officialAccount['nickname'] ?? ''));
    $accUsername = trim((string) ($officialAccount['username'] ?? ''));
    if ($accName === '') {
        $accName = $accUsername !== '' ? $accUsername : '官方账号';
    }
    $accEmail = trim((string) ($officialAccount['email'] ?? ''));
    $roleMap  = ['admin' => '管理员', 'agent' => '代理商', 'user' => '普通用户'];
    $accRole  = $roleMap[(string) ($officialAccount['role'] ?? '')] ?? '用户';
    // 代理商 / 管理员才有代理配置（服务端先按本地快照渲染，概览回来后再校正）
    $isAgent  = in_array((string) ($officialAccount['role'] ?? ''), ['agent', 'admin'], true);
    $accInitial = function_exists('mb_substr')
        ? mb_substr($accName, 0, 1, 'UTF-8')
        : strtoupper(substr($accName, 0, 1));
}

include __DIR__ . '/header.php';
?>

<div class="popup-inner oa-wrap">
<?php if (!$logged): ?>
    <div class="oa-empty">
        <div class="oa-empty__icon"><i class="fa fa-user-circle-o"></i></div>
        <div class="oa-empty__title">尚未登录官方账号</div>
        <div class="oa-empty__desc">请关闭本窗口，在后台首页的「官方账号」卡片上登录后再来管理。</div>
    </div>
<?php else: ?>
    <!-- 账号概览 -->
    <div class="oa-user">
        <div class="oa-user__avatar" id="oaUserAvatar"><?= $esc($accInitial) ?></div>
        <div class="oa-user__meta">
            <div class="oa-user__name" id="oaUserName"><?= $esc($accName) ?></div>
            <div class="oa-user__sub">
                <span id="oaUserEmail"<?= $accEmail === '' ? ' hidden' : '' ?>><?= $esc($accEmail) ?></span>
                <span class="oa-user__role" id="oaUserRole"><?= $esc($accRole) ?></span>
            </div>
        </div>
        <div class="oa-user__actions">
            <button type="button" class="oa-btn oa-btn--primary" id="oaProfile">
                <i class="fa fa-id-card-o"></i> 个人资料
            </button>
            <button type="button" class="oa-btn oa-btn--danger" id="oaSwitch">
                <i class="fa fa-sign-out"></i> 退出登录
            </button>
        </div>
    </div>

    <!-- 功能入口：两张卡，点开各自在新 iframe 窗口里展示 -->
    <div class="oa-grid">
        <div class="oa-tile" role="button" tabindex="0" data-popup="/admin/license.php?_popup=licenses" data-title="我的激活码" data-w="560" data-h="540">
            <span class="oa-tile__top">
                <span class="oa-tile__icon oa-tile__icon--indigo"><i class="fa fa-key"></i></span>
                <span class="oa-tile__name">我的激活码</span>
                <i class="fa fa-angle-right oa-tile__arrow"></i>
            </span>
            <span class="oa-tile__value"><span id="oaLicCount">—</span><small>个</small></span>
            <span class="oa-tile__foot">
                <span class="oa-tile__desc">查看账号下全部激活码</span>
                <button type="button" class="oa-tile__add" id="oaAddLicense">
                    <i class="fa fa-plus"></i>添加激活码
                </button>
            </span>
        </div>
        <div class="oa-tile" role="button" tabindex="0" data-popup="/admin/license.php?_popup=balance_logs" data-title="账户余额" data-w="560" data-h="560">
            <span class="oa-tile__top">
                <span class="oa-tile__icon oa-tile__icon--green"><i class="fa fa-jpy"></i></span>
                <span class="oa-tile__name">账户余额</span>
                <i class="fa fa-angle-right oa-tile__arrow"></i>
            </span>
            <span class="oa-tile__value oa-tile__value--money" id="oaBalance">—</span>
            <span class="oa-tile__desc">余额与变动明细</span>
        </div>
        <!-- 代理 / 管理账号才显示；角色以概览返回的实时资料为准 -->
        <div class="oa-tile" role="button" tabindex="0" id="oaAgentTile"
             data-popup="/admin/license.php?_popup=agent_settings" data-title="代理配置" data-w="520" data-h="560"<?= $isAgent ? '' : ' hidden' ?>>
            <span class="oa-tile__top">
                <span class="oa-tile__icon oa-tile__icon--blue"><i class="fa fa-sliders"></i></span>
                <span class="oa-tile__name">代理配置</span>
                <i class="fa fa-angle-right oa-tile__arrow"></i>
            </span>
            <span class="oa-tile__value oa-tile__value--note">同步上游</span>
            <span class="oa-tile__desc">身份标识 · 联系方式 · EMSHOP 链接</span>
        </div>
        <!-- 代理 / 管理账号才显示 -->
        <div class="oa-tile" role="button" tabindex="0" id="oaWarehouseTile"
             data-popup="/admin/license.php?_popup=code_warehouse" data-title="激活码仓库" data-w="600" data-h="620"<?= $isAgent ? '' : ' hidden' ?>>
            <span class="oa-tile__top">
                <span class="oa-tile__icon oa-tile__icon--orange"><i class="fa fa-cubes"></i></span>
                <span class="oa-tile__name">激活码仓库</span>
                <i class="fa fa-angle-right oa-tile__arrow"></i>
            </span>
            <span class="oa-tile__value oa-tile__value--note">生成 / 查看</span>
            <span class="oa-tile__desc">按档位单价从余额生成激活码</span>
        </div>

        <div class="oa-tile" role="button" tabindex="0" id="oaVersionsTile"
             data-popup="/admin/license.php?_popup=versions" data-title="版本安装包" data-w="600" data-h="560"<?= $isAgent ? '' : ' hidden' ?>>
            <span class="oa-tile__top">
                <span class="oa-tile__icon oa-tile__icon--teal"><i class="fa fa-file-archive-o"></i></span>
                <span class="oa-tile__name">版本安装包</span>
                <i class="fa fa-angle-right oa-tile__arrow"></i>
            </span>
            <span class="oa-tile__value oa-tile__value--note">EMSHOP 源码</span>
            <span class="oa-tile__desc">下载带身份标识的安装包</span>
        </div>

        <div class="oa-tile" role="button" tabindex="0" id="oaOrdersTile"
             data-popup="/admin/license.php?_popup=orders" data-title="用户订单记录" data-w="640" data-h="660"<?= $isAgent ? '' : ' hidden' ?>>
            <span class="oa-tile__top">
                <span class="oa-tile__icon oa-tile__icon--purple"><i class="fa fa-shopping-cart"></i></span>
                <span class="oa-tile__name">用户订单记录</span>
                <i class="fa fa-angle-right oa-tile__arrow"></i>
            </span>
            <span class="oa-tile__value oa-tile__value--note">查看售出订单</span>
            <span class="oa-tile__desc">应用订单 · 佣金记录</span>
        </div>

        <div class="oa-tile" role="button" tabindex="0" id="oaAdsTile"
             data-popup="/admin/license.php?_popup=ad_slots" data-title="广告位管理" data-w="660" data-h="680"<?= $isAgent ? '' : ' hidden' ?>>
            <span class="oa-tile__top">
                <span class="oa-tile__icon oa-tile__icon--rose"><i class="fa fa-bullhorn"></i></span>
                <span class="oa-tile__name">广告位管理</span>
                <i class="fa fa-angle-right oa-tile__arrow"></i>
            </span>
            <span class="oa-tile__value oa-tile__value--note">EMSHOP 广告位</span>
            <span class="oa-tile__desc">新建 · 续期 · 启停</span>
        </div>

        <div class="oa-tile" role="button" tabindex="0" id="oaInstallsTile"
             data-popup="/admin/license.php?_popup=install_records" data-title="安装记录" data-w="620" data-h="640"<?= $isAgent ? '' : ' hidden' ?>>
            <span class="oa-tile__top">
                <span class="oa-tile__icon oa-tile__icon--cyan"><i class="fa fa-desktop"></i></span>
                <span class="oa-tile__name">安装记录</span>
                <i class="fa fa-angle-right oa-tile__arrow"></i>
            </span>
            <span class="oa-tile__value oa-tile__value--note">客户安装上报</span>
            <span class="oa-tile__desc">装的哪一版、在哪台机器</span>
        </div>
    </div>
<?php endif; ?>
</div>

<style>
.oa-wrap { padding: 18px 20px; }

/* ---------- 未登录 ---------- */
.oa-empty {
    padding: 80px 24px;
    text-align: center;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 14px;
}
.oa-empty__icon {
    width: 64px; height: 64px;
    margin: 0 auto 16px;
    border-radius: 18px;
    background: #eef2ff; color: #a5b4fc;
    display: flex; align-items: center; justify-content: center;
    font-size: 28px;
}
.oa-empty__title { font-size: 16px; font-weight: 600; color: #111827; margin-bottom: 8px; }
.oa-empty__desc  { font-size: 13px; color: #9ca3af; line-height: 1.7; }

/* ---------- 账号概览 ---------- */
.oa-user {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px;
    margin-bottom: 14px;
    background:
        radial-gradient(260px 140px at 100% -30%, rgba(99, 102, 241, 0.14), transparent 70%),
        #fff;
    border: 1px solid #e0e7ff;
    border-radius: 14px;
}
.oa-user__avatar {
    width: 46px; height: 46px; flex-shrink: 0;
    border-radius: 50%;
    background: linear-gradient(135deg, #6366f1, #0ea5e9);
    color: #fff; font-size: 18px; font-weight: 600;
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.28);
}
.oa-user__meta { flex: 1; min-width: 0; }
.oa-user__name {
    font-size: 16px; font-weight: 600; color: #0f172a;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.oa-user__sub {
    margin-top: 4px;
    display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
    font-size: 12px; color: #9ca3af;
}
.oa-user__role {
    padding: 1px 8px; border-radius: 999px;
    background: #eef2ff; color: #4f46e5;
    font-size: 11px; font-weight: 500;
}
.oa-user__actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

/* ---------- 功能入口（两张卡） ---------- */
.oa-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.oa-tile {
    display: flex; flex-direction: column; align-items: flex-start; gap: 4px;
    padding: 16px 18px 18px;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 14px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
    cursor: pointer;
    text-align: left;
    font-family: inherit;
    transition: border-color 0.15s ease, background 0.15s ease;
}
.oa-tile:hover { border-color: #c7d2fe; background: #fafbff; }
.oa-tile:hover .oa-tile__name,
.oa-tile:hover .oa-tile__arrow { color: #4f46e5; }
.oa-tile__top {
    display: flex; align-items: center; gap: 9px;
    width: 100%;
    min-width: 0;
}
.oa-tile__icon {
    width: 25px; height: 25px;
    flex-shrink: 0;
    border-radius: 5px;
    background: var(--tint-soft);
    color: var(--tint);
    display: flex; align-items: center; justify-content: center;
    font-size: 15px;
}
.oa-tile__icon--indigo { --tint: #4f46e5; --tint-soft: #eef2ff; }
.oa-tile__icon--green  { --tint: #059669; --tint-soft: #ecfdf5; }
.oa-tile__icon--blue   { --tint: #2563eb; --tint-soft: #eff6ff; }
.oa-tile__icon--orange { --tint: #ea580c; --tint-soft: #fff7ed; }
.oa-tile__icon--teal   { --tint: #0d9488; --tint-soft: #f0fdfa; }
.oa-tile__icon--purple { --tint: #7c3aed; --tint-soft: #f5f3ff; }
.oa-tile__icon--rose   { --tint: #e11d48; --tint-soft: #fff1f2; }
.oa-tile__icon--cyan   { --tint: #0891b2; --tint-soft: #ecfeff; }
.oa-tile__name {
    min-width: 0;
    font-size: 13.5px; font-weight: 600; color: #111827;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    transition: color 0.15s ease;
}
.oa-tile__arrow {
    margin-left: auto;
    flex-shrink: 0;
    font-size: 15px;
    color: #cbd5e1;
    transition: color 0.15s ease;
}
.oa-tile__value {
    display: flex; align-items: baseline; gap: 3px;
    margin-top: 10px;
    font-size: 22px; font-weight: 700; color: #111827; line-height: 1.2;
}
.oa-tile__value small { font-size: 12px; font-weight: 500; color: #9ca3af; }
.oa-tile__value--money {
    font-family: Menlo, Consolas, Monaco, monospace;
    font-size: 20px;
    color: #e63c3e;
}
.oa-tile__value--note {
    font-size: 13.5px;
    font-weight: 600;
    color: #6b7280;
}
.oa-tile__desc { font-size: 11.5px; color: #9ca3af; line-height: 1.5; }
.oa-tile__foot {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    width: 100%;
}
.oa-tile__add {
    flex-shrink: 0;
    display: inline-flex; align-items: center; gap: 4px;
    padding: 0;
    border: 0; background: none;
    color: #4f46e5;
    font-size: 11.5px; font-family: inherit;
    cursor: pointer;
    transition: color 0.15s ease;
}
.oa-tile__add:hover { color: #4338ca; text-decoration: underline; }
.oa-tile__add i {
    font-size: 11px;
    line-height: 1;
    width: 11px;
    text-align: center;
}

/* ---------- 按钮 ---------- */
.oa-btn {
    display: inline-flex; align-items: center; gap: 6px;
    height: 34px; padding: 0 16px;
    border: 0; border-radius: 8px;
    font-size: 13px; font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
}
.oa-btn i { font-size: 13px; }
.oa-btn--primary {
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: #fff;
    border: 1px solid transparent;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.24);
}
.oa-btn--primary:hover { background: linear-gradient(135deg, #4f46e5, #4338ca); color: #fff; }
.oa-btn--danger {
    background: #fff; color: #e11d48;
    border: 1px solid #fecdd3;
}
.oa-btn--danger:hover { background: #fff1f2; border-color: #fda4af; color: #be123c; }
.oa-btn:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 600px) {
    .oa-wrap { padding: 14px; }
    .oa-user { flex-wrap: wrap; }
    .oa-user__meta { flex: 1 1 100%; order: 3; }
    .oa-grid { grid-template-columns: 1fr; }
    .oa-tile { padding: 14px; }
}
</style>

<script>
$(function () {
    layui.use(['layer'], function () {
        var layer = layui.layer;
        var csrfToken = <?= json_encode($csrfToken) ?>;
        var ACCOUNT = {
            name: <?= json_encode($accName) ?>,
            email: <?= json_encode($accEmail) ?>,
            initial: <?= json_encode($accInitial) ?>
        };

        // 本页是后台里的 iframe 弹窗，自身 layer 开的窗口会被 iframe 裁切；
        // 所以窗口类弹层一律开到父窗口顶层。
        function topLayer() {
            try {
                if (window.top && window.top !== window && window.top.layui && window.top.layui.layer) {
                    return window.top.layui.layer;
                }
            } catch (e) {}
            return layer;
        }

        // 按父窗口视口量弹窗尺寸：宽度不超过 maxWidth，高度不超过视口，
        // 内容放不下时由 layer 的内容区滚动。
        function fitSize(content, maxWidth) {
            var win = window;
            var doc = document;
            try {
                if (window.top && window.top !== window && window.top.document) {
                    win = window.top;
                    doc = window.top.document;
                }
            } catch (e) {}

            var vw = win.innerWidth || doc.documentElement.clientWidth;
            var vh = win.innerHeight || doc.documentElement.clientHeight;
            var width = Math.min(maxWidth, vw - 24);

            var probe = doc.createElement('div');
            probe.style.cssText = 'position:absolute;left:-9999px;top:0;visibility:hidden;width:' + width + 'px';
            probe.innerHTML = content;
            doc.body.appendChild(probe);
            var innerH = probe.offsetHeight;
            doc.body.removeChild(probe);

            return { width: width, height: innerH, vh: vh };
        }

        function post(data) {
            data.csrf_token = csrfToken;
            return $.ajax({
                url: '/admin/license.php',
                type: 'POST',
                dataType: 'json',
                data: data
            }).then(function (res) {
                if (res && res.data && res.data.csrf_token) {
                    csrfToken = res.data.csrf_token;
                }
                return res;
            });
        }

        function esc(s) {
            return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        // 功能宫格：每个格子在新 iframe 窗口里打开（开在父窗口顶层）
        function openFrame(url, title, maxW, maxH) {
            var win = window;
            var doc = document;
            try {
                if (window.top && window.top !== window && window.top.document) {
                    win = window.top;
                    doc = window.top.document;
                }
            } catch (e) {}

            var vw = win.innerWidth || doc.documentElement.clientWidth;
            var vh = win.innerHeight || doc.documentElement.clientHeight;
            var width = Math.max(280, Math.min(maxW, vw - 24));
            var height = Math.max(200, Math.min(maxH, vh - 40));

            topLayer().open({
                type: 2,
                title: title,
                skin: 'admin-modal',
                area: [width + 'px', height + 'px'],
                shadeClose: true,
                content: url
            });
        }

        $(document).on('click', '.oa-tile', function () {
            var $tile = $(this);
            var url = String($tile.data('popup') || '');
            if (url === '') return;
            openFrame(
                url,
                String($tile.data('title') || ''),
                parseInt($tile.data('w'), 10) || 460,
                parseInt($tile.data('h'), 10) || 360
            );
        });

        // 卡片是 div[role=button]，键盘也要能触发（卡片里的「添加激活码」按钮除外）
        $(document).on('keydown', '.oa-tile', function (e) {
            if ($(e.target).closest('.oa-tile__add').length) return;
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault();
                $(this).trigger('click');
            }
        });

        // ---------- 添加激活码（独立 iframe 窗口） ----------
        function bumpLicenseCount(delta) {
            var $count = $('#oaLicCount');
            var n = parseInt($count.text(), 10);
            if (isNaN(n)) {
                loadOverview();
                return;
            }
            $count.text(n + delta);
        }

        $('#oaAddLicense').on('click', function (e) {
            e.stopPropagation();

            var L = topLayer();
            var win = window;
            try {
                if (window.top && window.top !== window) {
                    win = window.top;
                }
            } catch (err) {}

            // 添加窗口是独立 iframe：绑定成功后由它回调这里刷新数量。
            // 顶层和本 iframe 各挂一份 —— 弹层落在哪一层就用哪一份。
            var afterAdd = function () {
                try { delete win.__oaAfterAddLicense; } catch (err) {}
                try { delete window.__oaAfterAddLicense; } catch (err) {}
                bumpLicenseCount(1);
                L.msg('绑定成功');
            };
            win.__oaAfterAddLicense = afterAdd;
            window.__oaAfterAddLicense = afterAdd;

            var vw = win.innerWidth || win.document.documentElement.clientWidth;
            var vh = win.innerHeight || win.document.documentElement.clientHeight;
            var width = Math.max(300, Math.min(420, vw - 24));
            var height = Math.max(220, Math.min(320, vh - 40));

            L.open({
                type: 2,
                title: '添加激活码',
                skin: 'admin-modal',
                area: [width + 'px', height + 'px'],
                shadeClose: true,
                content: '/admin/license.php?_popup=add_license'
            });
        });

        // 宫格数据（余额 + 激活码数量）只发这一个请求，上游也是合并好的一个接口；
        // 取不到就不显示数字，不阻塞页面。顺手把资料缓存下来给「个人资料」用。
        var overviewUser = null;

        // 头部身份卡渲染时用的是登录那一刻的本地快照；概览回来后用上游最新资料覆盖，
        // 昵称 / 邮箱 / 角色跟着一起更新（退出确认卡也用这份快照）。
        var ROLE_LABELS = { admin: '管理员', agent: '代理商', user: '普通用户' };
        function applyIdentity(user) {
            var name = String(user.nickname || user.username || '官方账号');
            var email = String(user.email || '');
            var role = ROLE_LABELS[String(user.role || '')] || '用户';
            var initial = name.slice(0, 1).toUpperCase();

            $('#oaUserName').text(name);
            $('#oaUserAvatar').text(initial);
            $('#oaUserRole').text(role);
            $('#oaUserEmail').text(email).prop('hidden', email === '');

            // 代理 / 管理账号才显示「代理配置」「激活码仓库」
            var isStaff = user.role === 'agent' || user.role === 'admin';
            $('#oaAgentTile, #oaWarehouseTile, #oaVersionsTile, #oaOrdersTile, #oaAdsTile, #oaInstallsTile')
                .prop('hidden', !isStaff);

            ACCOUNT.name = name;
            ACCOUNT.email = email;
            ACCOUNT.initial = initial;
        }

        function loadOverview() {
            post({ _action: 'official_overview' }).done(function (res) {
                if (!res || res.code !== 200) return;
                var data = res.data || {};
                var user = data.user || {};
                overviewUser = user;

                applyIdentity(user);

                if (data.licenses_count !== undefined && data.licenses_count !== null) {
                    $('#oaLicCount').text(data.licenses_count);
                }
                var num = parseFloat(user.balance);
                $('#oaBalance').text('¥ ' + (isNaN(num) ? '0.00' : num.toFixed(2)));
            });
        }
        if ($('#oaLicCount').length || $('#oaBalance').length) {
            loadOverview();
        }

        // 个人资料弹窗：实时向官方站拉当前账号资料。
        // 弹窗开在父窗口顶层，样式随 content 一起注入，不依赖本页的 <style>。
        var PROFILE_CSS = ''
            + '.layui-layer.admin-modal .layui-layer-content{border-radius:0 0 12px 12px;}'
            + '.oa-profile{padding:16px;background:#f0f2f5;}'
            + '.oa-profile__head{display:flex;align-items:center;gap:14px;padding:16px 18px;background:#fff;border:1px solid #eef0f4;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);}'
            + '.oa-profile__avatar{width:46px;height:46px;flex-shrink:0;border-radius:12px;background:linear-gradient(135deg,#4f46e5 0%,#6366f1 100%);color:#fff;font-size:17px;font-weight:600;display:flex;align-items:center;justify-content:center;overflow:hidden;}'
            + '.oa-profile__avatar img{width:100%;height:100%;object-fit:cover;}'
            + '.oa-profile__meta{min-width:0;}'
            + '.oa-profile__name{font-size:16px;font-weight:600;color:#111827;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
            + '.oa-profile__tags{display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin-top:6px;}'
            + '.oa-profile__tag{padding:1px 6px;border-radius:3px;background:#f4f5f3;color:#6b7280;font-size:11px;line-height:18px;}'
            + '.oa-profile__tag--ok{background:#ecfdf5;color:#059669;}'
            + '.oa-profile__tag--off{background:#fef2f2;color:#dc2626;}'
            + '.oa-profile__card{margin-top:10px;padding:2px 16px;background:#fff;border:1px solid #eef0f4;border-radius:14px;box-shadow:0 1px 4px rgba(0,0,0,.04);}'
            + '.oa-profile__row{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid #f3f4f6;font-size:13px;}'
            + '.oa-profile__row:last-child{border-bottom:none;}'
            + '.oa-profile__label{width:76px;flex-shrink:0;color:#9ca3af;font-size:13px;}'
            + '.oa-profile__value{margin-left:auto;min-width:0;text-align:right;color:#111827;font-weight:500;word-break:break-all;}'
            + '.oa-profile__value--money{color:#e63c3e;font-weight:600;}'
            + '@media(max-width:380px){.oa-profile{padding:12px;}.oa-profile__head{padding:14px;gap:11px;}.oa-profile__avatar{width:42px;height:42px;font-size:16px;}.oa-profile__label{width:66px;}}';

        function profileHtml(u) {
            var name = String(u.nickname || u.username || '官方账号');
            var avatar = String(u.avatar || '');
            var avatarHtml = (/^(https?:)?\/\//.test(avatar) || avatar.charAt(0) === '/')
                ? '<img src="' + esc(avatar) + '" alt="">'
                : esc(name.slice(0, 1).toUpperCase());
            var roleMap = { admin: '管理员', agent: '代理商', user: '普通用户' };
            var role = roleMap[String(u.role || '')] || '用户';
            var active = parseInt(u.status, 10) === 1;

            // 账号余额在上游是「元」的两位小数字符串（如 12.30）
            var balanceNum = parseFloat(u.balance);

            var rows = [
                ['用户名', u.username, ''],
                ['邮箱', u.email, ''],
                ['账号余额', '¥ ' + (isNaN(balanceNum) ? '0.00' : balanceNum.toFixed(2)), ' oa-profile__value--money'],
                ['注册时间', u.created_at, ''],
                ['最后登录', u.last_login_at, '']
            ];
            var list = '';
            rows.forEach(function (r) {
                var val = (r[1] === undefined || r[1] === null || r[1] === '') ? '—' : r[1];
                list += '<div class="oa-profile__row">' +
                            '<span class="oa-profile__label">' + r[0] + '</span>' +
                            '<span class="oa-profile__value' + r[2] + '">' + esc(val) + '</span>' +
                        '</div>';
            });

            return '<div class="oa-profile">' +
                        '<div class="oa-profile__head">' +
                            '<div class="oa-profile__avatar">' + avatarHtml + '</div>' +
                            '<div class="oa-profile__meta">' +
                                '<div class="oa-profile__name">' + esc(name) + '</div>' +
                                '<div class="oa-profile__tags">' +
                                    '<span class="oa-profile__tag">' + esc(role) + '</span>' +
                                    '<span class="oa-profile__tag ' + (active ? 'oa-profile__tag--ok' : 'oa-profile__tag--off') + '">' + (active ? '正常' : '已禁用') + '</span>' +
                                '</div>' +
                            '</div>' +
                        '</div>' +
                        '<div class="oa-profile__card">' + list + '</div>' +
                    '</div>';
        }

        // 退出登录确认：自绘确认卡（图标 + 说明 + 当前账号 + 双按钮）
        var LOGOUT_CSS = ''
            + '.oa-logout{padding:26px 22px 20px;background:#fff;border-radius:12px;text-align:center;}'
            + '.oa-logout__icon{width:56px;height:56px;margin:0 auto 14px;border-radius:18px;background:#ffe4e6;color:#e11d48;display:flex;align-items:center;justify-content:center;font-size:23px;box-shadow:0 6px 16px rgba(225,29,72,.14);}'
            + '.oa-logout__title{font-size:16px;font-weight:600;color:#0f172a;}'
            + '.oa-logout__desc{margin-top:8px;font-size:12.5px;line-height:1.7;color:#6b7280;}'
            + '.oa-logout__account{display:flex;align-items:center;gap:9px;margin-top:16px;padding:9px 12px;border:1px solid #eef0f4;border-radius:10px;background:#fafbfc;text-align:left;}'
            + '.oa-logout__account-avatar{width:28px;height:28px;flex-shrink:0;border-radius:50%;background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;font-size:12px;font-weight:600;display:flex;align-items:center;justify-content:center;}'
            + '.oa-logout__account-meta{display:flex;flex-direction:column;min-width:0;}'
            + '.oa-logout__account-name{font-size:12.5px;font-weight:600;color:#0f172a;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
            + '.oa-logout__account-email{font-size:11.5px;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}'
            + '.oa-logout__actions{display:flex;gap:10px;margin-top:18px;}'
            + '.oa-logout__btn{flex:1;height:38px;border-radius:9px;font-size:13px;font-weight:500;cursor:pointer;transition:background .15s ease,border-color .15s ease,color .15s ease;}'
            + '.oa-logout__btn--cancel{border:1px solid #e2e8f0;background:#fff;color:#475569;}'
            + '.oa-logout__btn--cancel:hover{background:#f8fafc;border-color:#cbd5e1;}'
            + '.oa-logout__btn--ok{border:1px solid #e11d48;background:#e11d48;color:#fff;}'
            + '.oa-logout__btn--ok:hover{background:#be123c;border-color:#be123c;}'
            + '.oa-logout__btn:disabled{opacity:.6;cursor:not-allowed;}'
            + '@media(max-width:380px){.oa-logout{padding:22px 16px 16px;}.oa-logout__actions{gap:8px;}}';

        function logoutHtml() {
            var initial = String(ACCOUNT.initial || ACCOUNT.name.slice(0, 1).toUpperCase());
            var email = ACCOUNT.email
                ? '<span class="oa-logout__account-email">' + esc(ACCOUNT.email) + '</span>'
                : '';

            return '<style>' + LOGOUT_CSS + '</style>' +
                '<div class="oa-logout">' +
                    '<div class="oa-logout__icon"><i class="fa fa-sign-out"></i></div>' +
                    '<div class="oa-logout__title">退出官方账号</div>' +
                    '<div class="oa-logout__desc">退出后需重新登录，才能继续管理账号下的激活码。</div>' +
                    '<div class="oa-logout__account">' +
                        '<span class="oa-logout__account-avatar">' + esc(initial) + '</span>' +
                        '<span class="oa-logout__account-meta">' +
                            '<span class="oa-logout__account-name">' + esc(ACCOUNT.name) + '</span>' +
                            email +
                        '</span>' +
                    '</div>' +
                    '<div class="oa-logout__actions">' +
                        '<button type="button" class="oa-logout__btn oa-logout__btn--cancel">取消</button>' +
                        '<button type="button" class="oa-logout__btn oa-logout__btn--ok">退出登录</button>' +
                    '</div>' +
                '</div>';
        }

        // 弹窗尺寸全部按父窗口视口算：宽度最多 420，高度不超过视口，
        // 内容放不下时由 layer 的内容区滚动（标题栏约 50px）。
        function openProfile(u) {
            var content = '<style>' + PROFILE_CSS + '</style>' + profileHtml(u);
            var size = fitSize(content, 420);
            var height = Math.max(160, Math.min(size.height + 50, size.vh - 24));

            topLayer().open({
                type: 1,
                title: '个人资料',
                skin: 'admin-modal',
                area: [size.width + 'px', height + 'px'],
                shadeClose: true,
                content: content
            });
        }

        $('#oaProfile').on('click', function () {
            // 概况请求里已经带了账号资料，直接开；只有那次失败没拿到才补拉
            if (overviewUser) {
                openProfile(overviewUser);
                return;
            }

            var L = topLayer();
            var loadIdx = L.load(2, { shade: [0.08, '#fff'] });
            post({ _action: 'official_profile' }).done(function (res) {
                L.close(loadIdx);
                if (!res || res.code !== 200) {
                    L.msg((res && res.msg) || '获取个人资料失败');
                    return;
                }
                overviewUser = (res.data && res.data.user) || {};
                openProfile(overviewUser);
            }).fail(function () {
                L.close(loadIdx);
                L.msg('网络异常，请稍后重试');
            });
        });

        // 退出登录：自绘确认卡，确认后退出并刷新父页面（父页面会回到未登录状态）
        $('#oaSwitch').on('click', function () {
            var L = topLayer();
            var content = logoutHtml();
            var size = fitSize(content, 360);

            L.open({
                type: 1,
                title: false,
                skin: 'admin-modal',
                closeBtn: 0,
                shadeClose: true,
                area: [size.width + 'px', Math.min(size.height, size.vh - 24) + 'px'],
                content: content,
                success: function (layero, index) {
                    var $ok = layero.find('.oa-logout__btn--ok');
                    var $cancel = layero.find('.oa-logout__btn--cancel');

                    $cancel.on('click', function () {
                        L.close(index);
                    });

                    $ok.on('click', function () {
                        $ok.prop('disabled', true).text('退出中…');
                        $cancel.prop('disabled', true);
                        post({ _action: 'official_logout' }).done(function (res) {
                            if (res && res.code === 200) {
                                if (window.parent && window.parent !== window) {
                                    window.parent.location.reload();
                                } else {
                                    location.reload();
                                }
                                return;
                            }
                            L.msg((res && res.msg) || '退出失败');
                            $ok.prop('disabled', false).text('退出登录');
                            $cancel.prop('disabled', false);
                        }).fail(function () {
                            L.msg('网络异常');
                            $ok.prop('disabled', false).text('退出登录');
                            $cancel.prop('disabled', false);
                        });
                    });
                }
            });
        });

    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
