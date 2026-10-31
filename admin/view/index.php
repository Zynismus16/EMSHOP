<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
$csrfToken = Csrf::token();

// 未签署免责协议时强制弹出签署窗（演示模式不弹：后台写操作被禁用，签了也存不进去）
$emDisclaimerShow = (!defined('EM_DEMO_MODE') || !EM_DEMO_MODE) && !DisclaimerService::accepted();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>后台管理 - <?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="icon" href="<?php echo htmlspecialchars(site_favicon_href(), ENT_QUOTES, 'UTF-8'); ?>">
    <link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
    <link rel="stylesheet" href="/content/static/lib/font-awesome-4.7.0/css/font-awesome.min.css">
    <link rel="stylesheet" href="/content/static/lib/cropper.min.css">
    <link rel="stylesheet" href="/content/static/lib/viewer.js/viewer.min.css">
    <link rel="stylesheet" href="/admin/static/css/reset-layui.css">
    <link rel="stylesheet" href="/admin/static/css/admin.css">
    <link rel="stylesheet" href="/admin/static/css/admin-modal.css">
    <link rel="stylesheet" href="/admin/static/css/style.css">
    <link rel="stylesheet" href="/admin/static/css/disclaimer.css">
    <link rel="stylesheet" href="/content/static/css/em-toast.css">
    <script src="/content/static/lib/jquery.min.3.5.1.js"></script>
    <script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
    <script src="/content/static/js/em-toast.js"></script>

    <script src="/content/static/lib/tinymce/tinymce.min.js"></script> 
    <script src="/admin/static/js/em-editor-upload.js"></script>
    <script src="/admin/static/js/em-tinymce.js"></script>
 

    <style>
    /* ================ 授权服务器线路切换（toolbar 全局）================ */
    .admin-line-switch { position: relative; }
    .admin-line-switch__trigger {
        display: inline-flex; align-items: center; gap: 6px;
    }
    .admin-line-switch__dot {
        color: #10b981;
        animation: adminLinePulse 2s ease-in-out infinite;
    }
    @keyframes adminLinePulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50%      { opacity: 0.5; transform: scale(0.88); }
    }
    .admin-line-switch__name {
        font-weight: 500;
        max-width: 120px;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .admin-line-switch__dropdown {
        position: absolute; top: calc(100% + 6px); right: 0;
        min-width: 237px;
        background: #fff;
        border: 1px solid #e4e7eb;
        border-radius: 12px;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.14);
        padding: 8px;
        z-index: 1000;
        opacity: 0; visibility: hidden;
        transform: translateY(-4px);
        transition: opacity 0.15s ease, transform 0.15s ease, visibility 0s linear 0.15s;
    }
    .admin-line-switch__dropdown.is-open {
        opacity: 1; visibility: visible; transform: translateY(0);
        transition: opacity 0.15s ease, transform 0.15s ease, visibility 0s;
    }
    .admin-line-switch__title {
        display: flex; align-items: center; gap: 5px;
        font-size: 11px; color: #9ca3af;
        text-transform: uppercase; letter-spacing: 0.6px;
        padding: 8px 12px 6px;
    }
    /* 标题旁的「查看解释」图标：说明气泡由 layui 的 layer.tips 负责 */
    .admin-line-switch__help {
        display: inline-flex; align-items: center;
        color: #b3b9c4; cursor: help;
        letter-spacing: 0; text-transform: none;
    }
    .admin-line-switch__help:hover { color: #6366f1; }
    .admin-line-switch__item {
        display: flex; align-items: center; gap: 10px;
        width: 100%; padding: 10px 12px;
        background: transparent; border: none; border-radius: 8px;
        cursor: pointer; text-align: left;
        transition: background 0.15s ease;
    }
    .admin-line-switch__item:hover { background: #f8fafc; }
    .admin-line-switch__item.is-current { background: #f5f3ff; }
    .admin-line-switch__item > i.fa-circle-o,
    .admin-line-switch__item > i.fa-check-circle {
        font-size: 12px; color: #cbd5e1; flex-shrink: 0;
    }
    .admin-line-switch__item.is-current > i.fa-check-circle { color: #6366f1; }
    .admin-line-switch__body { flex: 1; min-width: 0; }
    .admin-line-switch__item-name {
        font-size: 13px; font-weight: 500; color: #111827;
    }
    .admin-line-switch__ms {
        margin-left: auto; flex-shrink: 0;
        font-size: 11px; color: #9ca3af;
        font-variant-numeric: tabular-nums;
    }
    .admin-line-switch__ms.is-ok   { color: #10b981; }
    .admin-line-switch__ms.is-warn { color: #f59e0b; }
    .admin-line-switch__ms.is-bad  { color: #ef4444; }
    @media (max-width: 600px) {
        .admin-line-switch__dropdown { right: -143px; min-width: 260px; }
    }
    </style>
    <?php doAction('adm_head'); ?>
</head>
<body<?php if (!empty($_COOKIE['admin_sidebar_collapsed']) && $_COOKIE['admin_sidebar_collapsed'] === '1') { echo ' class="sidebar-pre-collapsed"'; } ?>>
<div class="admin-shell">
    <!-- 移动端遮罩 -->
    <div class="admin-overlay" id="adminOverlay"></div>

    <div class="admin-container">
        <!-- 左侧菜单 -->
        <aside class="admin-sidebar<?php if (!empty($_COOKIE['admin_sidebar_collapsed']) && $_COOKIE['admin_sidebar_collapsed'] === '1') { echo ' is-collapsed'; } ?>" id="adminSidebar">
            <div class="admin-sidebar__header">
                <a href="/admin/home.php" data-pjax="#adminContent" class="admin-sidebar__site-name"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></a>
            </div>

            <div class="admin-sidebar__body">
                <!-- 菜单分组标题：控制台 -->
                <div class="admin-menu-title"><?= t('Dashboard'); ?></div>

                <!-- 1. 控制台 -->
                <a href="/admin/home.php" data-pjax="#adminContent" class="admin-menu-item is-active">
                    <i class="fa fa-dashboard"></i>
                    <span><?= t('控制台'); ?></span>
                </a>

                <!-- 菜单分组标题：商城 -->
                <div class="admin-menu-title"><?= t('Store'); ?></div>

                <!-- 2. 商品管理 -->
                <div class="admin-menu-group">
                    <div class="admin-menu-group__header" data-group="product">
                        <i class="fa fa-shopping-bag"></i>
                        <span><?= t('商品管理'); ?></span>
                        <i class="fa fa-angle-right"></i>
                    </div>
                    <div class="admin-menu-group__body">
                        <a href="/admin/goods.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('商品列表'); ?></a>
                        <a href="/admin/goods_category.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('商品分类'); ?></a>
                        <a href="/admin/goods_tag.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('商品标签'); ?></a>
                        <a href="/admin/coupon.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('优惠券'); ?></a>
                    </div>
                </div>

                <!-- 3. 订单管理 -->
                <div class="admin-menu-group">
                    <div class="admin-menu-group__header" data-group="order">
                        <i class="fa fa-file-text"></i>
                        <span><?= t('订单管理'); ?></span>
                        <i class="fa fa-angle-right"></i>
                    </div>
                    <div class="admin-menu-group__body">
                        <a href="/admin/order.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('商品订单'); ?></a>
                        <a href="/admin/recharge.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('充值订单'); ?></a>
                        <a href="/admin/app_order.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('应用订单'); ?></a>
                        <a href="/admin/withdraw.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('提现申请'); ?></a>
                        <a href="/admin/merchant_order.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('分站订单'); ?></a>
                    </div>
                </div>

                <!-- 4. 用户管理 -->
                <div class="admin-menu-group">
                    <div class="admin-menu-group__header" data-group="user">
                        <i class="fa fa-users"></i>
                        <span><?= t('用户管理'); ?></span>
                        <i class="fa fa-angle-right"></i>
                    </div>
                    <div class="admin-menu-group__body">
                        <a href="/admin/user_list.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('用户列表'); ?></a>
                        <a href="/admin/user_level.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('用户等级'); ?></a>
                        <a href="/admin/merchant_level.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('商户等级'); ?></a>
                        <a href="/admin/commission.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('推广返佣'); ?></a>
                    </div>
                </div>

                <!-- 5. 博客管理 -->
                <div class="admin-menu-group">
                    <div class="admin-menu-group__header" data-group="blog">
                        <i class="fa fa-pencil-square"></i>
                        <span><?= t('博客管理'); ?></span>
                        <i class="fa fa-angle-right"></i>
                    </div>
                    <div class="admin-menu-group__body">
                        <a href="/admin/blog.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('文章列表'); ?></a>
                        <a href="/admin/blog_category.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('文章分类'); ?></a>
                        <a href="/admin/blog_comment.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('评论管理'); ?></a>
                        <a href="/admin/blog_tag.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('标签管理'); ?></a>
                    </div>
                </div>

                <!-- 菜单分组标题：设置 -->
                <div class="admin-menu-title"><?= t('Settings'); ?></div>

                <!-- 6. 外观设置 -->
                <div class="admin-menu-group">
                    <div class="admin-menu-group__header" data-group="appearance">
                        <i class="fa fa-paint-brush"></i>
                        <span><?= t('外观设置'); ?></span>
                        <i class="fa fa-angle-right"></i>
                    </div>
                    <div class="admin-menu-group__body">
                        <a href="/admin/template.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('模板管理'); ?></a>
                        <a href="/admin/navi.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('导航管理'); ?></a>
                        <a href="/admin/page.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('页面管理'); ?></a>
                        <a href="/admin/friend_link.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('友情链接'); ?></a>
                    </div>
                </div>

                <!-- 7. 语言设置 -->
                <div class="admin-menu-group">
                    <div class="admin-menu-group__header" data-group="language">
                        <i class="fa fa-globe"></i>
                        <span><?= t('语言设置'); ?></span>
                        <i class="fa fa-angle-right"></i>
                    </div>
                    <div class="admin-menu-group__body">
                        <a href="/admin/language.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('语言列表'); ?></a>
                        <a href="/admin/lang.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('翻译管理'); ?></a>
                    </div>
                </div>

                <!-- 8. 插件管理 -->
                <a href="/admin/plugin.php" data-pjax="#adminContent" class="admin-menu-item">
                    <i class="fa fa-puzzle-piece"></i>
                    <span><?= t('插件管理'); ?></span>
                </a>

                <!-- 插件侧栏菜单挂载点：插件自行 addAction('adm_sidebar_menu') 输出菜单项。
                     只在插件启用（已加载）时才会注册，禁用即不出现。
                     单项写法参考上面的「插件管理」：<a href="..." data-pjax="#adminContent" class="admin-menu-item"> -->
                <?php doAction('adm_sidebar_menu'); ?>

                <!-- 9. 系统管理 -->
                <div class="admin-menu-group">
                    <div class="admin-menu-group__header" data-group="system">
                        <i class="fa fa-gears"></i>
                        <span><?= t('系统管理'); ?></span>
                        <i class="fa fa-angle-right"></i>
                    </div>
                    <div class="admin-menu-group__body">
                        <a href="/admin/settings.php?action=base" data-pjax="#adminContent" class="admin-menu-item"><?= t('基础设置'); ?></a>
                        <a href="/admin/currency.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('货币配置'); ?></a>
                        <a href="/admin/attachment.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('资源管理'); ?></a>
                        <a href="/admin/system_log.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('系统日志'); ?></a>
                        <a href="/admin/disclaimer.php" data-pjax="#adminContent" class="admin-menu-item"><?= t('免责协议'); ?></a>
                    </div>
                </div>

                <!-- 10. 应用商店 -->
                <a href="/admin/appstore.php" data-pjax="#adminContent" class="admin-menu-item">
                    <i class="fa fa-shopping-basket"></i>
                    <span><?= t('应用商店'); ?></span>
                </a>

            </div>
        </aside>

        <!-- 右侧区域 -->
        <div class="admin-right">
            <!-- 右上工具栏 -->
            <div class="admin-toolbar">
                <div class="admin-toolbar__left">
                    <button type="button" class="admin-toolbar__toggle" id="adminSidebarToggle">
                        <i class="fa fa-bars"></i>
                    </button>
                    <span class="admin-toolbar__page-title"><?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="admin-toolbar__right">
                    <!-- 语言切换 -->
                    <div class="admin-lang-switch" id="adminLangSwitch">
                        <button type="button" class="admin-toolbar__ghost admin-lang-switch__trigger">
                            <i class="fa fa-globe"></i>
                            <span id="currentLangName"><?php
                                $currentLangCode = isset($_COOKIE['admin_lang']) ? $_COOKIE['admin_lang'] : '';
                                $currentLangName = '简体中文';
                                $langOptionsHtml = '';
                                foreach ($languages as $lang) {
                                    $isActive = $lang['code'] === $currentLangCode;
                                    if ($isActive) $currentLangName = $lang['name'];
                                    $iconHtml = $lang['icon']
                                        ? '<img src="' . htmlspecialchars($lang['icon'], ENT_QUOTES, 'UTF-8') . '" alt="">'
                                        : '<i class="fa fa-globe" style="width:20px;text-align:center;color:#9ca3af;"></i>';
                                    $activeClass = $isActive ? ' is-active' : '';
                                    $langOptionsHtml .= '<div class="admin-lang-option' . $activeClass . '" data-code="' . htmlspecialchars($lang['code'], ENT_QUOTES, 'UTF-8') . '" data-name="' . htmlspecialchars($lang['name'], ENT_QUOTES, 'UTF-8') . '">' . $iconHtml . '<span>' . htmlspecialchars($lang['name'], ENT_QUOTES, 'UTF-8') . '</span></div>';
                                }
                                echo htmlspecialchars($currentLangName, ENT_QUOTES, 'UTF-8');
                            ?></span>
                            <i class="fa fa-angle-down"></i>
                        </button>
                        <div class="admin-lang-dropdown" id="adminLangDropdown"><?php echo $langOptionsHtml; ?></div>
                    </div>
                    
                    <?php
                    // ============ 授权服务器线路切换（全局 toolbar）============
                    // 放在 toolbar 而非单页，原因：
                    //   - 后台首页 / license 页都会请求中心服务；线路不通时任何页都需要能切换
                    //   - PJAX 不会重载 toolbar，切换后状态在所有页面一致
                    // 线路列表来自 LicenseClient（多线路）。请求只会走当前选中的线路，
                    // 线路不通由用户在这里手动切换；只有一条线路时不渲染。
                    $__lines = [];
                    $__lineIdx = 0;
                    try {
                        $__lines = LicenseService::getAllLines();
                        $__lineIdx = LicenseService::currentLineIndex();
                    } catch (Throwable $e) {
                        // 不影响布局
                    }
                    if (count($__lines) > 1):
                    ?>
                    <div class="admin-line-switch" id="adminLineSwitch">
                        <button type="button" class="admin-toolbar__ghost admin-line-switch__trigger">
                            <i class="fa fa-signal admin-line-switch__dot"></i>
                            <span class="admin-line-switch__name" id="adminLineName"><?= htmlspecialchars((string) ($__lines[$__lineIdx]['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                            <i class="fa fa-angle-down"></i>
                        </button>
                        <div class="admin-line-switch__dropdown" id="adminLineDropdown">
                            <div class="admin-line-switch__title">
                                切换官方服务线路
                                <i class="fa fa-question-circle admin-line-switch__help" aria-label="查看解释"></i>
                            </div>
                            <?php foreach ($__lines as $__i => $__ln): ?>
                            <button type="button" class="admin-line-switch__item <?= $__i === $__lineIdx ? 'is-current' : '' ?>" data-idx="<?= $__i ?>">
                                <i class="fa <?= $__i === $__lineIdx ? 'fa-check-circle' : 'fa-circle-o' ?>"></i>
                                <div class="admin-line-switch__body">
                                    <div class="admin-line-switch__item-name"><?= htmlspecialchars((string) $__ln['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                </div>
                                <span class="admin-line-switch__ms">--</span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="admin-user-menu" id="adminUserMenu">
                        <button type="button" class="admin-user-menu__trigger">
                            <span class="admin-avatar"><img src="<?php echo htmlspecialchars((string) ($user['avatar'] ?: (EM_CONFIG['avatar'] ?? '/content/static/img/avatar.jpeg')), ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;" onerror="this.src='<?php echo htmlspecialchars(EM_CONFIG['avatar'] ?? '/content/static/img/avatar.jpeg', ENT_QUOTES, 'UTF-8'); ?>';this.onerror=null;"></span>
                            <span class="admin-user-menu__meta">
                                <strong><?php echo htmlspecialchars((string) (!empty($user['nickname']) ? $user['nickname'] : $user['username']), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small>超级管理员</small>
                            </span>
                            <span class="admin-user-menu__arrow"><i class="fa fa-angle-down"></i></span>
                        </button>
                        <div class="admin-user-menu__dropdown">
                            <a href="/" target="_blank" class="admin-dropdown-link"><i class="fa fa-home"></i> 网站首页</a>
                            <a href="/admin/profile.php" data-pjax="#adminContent" class="admin-dropdown-link"><i class="fa fa-user"></i> 个人信息</a>

                            <!-- 移动端：语言切换 -->
                            <div class="admin-user-menu__lang" id="mobileLangSwitch">
                                <button type="button" class="admin-dropdown-link admin-dropdown-link--lang">
                                    <i class="fa fa-globe"></i>
                                    <span id="mobileLangName"><?php echo htmlspecialchars($currentLangName, ENT_QUOTES, 'UTF-8'); ?></span>
                                </button>
                                <div class="admin-lang-dropdown" id="mobileLangDropdown"><?php echo $langOptionsHtml; ?></div>
                            </div>
                            <!-- 移动端：清除缓存 -->
                            <button type="button" class="admin-dropdown-link" id="mobileDropdownClearCache"><i class="fa fa-refresh"></i> 清除缓存</button>
                            <a href="/admin/index.php?action=logout" class="admin-dropdown-link admin-dropdown-link--danger"><i class="fa fa-power-off"></i> 退出登录</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 右下内容区 -->
            <div id="adminContent" class="admin-content">
                <?php include $adminContentView; ?>
            </div>
        </div>
    </div>
</div>


<script src="/content/static/lib/jquery.pjax.js"></script>
<script src="/content/static/lib/cropper.min.js"></script>
<script src="/content/static/lib/viewer.js/viewer.min.js"></script>
<script src="/content/static/lib/echarts.min.js"></script>
<script src="/content/static/lib/qrcode.min.js"></script>
<script>window.adminCsrfToken = <?php echo json_encode($csrfToken); ?>;</script>
<script src="/admin/static/js/em-editor-upload.js"></script>
<script src="/admin/static/js/admin.js"></script>
<script>
$(function () {
    layui.use(['layer'], function () {
        var layer = layui.layer;
        <?php if (!empty($emServerHardRestartPending)): ?>
        layer.open({
            type: 2,
            title: '需要重启任务服务',
            skin: 'admin-modal',
            maxmin: false,
            area: [window.innerWidth >= 640 ? '560px' : '94%', window.innerHeight >= 640 ? '550px' : '80%'],
            shade: 0.45,
            shadeClose: true,
            content: '/admin/home.php?_popup=server_restart'
        });
        <?php endif; ?>
        // ========== 授权服务器线路切换（toolbar 全局）==========
        var $lineWrap = $('#adminLineSwitch');
        if ($lineWrap.length) {
            // 标题旁「查看解释」：用 layui 自带的 layer.tips，悬停显示、移开关闭
            var __lineTipIdx = null;
            $lineWrap.find('.admin-line-switch__help').on('mouseenter', function () {
                __lineTipIdx = layer.tips(
                    '官方服务线路用于程序版本 / 模板 / 插件的安装与更新，如官方服务不可用，请切换其他线路后重试。',
                    this,
                    { tips: [3, '#1f2937'], time: 0, maxWidth: 240 }
                );
            }).on('mouseleave', function () {
                if (__lineTipIdx !== null) {
                    layer.close(__lineTipIdx);
                    __lineTipIdx = null;
                }
            });

            // 线路延迟：展开下拉时并发测一遍（服务端对每条线路发一个 GET），
            // 结果缓存 60 秒，避免每次展开都打一遍官方服务器
            var __lineLatencyAt = 0, __linePinging = false;
            function renderLineLatency(lines) {
                $('#adminLineDropdown .admin-line-switch__item').each(function () {
                    var idx = parseInt($(this).data('idx'), 10);
                    var $ms = $(this).find('.admin-line-switch__ms');
                    var row = null;
                    for (var i = 0; i < lines.length; i++) {
                        if (parseInt(lines[i].index, 10) === idx) { row = lines[i]; break; }
                    }
                    $ms.removeClass('is-ok is-warn is-bad');
                    if (!row || row.ms === null || row.ms < 0) {
                        // 服务端对传输失败（DNS 解析失败 / 连接被拒 / 超时）一律给 null
                        $ms.addClass('is-bad').text('失败');
                        return;
                    }
                    var ms = parseInt(row.ms, 10);
                    // 分档配色与首页线路卡一致：<150ms 绿 / 150-500ms 橙 / ≥500ms 红
                    $ms.addClass(ms < 150 ? 'is-ok' : (ms < 500 ? 'is-warn' : 'is-bad')).text(ms + ' ms');
                });
            }
            function loadLineLatency() {
                if (__linePinging || Date.now() - __lineLatencyAt < 60000) return;
                __linePinging = true;
                $('#adminLineDropdown .admin-line-switch__ms').removeClass('is-ok is-warn is-bad').text('…');
                $.ajax({
                    url: '/admin/license.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { _action: 'line_ping', csrf_token: window.adminCsrfToken },
                    timeout: 8000
                }).done(function (res) {
                    if (res && res.code === 200 && res.data && res.data.lines) {
                        __lineLatencyAt = Date.now();
                        renderLineLatency(res.data.lines);
                    } else {
                        $('#adminLineDropdown .admin-line-switch__ms').text('--');
                    }
                }).fail(function () {
                    $('#adminLineDropdown .admin-line-switch__ms').text('--');
                }).always(function () {
                    __linePinging = false;
                });
            }
            $lineWrap.find('.admin-line-switch__trigger').on('click', function (e) {
                e.stopPropagation();
                var $dropdown = $('#adminLineDropdown');
                $dropdown.toggleClass('is-open');
                if ($dropdown.hasClass('is-open')) loadLineLatency();
            });
            $(document).on('click', function (e) {
                if (!$(e.target).closest('#adminLineSwitch').length) {
                    $('#adminLineDropdown').removeClass('is-open');
                }
            });
            $(document).on('click', '#adminLineDropdown .admin-line-switch__item', function () {
                var idx = parseInt($(this).data('idx'), 10);
                var $item = $(this);
                $.ajax({
                    url: '/admin/license.php',
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        _action: 'switch_line',
                        index: idx,
                        csrf_token: window.adminCsrfToken
                    }
                }).done(function (res) {
                    if (res.code === 200) {
                        if (res.data && res.data.csrf_token) {
                            window.adminCsrfToken = res.data.csrf_token;
                        }
                        $('#adminLineName').text((res.data && res.data.current_name) || '');
                        $('#adminLineDropdown .admin-line-switch__item').removeClass('is-current').children('i.fa').first().removeClass('fa-check-circle').addClass('fa-circle-o');
                        $item.addClass('is-current').children('i.fa').first().removeClass('fa-circle-o').addClass('fa-check-circle');
                        $('#adminLineDropdown').removeClass('is-open');
                        layer.msg(res.msg || '已切换线路');
                        // 切换线路后刷新页面，让依赖当前线路的首页数据（代理商、公告、延迟等）重新拉取
                        setTimeout(function () { location.reload(); }, 700);
                    } else {
                        layer.msg(res.msg || '切换失败', {icon: 2});
                    }
                }).fail(function () {
                    layer.msg('网络异常', {icon: 2});
                });
            });
        }
    });
});
</script>

<?php if ($emDisclaimerShow): ?>
<!-- 免责协议签署弹层：未签署当前版本时强制显示，签署成功后才移除 -->
<div class="em-disclaimer" id="emDisclaimer">
    <div class="em-disclaimer__dialog" role="dialog" aria-modal="true" aria-labelledby="emDisclaimerTitle">
        <div class="em-disclaimer__head">
            <h3 id="emDisclaimerTitle">免责声明与使用协议</h3>
            <p>请完整阅读以下条款，同意后方可继续使用后台。签署后将记录协议版本、签署时间、IP 与管理员账号。</p>
        </div>
        <div class="em-disclaimer__body">
            <?php include __DIR__ . '/disclaimer_content.php'; ?>
        </div>
        <div class="em-disclaimer__foot">
            <label class="em-disclaimer__check">
                <input type="checkbox" id="emDisclaimerAgree">
                <span>我已阅读并同意以上全部条款</span>
            </label>
            <button type="button" class="em-disclaimer__btn" id="emDisclaimerAccept" disabled>同意并继续</button>
            <div class="em-disclaimer__error" id="emDisclaimerError"></div>
        </div>
    </div>
</div>
<script>
(function () {
    var mask = document.getElementById('emDisclaimer');
    var agree = document.getElementById('emDisclaimerAgree');
    var btn = document.getElementById('emDisclaimerAccept');
    var errorEl = document.getElementById('emDisclaimerError');
    if (!mask || !agree || !btn) {
        return;
    }

    // 弹层打开期间锁住背景滚动；Esc 不允许关闭
    document.body.style.overflow = 'hidden';
    function escGuard(e) {
        if (e.key === 'Escape' || e.keyCode === 27) {
            e.preventDefault();
            e.stopPropagation();
        }
    }
    document.addEventListener('keydown', escGuard, true);

    function closeMask() {
        document.removeEventListener('keydown', escGuard, true);
        document.body.style.overflow = '';
        if (mask.parentNode) {
            mask.parentNode.removeChild(mask);
        }
    }

    agree.addEventListener('change', function () {
        btn.disabled = !agree.checked;
        if (agree.checked) {
            errorEl.textContent = '';
        }
    });

    btn.addEventListener('click', function () {
        if (!agree.checked || btn.disabled) {
            return;
        }
        btn.disabled = true;
        btn.textContent = '提交中…';
        errorEl.textContent = '';

        $.ajax({
            url: '/admin/index.php?_action=disclaimer_accept',
            type: 'POST',
            dataType: 'json',
            data: {
                csrf_token: window.adminCsrfToken,
                agree: '1'
            }
        }).done(function (res) {
            if (res && res.code === 200) {
                closeMask();
                return;
            }
            btn.disabled = false;
            btn.textContent = '同意并继续';
            errorEl.textContent = (res && res.msg) ? res.msg : '提交失败，请重试';
        }).fail(function () {
            btn.disabled = false;
            btn.textContent = '同意并继续';
            errorEl.textContent = '网络异常，请稍后重试';
        });
    });
})();
</script>
<?php endif; ?>
</body>
</html>
 