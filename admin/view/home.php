<?php
if (!defined('EM_ROOT')) { 
    exit('Access Denied');
}
/** @var array<string, mixed> $adminUser */
/** @var string $siteName */ 

$esc = function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$__licenseActivated = LicenseService::isActivated();

// 主货币符号（从货币配置读，未配置时回退到 ¥）
$__primary = Currency::getInstance()->getPrimary();
$currencySymbol = $__primary ? ($__primary['symbol'] ?? '¥') : '¥';

/* ============================================================
 * 统计数据采集：view 自查，不依赖外层 controller 预填
 * ——  /admin/home.php（PJAX）和 /admin/index.php（默认）两处入口都用同一段
 * ============================================================ */
$__prefix  = Database::prefix();
$__today   = date('Y-m-d');

$stats = [
    'goods_total'     => 0,
    'order_total'     => 0,
    'user_total'      => 0,
    'blog_total'      => 0,
    'today_revenue'   => 0,
    'today_orders'    => 0,
    'pending_orders'  => 0,
    'today_users'     => 0,
];
try {
    $stats['goods_total']    = (int) (Database::fetchOne('SELECT COUNT(*) AS c FROM `' . $__prefix . 'goods` WHERE `deleted_at` IS NULL')['c'] ?? 0);
    $stats['order_total']    = (int) (Database::fetchOne('SELECT COUNT(*) AS c FROM `' . $__prefix . 'order`')['c'] ?? 0);
    $stats['user_total']     = (int) (Database::fetchOne('SELECT COUNT(*) AS c FROM `' . $__prefix . 'user` WHERE `role` = ?', ['user'])['c'] ?? 0);
    $stats['blog_total']     = (int) (Database::fetchOne('SELECT COUNT(*) AS c FROM `' . $__prefix . 'blog` WHERE `deleted_at` IS NULL AND `merchant_id` = 0')['c'] ?? 0);
    $stats['today_revenue']  = (int) (Database::fetchOne('SELECT COALESCE(SUM(`pay_amount`), 0) AS s FROM `' . $__prefix . 'order` WHERE `status` = ? AND DATE(`complete_time`) = ?', ['completed', $__today])['s'] ?? 0);
    $stats['today_orders']   = (int) (Database::fetchOne('SELECT COUNT(*) AS c FROM `' . $__prefix . 'order` WHERE DATE(`created_at`) = ?', [$__today])['c'] ?? 0);
    $stats['pending_orders'] = (int) (Database::fetchOne('SELECT COUNT(*) AS c FROM `' . $__prefix . 'order` WHERE `status` = ?', ['paid'])['c'] ?? 0);
    $stats['today_users']    = (int) (Database::fetchOne('SELECT COUNT(*) AS c FROM `' . $__prefix . 'user` WHERE `role` = ? AND DATE(`created_at`) = ?', ['user', $__today])['c'] ?? 0);
} catch (Throwable $e) {
    // 查询失败不影响页面
}

// 销售趋势：改为前端 AJAX 按需拉取（/admin/home.php?_action=trend&range=xxx），这里不再预加载
// 订单状态分布 / 最近订单 / 最近注册 已从 Dashboard 移除

// 系统信息
$sysInfo = [
    'php'              => PHP_VERSION,
    'db'               => 'MySQL',
    'server'           => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'version'          => defined('EM_VERSION') ? EM_VERSION : 'dev',
    'timezone'         => date_default_timezone_get(),
    'template_count'   => 0,
    'main_plugin_count' => 0,
    'merchant_plugin_count' => 0,
];
try {
    $__r = Database::fetchOne('SELECT VERSION() AS v');
    if ($__r !== null) $sysInfo['db'] = 'MySQL ' . (string) ($__r['v'] ?? '-');
    $sysInfo['template_count'] = count((new TemplateModel())->scanTemplates());
    $mainPlugins = (new PluginModel())->scanPlugins();
    $merchantCodes = [];
    $__rows = Database::query('SELECT `app_code` FROM `' . $__prefix . 'app_market` WHERE `type` = ?', ['plugin']);
    foreach ($__rows as $__row) {
        $__code = trim((string) ($__row['app_code'] ?? ''));
        if ($__code !== '') $merchantCodes[$__code] = true;
    }
    foreach ($merchantCodes as $__code => $__yes) {
        unset($mainPlugins[$__code]);
    }
    $sysInfo['main_plugin_count'] = count($mainPlugins);
    $__r = Database::fetchOne('SELECT COUNT(*) AS c FROM `' . $__prefix . 'app_market` WHERE `type` = ?', ['plugin']);
    if ($__r !== null) $sysInfo['merchant_plugin_count'] = (int) $__r['c'];
} catch (Throwable $e) {
}

$todayRevenueYuan = number_format(((int) $stats['today_revenue']) / 1000000, 2);

/* ============================================================
 * 6 指标卡数据采集（今日 / 昨日 / 日环比 / 本月）
 * —— 订单类指标都按 status=completed + complete_time 聚合
 * —— 利润 = 销售额 − 商户订单的拿货价合计（主站订单 cost_amount=0，暂视为毛利=销售额）
 * —— 访问量暂无后端存储，占位为 0；待接入 PV 统计后填充
 * ============================================================ */
$__dayStart   = $__today . ' 00:00:00';
$__dayEnd     = date('Y-m-d', strtotime($__today . ' +1 day')) . ' 00:00:00';
$__yestStart  = date('Y-m-d', strtotime($__today . ' -1 day')) . ' 00:00:00';
$__yestEnd    = $__dayStart;
$__monthStart = date('Y-m-01') . ' 00:00:00';
$__monthEnd   = date('Y-m-01', strtotime('+1 month')) . ' 00:00:00';

// 订单聚合查询（已完成订单的 revenue / orders / cost）
$__queryOrder = function ($start, $end) use ($__prefix) {
    try {
        $r = Database::fetchOne(
            'SELECT COUNT(*) AS orders, COALESCE(SUM(pay_amount), 0) AS revenue
               FROM `' . $__prefix . 'order`
              WHERE status = ? AND complete_time >= ? AND complete_time < ?',
            ['completed', $start, $end]
        );
        $c = Database::fetchOne(
            'SELECT COALESCE(SUM(og.cost_amount), 0) AS c
               FROM `' . $__prefix . 'order_goods` og
          INNER JOIN `' . $__prefix . 'order` o ON o.id = og.order_id
              WHERE o.status = ? AND o.complete_time >= ? AND o.complete_time < ?',
            ['completed', $start, $end]
        );
        return [
            'orders'  => (int) ($r['orders'] ?? 0),
            'revenue' => (int) ($r['revenue'] ?? 0),  // ×1000000
            'cost'    => (int) ($c['c'] ?? 0),         // ×1000000
        ];
    } catch (Throwable $e) {
        return ['orders' => 0, 'revenue' => 0, 'cost' => 0];
    }
};
$__queryNewUsers = function ($start, $end) use ($__prefix) {
    try {
        return (int) (Database::fetchOne(
            'SELECT COUNT(*) AS c FROM `' . $__prefix . 'user`
              WHERE role = ? AND created_at >= ? AND created_at < ?',
            ['user', $start, $end]
        )['c'] ?? 0);
    } catch (Throwable $e) {
        return 0;
    }
};

$__t = $__queryOrder($__dayStart,   $__dayEnd);
$__y = $__queryOrder($__yestStart,  $__yestEnd);
$__m = $__queryOrder($__monthStart, $__monthEnd);

$__tUsers = $__queryNewUsers($__dayStart,   $__dayEnd);
$__yUsers = $__queryNewUsers($__yestStart,  $__yestEnd);
$__mUsers = $__queryNewUsers($__monthStart, $__monthEnd);

// 从 ×1000000 整数 → 元
$__toYuan = static fn(int $v): float => round($v / 1000000, 2);

// 指标集（全部统一紫色主题）
// 6 张数据卡的指标定义；color/soft 为图标+"今日"标签的主次色，按业务语义分配不同色调便于快速辨识
$metrics = [
    [
        // 销售额 → 翡翠绿（金额/成交）
        'key'       => 'revenue',  'label' => '销售额',
        'icon'      => 'fa-cny',    'color' => '#10b981', 'soft' => '#ecfdf5',
        'unit'      => $currencySymbol, 'decimals' => 2,
        'today'     => $__toYuan($__t['revenue']),
        'yesterday' => $__toYuan($__y['revenue']),
        'month'     => $__toYuan($__m['revenue']),
    ],
    [
        // 订单量 → 玫红（订单/交易）
        'key'       => 'orders',   'label' => '订单量',
        'icon'      => 'fa-shopping-cart', 'color' => '#ec4899', 'soft' => '#fdf2f8',
        'unit'      => '',          'decimals' => 0,
        'today'     => (float) $__t['orders'],
        'yesterday' => (float) $__y['orders'],
        'month'     => (float) $__m['orders'],
    ],
    [
        // 利润 → 琥珀黄（盈利/财务）
        'key'       => 'profit',   'label' => '利润',
        'icon'      => 'fa-line-chart', 'color' => '#f59e0b', 'soft' => '#fffbeb',
        'unit'      => $currencySymbol, 'decimals' => 2,
        'today'     => $__toYuan(max(0, $__t['revenue'] - $__t['cost'])),
        'yesterday' => $__toYuan(max(0, $__y['revenue'] - $__y['cost'])),
        'month'     => $__toYuan(max(0, $__m['revenue'] - $__m['cost'])),
    ],
    [
        // 客单价 → 青绿（客均值）
        'key'       => 'aov',      'label' => '客单价',
        'icon'      => 'fa-tag',    'color' => '#14b8a6', 'soft' => '#ccfbf1',
        'unit'      => $currencySymbol, 'decimals' => 2,
        'today'     => $__t['orders'] > 0 ? round($__t['revenue'] / $__t['orders'] / 1000000, 2) : 0,
        'yesterday' => $__y['orders'] > 0 ? round($__y['revenue'] / $__y['orders'] / 1000000, 2) : 0,
        'month'     => $__m['orders'] > 0 ? round($__m['revenue'] / $__m['orders'] / 1000000, 2) : 0,
    ],
    [
        // 新增用户 → 紫罗兰（会员）
        'key'       => 'new_users', 'label' => '新增用户',
        'icon'      => 'fa-user-plus', 'color' => '#8b5cf6', 'soft' => '#f5f3ff',
        'unit'      => '',           'decimals' => 0,
        'today'     => (float) $__tUsers,
        'yesterday' => (float) $__yUsers,
        'month'     => (float) $__mUsers,
    ],
];

// 环比计算（返回 pct / state：up / down / zero）
// zero  → 持平（含两侧都为 0、非 0 但完全相等），红色 + 横线图标
// up    → 上升（含昨日 0 今日 > 0 的 ∞），绿色
// down  → 下降，红色
$calcRatio = static function (float $today, float $yesterday): array {
    if ($yesterday <= 0.0001 && $today <= 0.0001) {
        return ['state' => 'zero', 'pct' => '0.0'];
    }
    if ($yesterday <= 0.0001) {
        return ['state' => 'up', 'pct' => '∞'];
    }
    $diff = $today - $yesterday;
    if (abs($diff) <= 0.0001) {
        return ['state' => 'zero', 'pct' => '0.0'];
    }
    $pct = abs($diff) / $yesterday * 100;
    $pctStr = number_format($pct, $pct >= 100 ? 0 : 1);
    return ['state' => $diff > 0 ? 'up' : 'down', 'pct' => $pctStr];
};

// 运行模式：只有 CLI / FPM 两种。
//
// 判据必须与 OrderModel::triggerDelivery() **完全一致** —— 发货走异步还是同步，
// 全站唯一依据就是发货能力心跳 WorkerHeartbeat::CAPABILITY_DELIVERY。
// 卡片显示 CLI、实际却在同步发货（或反过来）是最坏的情况，所以这里复用同一个判据，
// 而不是看管家进程的宿主心跳：管家活着 ≠ 发货队列有人在消费。
// worker 明细（哪个任务在跑/停了）在「查看详细」弹窗里展示，首页只报模式。
$__deliveryAsync = WorkerHeartbeat::isAlive(WorkerHeartbeat::CAPABILITY_DELIVERY);
$__runMode       = $__deliveryAsync ? 'cli' : 'fpm';
$__runModeLabel  = $__deliveryAsync ? 'CLI 模式' : 'FPM 模式';
$__runModeDesc   = $__deliveryAsync
    ? '异步发货 · 常驻进程'
    : '同步发货 · 请求执行';

// 官方线路卡：当前选中的授权服务器线路。只读本地配置，不发请求；切换入口在后台顶栏
$__curLineName = '官方线路';
try {
    $__lines   = LicenseService::getAllLines();
    $__lineIdx = LicenseService::currentLineIndex();
    $__curLineName = (string) ($__lines[$__lineIdx]['name'] ?? $__curLineName);
} catch (Throwable $e) {
    // 读配置失败不影响页面
}

/* ============================================================
 * 官方公告 / 广告推广 / 版本更新 / 代理商联系方式
 *   这四块数据依赖中心服务器接口，同步拉取会拖慢首屏
 *   因此页面先渲染骨架，由 JS 异步请求 /admin/home.php?_action=admin_index_data
 * ============================================================ */
?>
<div class="admin-page">

    <?php
    // 后台首页顶部扩展点：tips / popup_gif 等插件在这里挂内容
    // 之前版本遗失了这条 doAction 调用，导致 tips 插件整体失效
    doAction('adm_main_top');
    ?>

    <?php if (!$__licenseActivated): ?>
    <!-- ================================ 未授权引导 Hero（仅未授权时展示） ================================ -->
    <section class="dash-hero dash-hero--warn">
        <div class="dash-hero__main">
            <div class="dash-hero__greet dash-hero__greet--warn">
                <i class="fa fa-exclamation-triangle"></i>
                <span>本站尚未激活正版授权</span>
            </div>
            <h1 class="dash-hero__title">激活正版 <?= $esc($siteName) ?> 解锁全部能力</h1>
            <p class="dash-hero__meta">
                享受专属模板 · 付费插件 · 应用商店 · 优先技术支持
            </p>
        </div>
        <div class="dash-hero__actions">
            <a href="/admin/license.php" data-pjax="#adminContent" class="dash-hero-btn">
                <i class="fa fa-shield"></i> 去激活
            </a>
            <button type="button" class="dash-hero-btn dash-hero-btn--ghost" id="dashGetLicense">
                <i class="fa fa-external-link"></i> 获取激活码
            </button>
        </div>
    </section>
    <?php endif; ?>

    <!-- ================================ 核心指标（6 卡 · 今日 / 昨日 / 日环比 / 本月） ================================ -->
    <section class="dash-metrics">
        <?php
            // 系统版本卡（固定第一位，结构复用 dash-metric，不含环比 chip）
            $__sysLevel = LicenseService::currentLevel();
            $__sysLevelLabel = LicenseService::levelLabel($__sysLevel);
        ?>
        <div class="dash-metric dash-metric--system" style="--m-color: #6366f1; --m-soft: #eef2ff;">
            <div class="dash-metric__head">
                <span class="dash-metric__icon"><i class="fa fa-cubes"></i></span>
                <span class="dash-metric__label">系统版本</span>
                <span class="dash-metric__level-tag dash-level--<?= $esc($__sysLevel) ?>"><?= $esc($__sysLevelLabel) ?></span>
            </div>

            <div class="dash-metric__main">
                <div class="dash-metric__value-row">
                    <span class="dash-metric__today-value">v<?= $esc(EM_VERSION) ?></span>
                </div>
                <div class="dash-metric__yesterday">
                    <a href="https://changelog.ihehe.me/" target="_blank" rel="noopener noreferrer" class="dash-changelog-link">
                        查看更新日志 <i class="fa fa-external-link"></i>
                    </a>
                </div>
            </div>

            <!-- 数据由 JS 异步注入；默认占位显示"检测中" -->
            <div class="dash-metric__month" id="dashVersionFooter">
                <span class="dash-metric__month-label" id="dashVersionLabel">
                    <i class="fa fa-spinner fa-spin" style="margin-right:4px;"></i>检测中…
                </span>
                <button type="button" class="dash-version-btn" id="dashCheckUpdate" disabled>
                    <i class="fa fa-refresh"></i> 检查更新
                </button>
            </div>
        </div>

        <div class="dash-metric dash-metric--action dash-metric--server" style="--m-color: <?= $__deliveryAsync ? '#10b981' : '#f59e0b' ?>; --m-soft: <?= $__deliveryAsync ? '#ecfdf5' : '#fffbeb' ?>;">
            <div class="dash-metric__head">
                <span class="dash-metric__icon"><i class="fa <?= $__deliveryAsync ? 'fa-terminal' : 'fa-globe' ?>"></i></span>
                <span class="dash-metric__label">运行模式</span>
                <span class="dash-metric__official-tag">核心</span>
            </div>
            <div class="dash-metric__main">
                <div class="dash-metric__value-row">
                    <span class="dash-metric__today-value dash-runmode dash-runmode--<?= $__runMode ?>"><?= $esc($__runModeLabel) ?></span>
                </div>
                <div class="dash-metric__yesterday"><?= $esc($__runModeDesc) ?></div>
            </div>
            <div class="dash-metric__month">
                <span class="dash-metric__month-label">两种模式说明</span>
                <button type="button" class="dash-version-btn" id="dashServerDetailBtn">查看详细</button>
            </div>
        </div>

        <?php foreach ($metrics as $m): ?>
        <?php
            $isEmpty = isset($m['empty']);
            $ratio = $calcRatio((float) $m['today'], (float) $m['yesterday']);
            $fmt = static function (float $v, int $d) {
                return number_format($v, $d);
            };
        ?>
        <div class="dash-metric" style="--m-color: <?= $m['color'] ?>; --m-soft: <?= $m['soft'] ?>;">
            <div class="dash-metric__head">
                <span class="dash-metric__icon"><i class="fa <?= $esc((string) $m['icon']) ?>"></i></span>
                <span class="dash-metric__label"><?= $esc((string) $m['label']) ?></span>
                <span class="dash-metric__today-tag">今日</span>
            </div>

            <div class="dash-metric__main">
                <div class="dash-metric__value-row">
                    <span class="dash-metric__today-value"><?= $esc((string) $m['unit']) . $fmt((float) $m['today'], (int) $m['decimals']) ?></span>
                    <span class="dash-ratio dash-ratio--<?= $ratio['state'] ?>" title="环比昨日">
                        <i class="fa fa-arrow-<?= $ratio['state'] === 'down' ? 'down' : 'up' ?>"></i>
                        <?= $ratio['pct'] ?>%
                    </span>
                </div>
                <div class="dash-metric__yesterday">
                    昨日 <?= $esc((string) $m['unit']) . $fmt((float) $m['yesterday'], (int) $m['decimals']) ?>
                </div>
            </div>

            <div class="dash-metric__month">
                <span class="dash-metric__month-label">本月</span>
                <span class="dash-metric__month-value"><?= $esc((string) $m['unit']) . $fmt((float) $m['month'], (int) $m['decimals']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- 附加操作卡：授权码 / 下载安装包（均为官方来源；任务服务在系统版本后） -->
        <div class="dash-metric dash-metric--action" style="--m-color: #10b981; --m-soft: #ecfdf5;">
            <div class="dash-metric__head">
                <span class="dash-metric__icon"><i class="fa fa-key"></i></span>
                <span class="dash-metric__label">授权码</span>
                <span class="dash-metric__official-tag">官方</span>
            </div>
            <div class="dash-metric__main">
                <div class="dash-metric__value-row">
                    <span class="dash-metric__today-value">正版授权码</span>
                </div>
                <div class="dash-metric__yesterday">点击下方按钮获取正版授权码</div>
            </div>
            <div class="dash-metric__month">
                <span class="dash-metric__month-label">获取授权码</span>
                <button type="button" class="dash-version-btn" id="dashGetLicenseBtn">
                    <i class="fa fa-download"></i> 获取
                </button>
            </div>
        </div>

        <div class="dash-metric dash-metric--action" style="--m-color: #f59e0b; --m-soft: #fffbeb;">
            <div class="dash-metric__head">
                <span class="dash-metric__icon"><i class="fa fa-cloud-download"></i></span>
                <span class="dash-metric__label">下载安装包</span>
                <span class="dash-metric__official-tag">官方</span>
            </div>
            <div class="dash-metric__main">
                <div class="dash-metric__value-row">
                    <span class="dash-metric__today-value">EMSHOP</span>
                </div>
                <div class="dash-metric__yesterday">点击下方按钮获取下载链接</div>
            </div>
            <div class="dash-metric__month">
                <span class="dash-metric__month-label">获取下载信息</span>
                <button type="button" class="dash-version-btn" id="dashGetDownloadBtn">
                    <i class="fa fa-download"></i> 获取
                </button>
            </div>
        </div>

        <!-- 官方线路：当前选中的授权服务器线路，切换入口复用后台顶栏的线路菜单 -->
        <div class="dash-metric dash-metric--action" style="--m-color: #0ea5e9; --m-soft: #ecfeff;">
            <div class="dash-metric__head">
                <span class="dash-metric__icon"><i class="fa fa-signal"></i></span>
                <span class="dash-metric__label">官方线路</span>
                <span class="dash-metric__official-tag">官方</span>
            </div>
            <div class="dash-metric__main">
                <div class="dash-metric__value-row">
                    <span class="dash-metric__today-value dash-line-name"><?= $esc($__curLineName) ?></span>
                </div>
                <div class="dash-metric__yesterday">用于程序版本 / 模板 / 插件的安装与更新</div>
            </div>
            <div class="dash-metric__month">
                <!-- 左侧：当前线路的连接速度，由 admin_index_data 一起返回 -->
                <span class="dash-metric__month-label dash-line-latency" id="dashLineLatency">--</span>
                <button type="button" class="dash-version-btn" id="dashLineSwitchBtn">
                    <i class="fa fa-exchange"></i> 切换
                </button>
            </div>
        </div>

    </section>

    <!-- ================================ 官方公告 / 广告推广 ================================ -->
    <section class="dash-row dash-row--promote">
        <div class="dash-card dash-announce">
            <div class="dash-card__header">
                <div>
                    <div class="dash-card__title">
                        <i class="fa fa-bullhorn" style="color:#6366f1;margin-right:6px;"></i>
                        官方公告
                    </div>
                    <div class="dash-card__subtitle">来自 EMSHOP 官方的最新动态</div>
                </div>
                <a href="#" class="dash-card__more">查看全部 <i class="fa fa-arrow-right"></i></a>
            </div>
            <!-- 内容由 JS 异步注入；初始展示骨架占位 -->
            <div class="dash-announce__list" id="dashNoticeList">
                <div class="dash-skeleton__item"><div class="dash-skeleton__tag"></div><div class="dash-skeleton__line"></div></div>
                <div class="dash-skeleton__item"><div class="dash-skeleton__tag"></div><div class="dash-skeleton__line"></div></div>
                <div class="dash-skeleton__item"><div class="dash-skeleton__tag"></div><div class="dash-skeleton__line"></div></div>
            </div>
        </div>

        <div class="dash-card dash-announce">
            <div class="dash-card__header">
                <div>
                    <div class="dash-card__title">
                        <i class="fa fa-gift" style="color:#ec4899;margin-right:6px;"></i>
                        推荐 / 活动
                    </div>
                    <div class="dash-card__subtitle">近期福利 · 精选合作</div>
                </div>
            </div>
            <div class="dash-announce__list" id="dashAdList">
                <div class="dash-skeleton__item"><div class="dash-skeleton__tag"></div><div class="dash-skeleton__line"></div></div>
                <div class="dash-skeleton__item"><div class="dash-skeleton__tag"></div><div class="dash-skeleton__line"></div></div>
                <div class="dash-skeleton__item"><div class="dash-skeleton__tag"></div><div class="dash-skeleton__line"></div></div>
            </div>
        </div>
    </section>

    <!-- ================================ 销售趋势 + 系统信息（同一行） ================================ -->
    <section class="dash-row dash-row--trend-sys">
        <!-- 销售趋势：左侧大卡片，带日期范围筛选 -->
        <div class="dash-card dash-card--trend">
            <div class="dash-card__header">
                <div>
                    <div class="dash-card__title">销售趋势</div>
                    <div class="dash-card__subtitle" id="dashTrendSubtitle">加载中...</div>
                </div>
                <div class="dash-card__legend">
                    <span class="dash-legend"><i style="background:#6366f1;"></i> 收入 (¥)</span>
                    <span class="dash-legend"><i style="background:#10b981;"></i> 订单数</span>
                </div>
            </div>
            <!-- 日期范围筛选（9 个预设，默认最近 7 天） -->
            <div class="dash-trend-filter" id="dashTrendFilter">
                <span class="dash-trend-filter__item" data-range="today">今日</span>
                <span class="dash-trend-filter__item" data-range="yesterday">昨日</span>
                <span class="dash-trend-filter__item" data-range="week">本周</span>
                <span class="dash-trend-filter__item is-active" data-range="7d">最近 7 天</span>
                <span class="dash-trend-filter__item" data-range="month">本月</span>
                <span class="dash-trend-filter__item" data-range="30d">最近 30 天</span>
                <span class="dash-trend-filter__item" data-range="6m">最近半年</span>
                <span class="dash-trend-filter__item" data-range="year">本年</span>
                <span class="dash-trend-filter__item" data-range="12m">最近 1 年</span>
            </div>
            <div id="dashChartTrend" class="dash-chart dash-chart--lg"></div>
        </div>

        <!-- 系统信息：右侧窄卡片，和趋势平齐 -->
        <div class="dash-card">
            <div class="dash-card__header">
                <div class="dash-card__title">系统信息</div>
            </div>
            <div class="dash-sys">
                <div class="dash-sys__row"><span class="dash-sys__label">PHP 版本</span><span class="dash-sys__value"><?= $esc((string) $sysInfo['php']) ?></span></div>
                <div class="dash-sys__row"><span class="dash-sys__label">数据库</span><span class="dash-sys__value"><?= $esc((string) $sysInfo['db']) ?></span></div>
                <div class="dash-sys__row"><span class="dash-sys__label">Web 服务器</span><span class="dash-sys__value"><?= $esc((string) $sysInfo['server']) ?></span></div>
                <div class="dash-sys__row"><span class="dash-sys__label">系统版本</span><span class="dash-sys__value">EMSHOP <?= $esc((string) $sysInfo['version']) ?></span></div>
                <div class="dash-sys__row"><span class="dash-sys__label">时区</span><span class="dash-sys__value"><?= $esc((string) $sysInfo['timezone']) ?></span></div>
                <div class="dash-sys__row"><span class="dash-sys__label">模板主题</span><span class="dash-sys__value"><?= (int) $sysInfo['template_count'] ?> 个</span></div>
                <div class="dash-sys__row"><span class="dash-sys__label">主站插件</span><span class="dash-sys__value"><?= (int) $sysInfo['main_plugin_count'] ?> 个</span></div>
                <div class="dash-sys__row"><span class="dash-sys__label">分站插件</span><span class="dash-sys__value"><?= (int) $sysInfo['merchant_plugin_count'] ?> 个</span></div>
            </div>
        </div>
    </section>
</div>

<style>
/* ============================================================
 * Dashboard 重设计 —— 现代 SaaS 风
 * 所有 class 前缀 .dash 避免与旧 .admin-* 样式冲突
 * ============================================================ */
.admin-page { background: unset; }

/* ---------- 未授权引导 Hero（仅未授权时渲染） ---------- */
.dash-hero {
    position: relative;
    display: flex; align-items: center; justify-content: space-between; gap: 24px;
    padding: 28px 32px;
    margin-bottom: 22px;
    background:
        radial-gradient(600px 240px at 85% -20%, rgba(99,102,241,0.22), transparent 60%),
        radial-gradient(400px 240px at 0% 120%, rgba(236,72,153,0.18), transparent 60%),
        linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
    border-radius: 18px;
    color: #fff;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(15, 23, 42, 0.2);
    flex-wrap: wrap;
}
/* 警告态：主渐变偏橙红，暗示待处理 */
.dash-hero--warn {
    background:
        radial-gradient(600px 240px at 85% -20%, rgba(251,191,36,0.25), transparent 60%),
        radial-gradient(400px 240px at 0% 120%, rgba(239,68,68,0.2), transparent 60%),
        linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
}
.dash-hero__main { flex: 1; min-width: 260px; }
.dash-hero__greet {
    display: inline-flex; align-items: center; gap: 8px;
    font-size: 13px; opacity: 0.9;
    padding: 5px 12px; border-radius: 20px;
    background: rgba(255,255,255,0.1);
    margin-bottom: 12px;
    backdrop-filter: blur(4px);
}
.dash-hero__greet i { color: #fbbf24; }
.dash-hero__greet--warn {
    background: rgba(251, 191, 36, 0.15);
    border: 1px solid rgba(251, 191, 36, 0.35);
    color: #fde68a;
}
.dash-hero__greet--warn i { color: #fbbf24; }
.dash-hero__title {
    font-size: 24px; font-weight: 700; letter-spacing: 0.3px;
    margin: 0 0 10px;
    line-height: 1.25;
}
.dash-hero__meta {
    font-size: 13px; color: rgba(255,255,255,0.78);
    margin: 0; line-height: 1.7;
}

/* 按钮区：默认纵向排列；窄屏（手机）改为横向均分 */
.dash-hero__actions {
    display: flex;
    flex-direction: column;
    gap: 18px;
    min-width: 180px;
}
.dash-hero-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 11px 20px; border-radius: 10px;
    background: rgba(255,255,255,0.95); color: #1e293b;
    text-decoration: none; font-size: 13px; font-weight: 600;
    transition: all 0.18s ease;
    white-space: nowrap;
    border: none; cursor: pointer;
}
.dash-hero-btn:hover { background: #fff; color: #0f172a; transform: translateY(-1px); }
.dash-hero-btn--ghost {
    background: rgba(255,255,255,0.12); color: #fff;
    border: 1px solid rgba(255,255,255,0.25);
    backdrop-filter: blur(8px);
}
.dash-hero-btn--ghost:hover { background: rgba(255,255,255,0.22); color: #fff; border-color: rgba(255,255,255,0.4); }

/* ---------- 核心指标 6 卡（今日 / 昨日 / 环比 / 本月） ---------- */
.dash-metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 10px;
    margin-bottom: 22px;
}
.dash-metric {
    position: relative;
    display: flex; flex-direction: column;
    padding: 18px 20px 16px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    transition: all 0.2s ease;
    overflow: hidden;
    /* 默认阴影：白底场景下浮起感；紫色调统一全卡 */
    box-shadow:
        0 1px 2px rgba(15, 23, 42, 0.04),
        0 6px 16px rgba(99, 102, 241, 0.08);
}

.dash-metric:hover {
    transform: translateY(-3px);
    box-shadow:
        0 4px 10px rgba(15, 23, 42, 0.05),
        0 18px 36px rgba(99, 102, 241, 0.18);
}

/* 头部：图标 + 标签 + 右上角"今日" tag */
.dash-metric__head {
    position: relative; z-index: 1;
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 14px;
}
.dash-metric__icon {
    width: 28px; height: 28px; border-radius: 5px;
    background: var(--m-soft);
    color: var(--m-color);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.dash-metric__label {
    font-size: 13px; font-weight: 500; color: #374151;
    flex: 1; min-width: 0;
    letter-spacing: 0.2px;
}
.dash-metric__today-tag {
    font-size: 11px; font-weight: 500;
    padding: 3px 10px; border-radius: 999px;
    background: var(--m-soft);
    color: var(--m-color);
    letter-spacing: 0.2px;
    flex-shrink: 0;
}

/* 中部：大号数字居中 + 环比右上角定位（不占居中计算） + 昨日 */
.dash-metric__main {
    position: relative; z-index: 1;
    text-align: center;
    margin-bottom: 10px;
    margin-top: 10px;
}
.dash-metric__value-row {
    display: inline-block;
    position: relative;
    margin-bottom: 6px;
}
.dash-metric__today-value {
    font-size: 26px;
    color: #0f172a; line-height: 1.1;
    letter-spacing: 0.3px;
    display: inline-block;
}
.dash-metric__yesterday {
    font-size: 12px; color: #9ca3af;
    letter-spacing: 0.2px;
}

/* 运行模式：CLI 是「有常驻进程在消费队列」，绿色 + 呼吸灯（text-shadow 模拟光晕）；
   FPM 是「随请求同步执行」，琥珀常亮 —— 它是一种正常模式，不是故障，所以不闪。 */
@keyframes dashServerBreath {
    0%, 100% {
        opacity: 1;
        text-shadow: 0 0 0 transparent;
    }
    50% {
        opacity: 0.9;
        text-shadow: 0 0 12px rgba(16, 185, 129, 0.55), 0 0 26px rgba(16, 185, 129, 0.22);
    }
}
.dash-runmode--cli {
    color: #10b981 !important;
    animation: dashServerBreath 2.4s ease-in-out infinite;
}
.dash-runmode--fpm {
    color: #f59e0b !important;
}

/* 官方线路卡：线路名比较长，缩小字号避免撑破卡片 */
.dash-line-name {
    font-size: 18px;
}
/* 官方线路卡：连接速度分档配色（与顶栏线路菜单一致） */
.dash-line-latency { font-variant-numeric: tabular-nums; }
.dash-line-latency.is-ok   { color: #10b981; }
.dash-line-latency.is-warn { color: #f59e0b; }
.dash-line-latency.is-bad  { color: #ef4444; }

/* 环比 chip：绝对定位到大号数字右上角，不占居中计算空间 */
.dash-ratio {
    position: absolute;
    left: 100%;
    margin-left: 8px;
    display: inline-flex; align-items: center; gap: 3px;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 11px; font-weight: 600;
    letter-spacing: 0.2px;
    white-space: nowrap;
}
.dash-ratio i { font-size: 9px; }
.dash-ratio--up {
    background: #ecfdf5;
    color: #059669;
    box-shadow: inset 0 0 0 1px rgba(16, 185, 129, 0.18);
}
.dash-ratio--down,
.dash-ratio--zero {
    background: #fff1f2;
    color: #e11d48;
    box-shadow: inset 0 0 0 1px rgba(225, 29, 72, 0.18);
}

/* 底部：本月独占一行，左标签 + 右数值 */
.dash-metric__month {
    position: relative; z-index: 1;
    display: flex; justify-content: space-between; align-items: center;
    padding-top: 12px;
    border-top: 1px dashed #e5e7eb;
    font-size: 13px;
}
.dash-metric__month-label {
    color: #6b7280;
    letter-spacing: 0.2px;
}
.dash-metric__month-value {
    font-weight: 600;
    color: #1f2937;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

/* ---------- 系统版本卡：更新日志链接 ---------- */
.dash-changelog-link {
    color: #6366f1;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.15s ease;
}
.dash-changelog-link:hover { color: #4f46e5; }
.dash-changelog-link i { font-size: 10px; margin-left: 2px; opacity: 0.75; }

/* ---------- 系统版本卡：授权等级 tag + 检查更新按钮 ---------- */
.dash-metric__level-tag {
    font-size: 11px; font-weight: 600;
    padding: 3px 10px; border-radius: 999px;
    letter-spacing: 0.3px; flex-shrink: 0;
}
.dash-level--none    { background: #fef2f2; color: #ef4444; box-shadow: inset 0 0 0 1px rgba(239,68,68,.2); }
.dash-level--vip     { background: #eff6ff; color: #2563eb; box-shadow: inset 0 0 0 1px rgba(37,99,235,.2); }
.dash-level--svip    { background: #f5f3ff; color: #7c3aed; box-shadow: inset 0 0 0 1px rgba(124,58,237,.2); }
.dash-level--supreme { background: linear-gradient(135deg, #fbbf24, #d97706); color: #fff; box-shadow: 0 2px 6px rgba(217,119,6,.25); }

.dash-version-btn {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 12px;
    font-size: 12px; font-weight: 500;
    color: #fff; background: #6366f1;
    border: 0; border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s ease, transform 0.15s ease;
}
.dash-version-btn:hover { background: #4f46e5; transform: translateY(-1px); }
.dash-version-btn i { font-size: 11px; }
.dash-version-btn--alert {
    background: linear-gradient(135deg, #f43f5e, #e11d48);
    box-shadow: 0 2px 8px rgba(225, 29, 72, 0.3);
}
.dash-version-btn--alert:hover { background: linear-gradient(135deg, #e11d48, #be123c); }

/* ---------- 附加操作卡：授权码 / 下载安装包 ---------- */
/* 右上角"官方"标签 */
.dash-metric__official-tag {
    font-size: 11px; font-weight: 600;
    padding: 3px 10px; border-radius: 999px;
    background: #eef2ff; color: #4f46e5;
    letter-spacing: 0.3px; flex-shrink: 0;
    box-shadow: inset 0 0 0 1px rgba(79, 70, 229, 0.15);
}
/* 下载源选择弹层（多源时弹出） */
.dash-dl-wrap { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 8px; }
.dash-dl-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 14px;
    border: 1px solid #e5e7eb; border-radius: 10px;
    background: #fff; text-decoration: none; color: #111827;
    transition: all 0.15s ease;
}
.dash-dl-item:hover { background: #f9fafb; border-color: #f59e0b; color: #111827; transform: translateY(-1px); }
.dash-dl-ico {
    width: 34px; height: 34px; flex-shrink: 0;
    border-radius: 8px;
    background: #fffbeb; color: #f59e0b;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 14px;
}
.dash-dl-body { flex: 1; min-width: 0; }
.dash-dl-name { font-size: 13px; font-weight: 600; }
.dash-dl-url {
    font-size: 11px; color: #9ca3af;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    margin-top: 2px;
}
.dash-dl-arrow { color: #d1d5db; font-size: 12px; flex-shrink: 0; }
.dash-dl-item:hover .dash-dl-arrow { color: #f59e0b; }
/* 授权码卡复用同一套列表结构，配色跟随卡片主色（绿） */
.dash-dl-item--license:hover { border-color: #10b981; }
.dash-dl-item--license .dash-dl-ico { background: #ecfdf5; color: #10b981; }
.dash-dl-item--license:hover .dash-dl-arrow { color: #10b981; }
/* 兜底提示：身份标识没匹配到代理商时，页面顶部提示当前是官方通用配置 */
.dash-dl-notice {
    display: flex; align-items: center; gap: 6px;
    padding: 8px 12px;
    font-size: 12px; color: #92400e;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 8px;
}

/* 公告 / 广告空态 */
.dash-announce__empty {
    padding: 36px 12px;
    text-align: center;
    color: #9ca3af;
    font-size: 13px;
}
.dash-announce__empty i { font-size: 24px; display: block; margin-bottom: 6px; color: #d1d5db; }
/* 加载失败态：整块可点，点击重拉 admin_index_data */
.dash-announce__empty--retry { cursor: pointer; transition: color 0.15s ease; }
.dash-announce__empty--retry:hover { color: #6366f1; }
.dash-announce__empty--retry i { transition: color 0.15s ease; }
.dash-announce__empty--retry:hover i { color: #6366f1; }

/* 公告第一条：代理商联系方式 */
.dash-announce__contact { cursor: default; }
.dash-contact-row {
    display: flex; flex-wrap: wrap; gap: 8px;
    padding: 2px 0;
}
.dash-contact-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 10px;
    font-size: 12px; color: #374151;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 999px;
    cursor: pointer; user-select: none;
    transition: border-color .15s ease, box-shadow .15s ease;
    text-decoration: none;
}
.dash-contact-chip:hover { border-color: #6366f1; box-shadow: 0 2px 6px rgba(99,102,241,.15); color: #374151; }
.dash-contact-chip b { font-weight: 600; color: #0f172a; letter-spacing: .2px; }
.dash-contact-chip i { font-size: 13px; }
.dash-contact-chip--link { color: #229ed9; }
.dash-contact-chip--link:hover { color: #1a7db3; }

/* ---------- 公告 / 广告加载骨架 ---------- */
@keyframes dash-skeleton-pulse { 0%, 100% { opacity: 0.55; } 50% { opacity: 1; } }
.dash-skeleton__item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 8px;
    border-bottom: 1px dashed #f3f4f6;
    animation: dash-skeleton-pulse 1.2s ease-in-out infinite;
}
.dash-skeleton__item:last-child { border-bottom: none; }
.dash-skeleton__tag {
    width: 42px; height: 20px; border-radius: 999px;
    background: #eef2ff; flex-shrink: 0;
}
.dash-skeleton__line {
    flex: 1; height: 14px; border-radius: 4px;
    background: linear-gradient(90deg, #f3f4f6 0%, #e5e7eb 50%, #f3f4f6 100%);
}

/* ---------- 版本更新弹窗（分版本逐条列出） ---------- */
/* popup.css 只在 iframe 弹窗里引，这里把本弹窗依赖的几条样式内联并限定在 dash-update-modal 下 */
.layui-layer.dash-update-modal .layui-layer-content { padding: 0 !important; height: 100% !important; }
.layui-layer.dash-update-modal .popup-wrap {
    display: flex; flex-direction: column;
    height: 100%;
}
.layui-layer.dash-update-modal .popup-inner {
    flex: 1; min-height: 0;
    overflow-y: auto;
    padding: 16px 20px;
    -webkit-overflow-scrolling: touch;
}
.layui-layer.dash-update-modal .popup-footer {
    flex-shrink: 0;
    display: flex; align-items: center; justify-content: flex-end;
    gap: 10px;
    padding: 12px 16px;
    background: #fff;
    border-top: 1px solid #f0f0f0;
    box-sizing: border-box;
}
.layui-layer.dash-update-modal .popup-btn {
    height: 34px; line-height: 34px;
    padding: 0 15px;
    border: none;
    border-radius: 4px;
    font-size: 14px;
    cursor: pointer;
    transition: background 0.2s, opacity 0.2s;
}
.layui-layer.dash-update-modal .popup-btn .fa { margin-right: 5px; }
.layui-layer.dash-update-modal .popup-btn--primary { background: #1e9fff; color: #fff; }
.layui-layer.dash-update-modal .popup-btn--primary:hover { background: #1d87e2; }
.layui-layer.dash-update-modal .popup-btn--default {
    background: #fff; color: #333;
    border: 1px solid #e0e0e0;
}
.layui-layer.dash-update-modal .popup-btn--default:hover { background: #f5f5f5; }
.dash-update__item {
    padding: 14px 16px;
    margin-bottom: 12px;
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04), 0 4px 12px rgba(15, 23, 42, 0.06);
}
.dash-update__item:last-child { margin-bottom: 0; }
.dash-update__head {
    display: flex; justify-content: space-between; align-items: center;
    margin-bottom: 8px; gap: 8px;
}
.dash-update__ver {
    font-size: 14px; font-weight: 600; color: #0f172a;
}
.dash-update__ver i { color: #e11d48; margin-right: 4px; }
.dash-update__time { font-size: 12px; color: #9ca3af; }
.dash-update__time i { margin-right: 4px; }
.dash-update__body {
    font-size: 13px; color: #374151;
    line-height: 1.7;
    word-break: break-word;
}
/* 更新内容后端返回 HTML（含 p / ul / li 等），这里做一些常见标签的还原 */
.dash-update__body p { margin: 0 0 8px; }
.dash-update__body p:last-child { margin-bottom: 0; }
.dash-update__body ul,
.dash-update__body ol { margin: 0 0 8px; padding-left: 22px; }
.dash-update__body li { margin-bottom: 4px; }
.dash-update__body a { color: #6366f1; text-decoration: none; }
.dash-update__body a:hover { text-decoration: underline; }
.dash-update__body code {
    background: #eef2ff; color: #4338ca;
    padding: 1px 6px; border-radius: 3px;
    font-family: Consolas, Monaco, monospace; font-size: 12px;
}
.dash-update__body pre {
    background: #0f172a; color: #e2e8f0;
    padding: 10px 12px; border-radius: 6px;
    overflow-x: auto; font-size: 12px; margin: 6px 0;
}
.dash-update__body img { max-width: 100%; border-radius: 4px; }
.dash-update__body strong, .dash-update__body b { color: #0f172a; }

/* 手机端：版本更新 / 升级向导弹窗 */
@media (max-width: 640px) {
    .layui-layer.dash-update-modal .popup-inner,
    .layui-layer.dash-wizard-modal .popup-inner { padding: 12px 14px; }
    .layui-layer.dash-update-modal .popup-footer,
    .layui-layer.dash-wizard-modal .popup-footer {
        flex-wrap: wrap;
        justify-content: stretch;
        gap: 8px;
        padding: 10px 12px;
    }
    .layui-layer.dash-update-modal .popup-footer .popup-btn,
    .layui-layer.dash-wizard-modal .popup-footer .dash-wizard__btn,
    .layui-layer.dash-wizard-modal .popup-footer .em-btn {
        flex: 1 1 auto;
        min-width: 0;
        text-align: center;
        justify-content: center;
        white-space: nowrap;
    }
    .dash-update__item { padding: 12px 12px; }
    .dash-update__head { flex-wrap: wrap; align-items: flex-start; }
    .dash-wizard__hero { padding: 12px 14px; gap: 10px; }
    .dash-wizard__hero-ico { width: 34px; height: 34px; font-size: 16px; }
    .dash-wizard__hero-title { font-size: 14px; }
    .dash-wizard__log { padding: 9px 10px; }
    .dash-wizard__logwrap { min-height: 72px; }
}

/* ---------- 升级向导弹窗 ---------- */
.layui-layer.dash-wizard-modal .layui-layer-content { padding: 0 !important; height: 100% !important; }
.layui-layer.dash-wizard-modal .popup-wrap { display: flex; flex-direction: column; height: 100%; }
.layui-layer.dash-wizard-modal .popup-inner {
    flex: 1 1 auto; min-height: 0; overflow: hidden;
    display: flex; flex-direction: column;
    padding: 16px 18px 0;
    background: #f8fafc;
}
.layui-layer.dash-wizard-modal .popup-footer {
    flex-shrink: 0; display: flex; align-items: center; justify-content: flex-end; gap: 10px;
    padding: 12px 18px; background: #fff; border-top: 1px solid #eef0f4;
}

/* Hero：目标版本 + 进度条 */
.dash-wizard__hero {
    flex-shrink: 0;
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px;
    background: linear-gradient(135deg, #4f46e5 0%, #6366f1 55%, #7c6cf5 100%);
    color: #fff; border-radius: 12px;
    box-shadow: 0 6px 18px rgba(79, 70, 229, 0.22);
}
.dash-wizard__hero-ico {
    flex-shrink: 0; width: 42px; height: 42px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 11px; font-size: 19px;
    background: rgba(255, 255, 255, 0.18);
}
.dash-wizard__hero-main { flex: 1; min-width: 0; }
.dash-wizard__hero-title {
    display: flex; align-items: center; gap: 8px;
    font-size: 15px; font-weight: 500;
}
.dash-wizard__hero-title b { font-weight: 700; letter-spacing: 0.3px; }
.dash-wizard__hero-count {
    margin-left: auto; flex-shrink: 0;
    padding: 2px 9px; border-radius: 999px;
    font-size: 11px; font-weight: 600;
    background: rgba(255, 255, 255, 0.2);
}
.dash-wizard__hero-sub { font-size: 12px; opacity: 0.82; margin-top: 3px; }
.dash-wizard__progress {
    height: 4px; margin-top: 10px;
    background: rgba(255, 255, 255, 0.22);
    border-radius: 999px; overflow: hidden;
}
.dash-wizard__progress i {
    display: block; height: 100%; width: 0;
    background: #fff; border-radius: 999px;
    transition: width 0.35s ease;
}
.dash-wizard__progress.is-fail i { background: #fecaca; }

/* 主体：步骤时间轴（定高）+ 日志（占满剩余） */
.dash-wizard__body {
    flex: 1 1 auto; min-height: 0;
    display: flex; flex-direction: column;
    padding: 14px 0 16px;
    overflow-y: auto;   /* 窗口极矮时兜底，避免时间轴/日志被裁掉 */
}

/* 时间轴：去掉每步的卡片边框，用左侧竖线 + 圆点表达流程 */
.dash-wizard__steps { list-style: none; margin: 0; padding: 0; flex-shrink: 0; }
.dash-wizard__step {
    position: relative;
    display: flex; align-items: flex-start; gap: 10px;
    padding: 5px 6px 5px 2px;
    border-radius: 8px;
}
/* 连接线：最后一步不画 */
.dash-wizard__step:not(:last-child)::before {
    content: ''; position: absolute;
    left: 12px; top: 26px; bottom: -6px;
    width: 1.5px; background: #e2e6ee;
}
.dash-wizard__step-ico {
    position: relative; z-index: 1;
    flex-shrink: 0; width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 50%; font-size: 11px; font-weight: 600;
    background: #fff; color: #9aa3b2;
    border: 1.5px solid #e2e6ee;
    transition: all 0.2s ease;
}
.dash-wizard__step-body { flex: 1; min-width: 0; padding-top: 1px; }
.dash-wizard__step-title { font-size: 13px; color: #6b7280; line-height: 1.5; }
.dash-wizard__step-msg {
    font-size: 12px; color: #9aa3b2; margin-top: 1px;
    line-height: 1.5; word-break: break-all;
}
/* 进行中 */
.dash-wizard__step.is-running { background: #eef2ff; }
.dash-wizard__step.is-running .dash-wizard__step-ico {
    background: #4f46e5; border-color: #4f46e5; color: #fff;
}
.dash-wizard__step.is-running .dash-wizard__step-title { color: #4338ca; font-weight: 600; }
.dash-wizard__step.is-running .dash-wizard__step-msg { color: #6366f1; }
/* 已完成 */
.dash-wizard__step.is-done .dash-wizard__step-ico {
    background: #10b981; border-color: #10b981; color: #fff;
}
.dash-wizard__step.is-done .dash-wizard__step-title { color: #374151; }
.dash-wizard__step.is-done .dash-wizard__step-msg { color: #10b981; }
/* 失败 */
.dash-wizard__step.is-fail { background: #fef2f2; }
.dash-wizard__step.is-fail .dash-wizard__step-ico {
    background: #ef4444; border-color: #ef4444; color: #fff;
}
.dash-wizard__step.is-fail .dash-wizard__step-title { color: #991b1b; font-weight: 600; }
.dash-wizard__step.is-fail .dash-wizard__step-msg { color: #ef4444; }

/* 日志：不再写死 max-height，改为撑满剩余空间，避免步骤多时溢出弹窗 */
.dash-wizard__logwrap {
    flex: 1 1 auto; min-height: 96px;
    display: flex; flex-direction: column;
    margin-top: 12px;
}
.dash-wizard__loghead {
    flex-shrink: 0;
    display: flex; align-items: center; gap: 5px;
    margin-bottom: 5px;
    font-size: 11px; color: #9aa3b2; letter-spacing: 0.5px;
}
.dash-wizard__log {
    flex: 1 1 auto; min-height: 0; overflow-y: auto;
    background: #0f172a; color: #cbd5e1;
    font-family: Consolas, "Cascadia Code", Monaco, monospace;
    font-size: 12px; line-height: 1.7;
    border-radius: 10px;
    padding: 10px 12px;
    white-space: pre-wrap; word-break: break-all;
}
.dash-wizard__log-line { padding: 1px 0; }
.dash-wizard__log-line.is-err { color: #fca5a5; }
.dash-wizard__log-line.is-ok { color: #86efac; }
.dash-wizard__log-line.is-info { color: #93c5fd; }

/* 底部按钮：统一一套（原来「开始升级」混用了 em-btn，和另两个不一致） */
.dash-wizard__btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 5px;
    height: 34px; padding: 0 16px;
    border: 1px solid #e5e7eb; border-radius: 8px;
    background: #fff; color: #374151;
    font-size: 13px; font-weight: 500; cursor: pointer;
    transition: all 0.15s ease;
}
.dash-wizard__btn:hover:not(:disabled) { background: #f9fafb; border-color: #d8dde6; }
.dash-wizard__btn--primary {
    background: #4f46e5; color: #fff; border-color: #4f46e5;
    box-shadow: 0 2px 8px rgba(79, 70, 229, 0.25);
}
.dash-wizard__btn--primary:hover:not(:disabled) { background: #4338ca; border-color: #4338ca; }
.dash-wizard__btn--danger { background: #fff; color: #dc2626; border-color: #fecaca; }
.dash-wizard__btn--danger:hover:not(:disabled) { background: #fef2f2; border-color: #fca5a5; }
/* 回滚是破坏性操作，放最左边和其他按钮拉开距离 */
.dash-wizard__btn--left { margin-right: auto; }
.dash-wizard__btn:disabled { opacity: 0.5; cursor: not-allowed; box-shadow: none; }

/* ---------- 卡片通用 ---------- */
.dash-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    padding: 20px 22px;
    transition: box-shadow 0.2s ease;
}
.dash-card:hover { box-shadow: 0 4px 16px rgba(15, 23, 42, 0.04); }
.dash-card__header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px; gap: 10px;
}
.dash-card__title {
    font-size: 15px; font-weight: 600; color: #111827;
    letter-spacing: 0.2px;
}
.dash-card__subtitle {
    font-size: 11.5px; color: #9ca3af;
    margin-top: 3px;
}
.dash-card__more {
    font-size: 12px; color: #6366f1; text-decoration: none;
    display: inline-flex; align-items: center; gap: 4px;
    transition: gap 0.15s ease;
}
.dash-card__more:hover { gap: 8px; }
.dash-card__more i { font-size: 10px; }
.dash-card__legend {
    display: flex; gap: 16px;
}
.dash-legend {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px; color: #6b7280;
}
.dash-legend i {
    width: 8px; height: 8px; border-radius: 50%; display: inline-block;
}

/* ---------- 行布局 ---------- */
.dash-row { margin-bottom: 22px; }
/* 销售趋势 + 系统信息：左宽右窄（约 2:1） */
.dash-row--trend-sys {
    display: grid; gap: 14px;
    grid-template-columns: minmax(0, 2fr) minmax(240px, 1fr);
}
.dash-row--promote {
    display: grid; gap: 14px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

/* 销售趋势顶部日期筛选条 */
.dash-trend-filter {
    display: flex; flex-wrap: wrap; gap: 4px;
    padding: 8px 0 14px;
    border-bottom: 1px solid #f3f4f6;
    margin-bottom: 12px;
}
.dash-trend-filter__item {
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 12.5px; color: #6b7280;
    cursor: pointer; user-select: none;
    transition: background 0.15s ease, color 0.15s ease;
}
.dash-trend-filter__item:hover { background: #f5f7fa; color: #374151; }
.dash-trend-filter__item.is-active {
    background: #eef2ff; color: #4e6ef2; font-weight: 500;
}

/* ---------- 官方公告 ---------- */
.dash-announce__list { display: flex; flex-direction: column; }
.dash-announce__item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 8px;
    border-bottom: 1px dashed #f3f4f6;
    text-decoration: none;
    transition: background 0.15s ease;
    border-radius: 6px;
}
.dash-announce__item:last-child { border-bottom: none; }
.dash-announce__item:hover { background: #fafbfc; }
.dash-announce__tag {
    flex-shrink: 0;
    padding: 3px 10px; border-radius: 999px;
    font-size: 11px; font-weight: 600;
    letter-spacing: 0.3px;
}
.dash-announce__body { flex: 1; min-width: 0; }
.dash-announce__title {
    font-size: 13.5px; font-weight: 500; color: #111827;
    white-space: normal; word-break: break-word;
    line-height: 1.5;
}
.dash-announce__date {
    font-size: 11px; color: #9ca3af;
    margin-top: 3px;
}
.dash-announce__date i { margin-right: 4px; }
.dash-announce__arrow {
    color: #cbd5e1; font-size: 13px;
    flex-shrink: 0;
}

/* ---------- 公告 / 广告位的富文本正文（content 来自官方接口，按 HTML 注入） ---------- */
/* 有正文时整条改顶部对齐，标签与箭头都贴第一行，否则多行正文会把它们挤到垂直居中 */
.dash-announce__item--rich { align-items: flex-start; }
.dash-announce__desc {
    margin-top: 4px;
    font-size: 12.5px; line-height: 1.6;
    white-space: normal; word-break: break-word;
}
.dash-announce__desc p { margin: 0 0 6px; }
.dash-announce__desc p:last-child { margin-bottom: 0; }
.dash-announce__desc img { max-width: 100%; height: auto; border-radius: 6px; }
.dash-announce__desc ul,
.dash-announce__desc ol { margin: 6px 0; padding-left: 18px; }
.dash-announce__desc a { color: #6366f1; }
.dash-announce__desc strong { color: #374151; }

/* ---------- Chart 容器 ---------- */
.dash-chart { width: 100%; }
.dash-chart--lg { height: 300px; }
.dash-chart--md { height: 280px; }

/* ---------- 最近订单 / 用户 list ---------- */
.dash-list { display: flex; flex-direction: column; }
.dash-list__row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid #f3f4f6;
}
.dash-list__row:last-child { border-bottom: none; }
.dash-list__main { flex: 1; min-width: 0; }
.dash-list__title {
    font-size: 13px; font-weight: 500; color: #111827;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.dash-list__sub {
    font-size: 11.5px; color: #9ca3af;
    margin-top: 3px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.dash-list__right { text-align: right; }
.dash-list__amount {
    font-size: 14px; font-weight: 600; color: #111827;
    margin-bottom: 3px;
}

/* 状态标签 */
.dash-tag {
    display: inline-block;
    padding: 2px 8px; border-radius: 10px;
    font-size: 11px; font-weight: 500;
    letter-spacing: 0.3px;
}
.dash-tag--pending    { background: #fff7ed; color: #c2410c; }
.dash-tag--paid       { background: #eff6ff; color: #1d4ed8; }
.dash-tag--delivering { background: #f0f9ff; color: #0369a1; }
.dash-tag--delivered  { background: #f0fdf4; color: #15803d; }
.dash-tag--completed  { background: #dcfce7; color: #15803d; }
.dash-tag--refunding  { background: #fef2f2; color: #b91c1c; }
.dash-tag--refunded   { background: #f3f4f6; color: #6b7280; }
.dash-tag--cancelled  { background: #f3f4f6; color: #9ca3af; }
.dash-tag--expired    { background: #f3f4f6; color: #9ca3af; }
.dash-tag--failed     { background: #fef2f2; color: #b91c1c; }

/* ---------- 快捷入口 ---------- */
.dash-quick {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
}
.dash-quick__item {
    display: flex; align-items: center; gap: 12px;
    padding: 12px 14px;
    background: #fafbfc;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    text-decoration: none;
    transition: all 0.18s ease;
}
.dash-quick__item:hover {
    background: #fff;
    border-color: #c7d2fe;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(99,102,241,0.08);
}
.dash-quick__icon {
    width: 36px; height: 36px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.dash-quick__title { font-size: 13px; font-weight: 600; color: #111827; }
.dash-quick__desc { font-size: 11px; color: #9ca3af; margin-top: 2px; }

/* ---------- 最近用户 ---------- */
.dash-users { display: flex; flex-direction: column; }
.dash-user {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #f3f4f6;
}
.dash-user:last-child { border-bottom: none; }
.dash-user__avatar {
    width: 36px; height: 36px; border-radius: 50%;
    object-fit: cover; flex-shrink: 0;
}
.dash-user__avatar--default {
    display: flex; align-items: center; justify-content: center;
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    color: #6366f1; font-weight: 600; font-size: 14px;
}
.dash-user__body { flex: 1; min-width: 0; }
.dash-user__name {
    font-size: 13px; font-weight: 500; color: #111827;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.dash-user__email {
    font-size: 11.5px; color: #9ca3af;
    margin-top: 2px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.dash-user__time { font-size: 11px; color: #9ca3af; flex-shrink: 0; }

/* ---------- 系统信息 ---------- */
.dash-sys { display: flex; flex-direction: column; }
.dash-sys__row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 9px 0;
    font-size: 13px;
    border-bottom: 1px dashed #f3f4f6;
}
.dash-sys__row:last-child { border-bottom: none; }
.dash-sys__label { color: #6b7280; }
.dash-sys__value {
    color: #111827; font-weight: 500;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    font-size: 12px;
}

/* ---------- 空状态 ---------- */
.dash-empty {
    padding: 30px 20px;
    text-align: center;
    color: #9ca3af;
    font-size: 13px;
}
.dash-empty i { font-size: 24px; display: block; margin-bottom: 8px; color: #d1d5db; }

/* ---------- 响应式 ---------- */
@media (max-width: 900px) {
    .dash-hero { padding: 22px 20px; }
    .dash-hero__title { font-size: 20px; }
    .dash-row--trend-sys { grid-template-columns: 1fr; }
    .dash-row--promote { grid-template-columns: 1fr; }
}
@media (max-width: 600px) {
    .dash-metric { padding: 16px 16px 14px; }
    .dash-metric__today-value { font-size: 24px; }
    .dash-card { padding: 16px; }
    .dash-chart--lg { height: 260px; }

    /* 手机端：按钮改横向均分 */
    .dash-hero__actions {
        flex-direction: row;
        width: 100%;
        min-width: 0;
    }
    .dash-hero__actions > * { flex: 1; }
}
</style>

<script>
$(function () {
    // PJAX 防重复绑定：后台首页经常被 PJAX 切回，脚本每次执行都会给 document / window
    // 追加一次监听，点击会成倍触发。这里先 off 掉同命名空间的旧监听，再按 `.dashHome`
    // 统一绑定 —— 下方所有 $(document).on / $(window).on 都带这个命名空间。
    $(document).off('.dashHome');
    $(window).off('.dashHome');

    checkRewriteStatus();
    /**
     * 探测伪静态是否生效
     */ 
    function checkRewriteStatus() {
        $.ajax({
            url: '/test',
            method: 'GET',
            data: {},
            dataType: 'json',
            timeout: 15000
        }).done(function (resp) {
            console.log(resp);
        }).fail(function (xhr, status, error) {
            // 失败捕获
            if (xhr.status === 404) {
                console.log('未配置伪静态');
                layui.layer.open({
                    type: 2,
                    title: '未配置伪静态',
                    skin: 'admin-modal',
                    maxmin: false,
                    area: [window.innerWidth >= 640 ? '580px' : '94%', window.innerHeight >= 640 ? '580px' : '88%'],
                    shadeClose: true,
                    content: '/admin/home.php?popup=rewrite'
                });
            }
        });
    }
    

    // 官方公告第一条：客服QQ / QQ群 点击复制
    $(document).on('click.dashHome', '.dash-contact-chip[data-copy]', function () {
        var text = $(this).data('copy');
        if (!text) return;
        var $tmp = $('<textarea>').val(text).css({ position: 'fixed', top: '-1000px' }).appendTo('body');
        $tmp[0].select();
        try { document.execCommand('copy'); } catch (e) {}
        $tmp.remove();
        if (typeof layui !== 'undefined' && layui.layer) {
            layui.layer.msg('已复制：' + text);
        }
    });

    // 异步加载首页中心服务数据（代理商联系方式 / 公告 / 广告 / 版本更新）
    // 保存最新一次响应，供"检查更新"按钮读取
    var __dashIndexData = null;
    var __dashIndexLoading = false;
    var __dashIndexXhr = null;
    var __dashLicenseActivated = <?= $__licenseActivated ? 'true' : 'false' ?>;

    // 公告 / 推广的加载骨架（重试时恢复成和首屏一致的占位）
    var DASH_SKELETON = (function () {
        var one = '<div class="dash-skeleton__item"><div class="dash-skeleton__tag"></div><div class="dash-skeleton__line"></div></div>';
        return one + one + one;
    })();

    // admin_index_data 上游失败时 HTTP 仍是 200，但会带 error、业务字段全是空数组。
    // 这种状态必须按「加载失败」处理，否则卡片会误显示"暂无公告 / 暂无推广 / 暂无链接"。
    function dashDataFailed(data) {
        return !data || String(data.error || '') !== '';
    }
    function dashRetryHtml() {
        return '<div class="dash-announce__empty dash-announce__empty--retry" title="点击重新获取">' +
               '<i class="fa fa-refresh"></i>加载失败，点击重试</div>';
    }
    // 数据没拿到（首次还没返回，或上次上游失败）时先重拉一次，成功后再执行 onReady。
    // 等待期间只显示 loading，不弹提示；请求结束（无论成败）再关掉 loading。
    function dashEnsureIndexData(onReady) {
        if (!dashDataFailed(__dashIndexData)) {
            onReady();
            return;
        }
        var loadIdx = layui.layer.load(2);
        loadDashIndex().always(function () {
            layui.layer.close(loadIdx);
            if (dashDataFailed(__dashIndexData)) {
                layui.layer.msg('获取失败，请稍后再试');
                return;
            }
            onReady();
        });
    }

    // FPM 模式是正常可用的模式（同步发货），不再自动弹窗打扰；想了解区别点“查看详细”。
    $('#dashServerDetailBtn').on('click', function () {
        openServerDescription();
    });

    function openServerDescription() {
        layui.layer.open({
            type: 2,
            title: '运行模式说明',
            skin: 'admin-modal',
            maxmin: false,
            area: [window.innerWidth >= 640 ? '560px' : '94%', window.innerHeight >= 640 ? '520px' : '82%'],
            shadeClose: true,
            content: '/admin/home.php?popup=server_guide'
        });
    }

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }
    // 标签色由服务端下发（label_color）。它会被拼进 style 属性，所以只接受
    // 十六进制与 rgb/rgba 这两种「不含引号/分号/括号逃逸」的写法，其余一律回退默认色。
    function safeColor(v, fallback) {
        var s = String(v || '').trim();
        if (/^#[0-9a-fA-F]{3,8}$/.test(s)) return s;
        if (/^rgba?\([0-9.,%\s]+\)$/.test(s)) return s;
        return fallback;
    }
    // 正文里不能出现交互元素：整条是「外层 <a> 包一切」的结构，而服务端下发的富文本
    // 经常自带链接（如 <p><strong>标题</strong><a href="...">网址</a></p>）。
    // <a> 套 <a> 是非法 HTML，浏览器解析时会触发「收养算法」提前闭合外层 <a> ——
    // 表现为标签独占一行、正文掉到下面整行、箭头被挤成列表外的独立一行（列表是 flex 列）。
    // 所以这里把正文里的交互标签剥掉，只留文字与内联样式；整条仍统一跳 link_url。
    function stripInteractive(html) {
        return String(html || '').replace(/<\/?(?:a|button|form|input|select|textarea|label|iframe)\b[^>]*>/gi, '');
    }
    // 公告 / 广告位共用一条的渲染：label 作彩色标签、content 是富文本 HTML。
    // content 来自官方接口，按约定直接按 HTML 注入（不转义）—— 见 LicenseService::normalizeEntries 的说明。
    // 一行只有标签 + 正文两段：title / created_at / expires_on 都不渲染
    // （字段仍保留在数据里，要用随时加回来）。
    function renderFeedItem(item, defaultLabel, defaultColor) {
        var color = safeColor(item.label_color, defaultColor);
        var label = item.label || defaultLabel;
        var url = String(item.link_url || '');
        var external = url.indexOf('#') !== 0 && url !== '';
        var body = '';
        if (item.content) {
            body += '<div class="dash-announce__desc">' + stripInteractive(item.content) + '</div>';
        }
        var inner = '<span class="dash-announce__tag" style="background:' + color + '1a;color:' + color + ';">' +
                    escapeHtml(label) + '</span>' +
                    '<div class="dash-announce__body">' + body + '</div>';
        var cls = 'dash-announce__item' + (item.content ? ' dash-announce__item--rich' : '');
        if (url === '') {
            return '<div class="' + cls + '">' + inner + '</div>';
        }
        return '<a href="' + escapeHtml(url) + '"' + (external ? ' target="_blank"' : '') + ' class="' + cls + '">' +
               inner + '<i class="fa fa-angle-right dash-announce__arrow"></i></a>';
    }
    function renderNoticeList(data) {
        var $list = $('#dashNoticeList');
        if (dashDataFailed(data)) {
            $list.html(dashRetryHtml());
            return;
        }
        var html = '';
        var c = data.contact || {};
        var hasContact = !!(c.qq_service || c.wechat_service || c.qq_group || c.tg_service || c.tg_group_url || c.wechat_qr);

        if (hasContact) {
            html += '<div class="dash-announce__item dash-announce__contact">' +
                '<span class="dash-announce__tag" style="background:#eef2ff;color:#6366f1;">联系</span>' +
                '<div class="dash-announce__body"><div class="dash-contact-row">';
            if (c.qq_service) {
                html += '<span class="dash-contact-chip" data-copy="' + escapeHtml(c.qq_service) + '" title="点击复制">' +
                    '<i class="fa fa-qq" style="color:#1296db;"></i> 客服QQ <b>' + escapeHtml(c.qq_service) + '</b></span>';
            }
            if (c.wechat_service) {
                html += '<span class="dash-contact-chip" data-copy="' + escapeHtml(c.wechat_service) + '" title="点击复制">' +
                    '<i class="fa fa-weixin" style="color:#07c160;"></i> 微信 <b>' + escapeHtml(c.wechat_service) + '</b></span>';
            }
            if (c.qq_group) {
                html += '<span class="dash-contact-chip" data-copy="' + escapeHtml(c.qq_group) + '" title="点击复制">' +
                    '<i class="fa fa-users" style="color:#ec4899;"></i> 官方QQ群 <b>' + escapeHtml(c.qq_group) + '</b></span>';
            }
            if (c.tg_service) {
                html += '<span class="dash-contact-chip" data-copy="' + escapeHtml(c.tg_service) + '" title="点击复制">' +
                    '<i class="fa fa-paper-plane" style="color:#229ed9;"></i> TG客服 <b>' + escapeHtml(c.tg_service) + '</b></span>';
            }
            if (c.tg_group_url) {
                html += '<a href="' + escapeHtml(c.tg_group_url) + '" target="_blank" class="dash-contact-chip dash-contact-chip--link">' +
                    '<i class="fa fa-paper-plane" style="color:#229ed9;"></i> TG群</a>';
            }
            if (c.wechat_qr) {
                html += '<a href="' + escapeHtml(c.wechat_qr) + '" target="_blank" class="dash-contact-chip dash-contact-chip--link">' +
                    '<i class="fa fa-qrcode" style="color:#07c160;"></i> 微信二维码</a>';
            }
            html += '</div></div></div>';
        }

        // 键名跟服务端接口一致：announcements / ad_slots（别再写成老接口的 notice / ad）
        var notices = data.announcements || [];
        if (!hasContact && notices.length === 0) {
            html = '<div class="dash-announce__empty"><i class="fa fa-inbox"></i> 暂无公告</div>';
        } else {
            notices.forEach(function (n) {
                html += renderFeedItem(n, '公告', '#6366f1');
            });
        }
        $list.html(html);
    }
    function renderAdList(data) {
        var $list = $('#dashAdList');
        if (dashDataFailed(data)) {
            $list.html(dashRetryHtml());
            return;
        }
        var ads = data.ad_slots || [];
        if (!ads.length) {
            $list.html('<div class="dash-announce__empty"><i class="fa fa-inbox"></i> 暂无推广</div>');
            return;
        }
        var html = '';
        ads.forEach(function (a) {
            // 不显示有效期：接口会给 expires_on，但列表里只当排序/有效性由服务端保证，不外露
            html += renderFeedItem(a, '推广', '#6366f1');
        });
        $list.html(html);
    }
    // 官方线路卡：当前线路的连接速度（admin_index_data 顺带返回，null = 连不上）
    function renderLineLatency(ms) {
        var $el = $('#dashLineLatency');
        if (!$el.length) return;
        var v = parseInt(ms, 10);
        $el.removeClass('is-ok is-warn is-bad');
        if (ms === null || ms === undefined || isNaN(v) || v < 0) {
            $el.addClass('is-bad').text('连接失败');
            return;
        }
        // 分档配色与顶栏线路菜单一致：<150ms 绿 / 150-500ms 橙 / ≥500ms 红
        $el.addClass(v < 150 ? 'is-ok' : (v < 500 ? 'is-warn' : 'is-bad')).text(v + ' ms');
    }
    function renderVersion(data) {
        var $label = $('#dashVersionLabel');
        var $btn = $('#dashCheckUpdate');
        $btn.prop('disabled', false);
        var u = data.update || {};
        if (u.has_new) {
            // 只写「发现新版本」：版本号与是否强制都不在卡片上占位置
            // （目标版本在升级向导标题里，force 标记暂不外露；数据里都还在）
            $label.html('<i class="fa fa-bell" style="margin-right:4px;"></i>发现新版本')
                  .css({ color: '#e11d48', fontWeight: 600 });
            $btn.addClass('dash-version-btn--alert').html('<i class="fa fa-cloud-download"></i> 立即更新');
        } else {
            $label.text('当前已是最新版本').css({ color: '', fontWeight: '' });
            $btn.removeClass('dash-version-btn--alert').html('<i class="fa fa-refresh"></i> 检查更新');
        }
    }
    function renderVersionError() {
        $('#dashVersionLabel').html('<i class="fa fa-exclamation-circle" style="color:#9ca3af;margin-right:4px;"></i>检测失败');
        $('#dashCheckUpdate').prop('disabled', false);
    }
    function renderDashFail() {
        __dashIndexData = null;
        renderVersionError();
        $('#dashNoticeList').html(dashRetryHtml());
        $('#dashAdList').html(dashRetryHtml());
    }
    // 返回 jqXHR，调用方（授权码 / 下载卡片）可在失败后等它重试完再决定弹不弹
    function loadDashIndex() {
        if (__dashIndexLoading) return __dashIndexXhr;
        __dashIndexLoading = true;
        __dashIndexXhr = $.ajax({
            url: '/admin/home.php',
            method: 'GET',
            data: { _action: 'admin_index_data', _t: Date.now() },
            dataType: 'json',
            // 后端只请求当前线路（不自动换线），单次超时 8s；这里留足余量
            timeout: 40000
        }).done(function (resp) {
            if (!resp || resp.code !== 200 || !resp.data) {
                renderDashFail();
                return;
            }
            // 连接速度随这次响应一起回来，即使上游业务数据失败也可能已经测到
            renderLineLatency(resp.data.line_latency_ms);
            // 上游失败时 HTTP 仍是 200，但带 error、update 是空壳 —— 必须按失败处理，
            // 否则版本卡会误显示「当前已是最新版本」。
            if (dashDataFailed(resp.data)) {
                renderDashFail();
                return;
            }
            __dashIndexData = resp.data;
            renderNoticeList(resp.data);
            renderAdList(resp.data);
            renderVersion(resp.data);
        }).fail(function () {
            renderDashFail();
        }).always(function () {
            __dashIndexLoading = false;
        });
        return __dashIndexXhr;
    }
    loadDashIndex();

    // 公告 / 推广的"加载失败，点击重试"：整体重拉一次
    $(document).on('click.dashHome', '.dash-announce__empty--retry', function () {
        $('#dashNoticeList, #dashAdList').html(DASH_SKELETON);
        loadDashIndex();
    });

    // 系统版本卡片：有新版本 → 直接进升级流程（新接口不再提供逐版本更新日志）；
    // 否则触发一次重新检测
    $(document).on('click.dashHome', '#dashCheckUpdate:not(:disabled)', function () {
        if (typeof layui === 'undefined' || !layui.layer) return;
        var u = (__dashIndexData && __dashIndexData.update) || {};
        if (!u.has_new) {
            $('#dashVersionLabel').html('<i class="fa fa-spinner fa-spin" style="margin-right:4px;"></i>检测中…');
            $('#dashCheckUpdate').prop('disabled', true);
            loadDashIndex();
            return;
        }
        if (!__dashLicenseActivated) {
            layui.layer.msg('未激活版本不支持在线升级');
            return;
        }
        // package_url 非空 = 服务端给了增量包且当前版本满足 min_version，可走在线升级
        if (u.package_url) {
            startUpdateWizard(u);
            return;
        }
        var tip = u.min_version
            ? '当前版本低于最低可升级版本 v' + u.min_version + '，只能下载完整安装包手动升级。'
            : '该版本暂未提供在线升级包，请下载安装包手动升级。';
        layui.layer.confirm(tip, function (idx) {
            layui.layer.close(idx);
            $('#dashGetDownloadBtn').trigger('click');
        });
    });

    // ==================================================================
    // 在线升级向导：6 步顺序执行，每步独立 AJAX（不备份、不保留升级包）
    // ==================================================================
    function startUpdateWizard(target) {
        if (!__dashLicenseActivated) {
            layui.layer.msg('未激活版本不支持在线升级');
            return;
        }
        // target 是服务端 admin_index_data 的 update 对象，必要字段：
        //   version / package_url / min_version（package_url 已由服务端保证满足 min_version）
        var STEPS = [
            { id: 'preflight', name: '环境预检' },
            { id: 'download',  name: '下载升级包' },
            { id: 'extract',   name: '解压升级包' },
            { id: 'apply',     name: '应用新文件' },
            { id: 'migrate',   name: '数据库迁移' },
            { id: 'finalize',  name: '完成收尾' }
        ];

        var state = { zip_path: '', extract_path: '', manifest_file: '' };

        function formatBytes(bytes) {
            if (!bytes) return '-';
            var u = ['B', 'KB', 'MB', 'GB'], i = 0;
            while (bytes >= 1024 && i < u.length - 1) { bytes /= 1024; i++; }
            return bytes.toFixed(2) + ' ' + u[i];
        }

        // 组装步骤时间轴 + 初始按钮区
        var stepsHtml = STEPS.map(function (s, i) {
            return '<li class="dash-wizard__step is-pending" data-step="' + s.id + '">' +
                '<span class="dash-wizard__step-ico">' + (i + 1) + '</span>' +
                '<div class="dash-wizard__step-body">' +
                    '<div class="dash-wizard__step-title">' + escapeHtml(s.name) + '</div>' +
                    '<div class="dash-wizard__step-msg"></div>' +
                '</div>' +
            '</li>';
        }).join('');
        var html = '<div class="popup-wrap">' +
            '<div class="popup-inner">' +
                '<div class="dash-wizard__hero">' +
                    '<span class="dash-wizard__hero-ico"><i class="fa fa-cloud-download"></i></span>' +
                    '<div class="dash-wizard__hero-main">' +
                        '<div class="dash-wizard__hero-title">' +
                            '<span>升级到 <b>v' + escapeHtml(target.version || '') + '</b></span>' +
                            '<span class="dash-wizard__hero-count" id="dashWizardCount">0 / ' + STEPS.length + '</span>' +
                        '</div>' +
                        '<div class="dash-wizard__hero-sub" id="dashWizardHint">升级过程请不要关闭浏览器或离开本页</div>' +
                        '<div class="dash-wizard__progress" id="dashWizardProgress"><i id="dashWizardBar"></i></div>' +
                    '</div>' +
                '</div>' +
                '<div class="dash-wizard__body">' +
                    '<ul class="dash-wizard__steps">' + stepsHtml + '</ul>' +
                    '<div class="dash-wizard__logwrap">' +
                        '<div class="dash-wizard__loghead"><i class="fa fa-terminal"></i> 运行日志</div>' +
                        '<div class="dash-wizard__log" id="dashWizardLog"></div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="popup-footer">' +
                '<button type="button" class="dash-wizard__btn dash-wizard__btn--danger dash-wizard__btn--left" id="dashWizardRollback" style="display:none;"><i class="fa fa-undo"></i> 删除本次新增文件</button>' +
                '<button type="button" class="dash-wizard__btn" id="dashWizardClose">关闭</button>' +
                '<button type="button" class="dash-wizard__btn dash-wizard__btn--primary" id="dashWizardStart"><i class="fa fa-play"></i> 开始升级</button>' +
            '</div>' +
        '</div>';

        var idx = layui.layer.open({
            type: 1,
            title: '在线升级',
            skin: 'admin-modal dash-wizard-modal',
            area: [window.innerWidth >= 640 ? '620px' : '94%', window.innerHeight >= 640 ? '600px' : '90%'],
            shadeClose: false,
            closeBtn: 0,  // 运行中不允许点遮罩/右上角关，只能走底部按钮
            content: html
        });

        function log(msg, type) {
            var $log = $('#dashWizardLog');
            var t = new Date().toTimeString().slice(0, 8);
            $log.append('<div class="dash-wizard__log-line is-' + (type || 'info') + '">[' + t + '] ' + escapeHtml(msg) + '</div>');
            $log.scrollTop($log[0].scrollHeight);
        }
        // 进度 = 已完成步数 / 总步数
        function setProgress(failed) {
            var done = $('.dash-wizard__step.is-done').length;
            $('#dashWizardCount').text(done + ' / ' + STEPS.length);
            $('#dashWizardBar').css('width', Math.round(done / STEPS.length * 100) + '%');
            if (failed) $('#dashWizardProgress').addClass('is-fail');
        }
        function setStep(stepId, status, msg) {
            var $s = $('.dash-wizard__step[data-step="' + stepId + '"]');
            $s.removeClass('is-pending is-running is-done is-fail').addClass('is-' + status);
            if (msg !== undefined) $s.find('.dash-wizard__step-msg').text(msg);
            var ic = $s.find('.dash-wizard__step-ico');
            if      (status === 'done')    ic.html('<i class="fa fa-check"></i>');
            else if (status === 'fail')    ic.html('<i class="fa fa-times"></i>');
            else if (status === 'running') ic.html('<i class="fa fa-spinner fa-spin"></i>');
            setProgress(status === 'fail');
        }
        function callStep(action, data) {
            return $.ajax({
                url: '/admin/update.php', method: 'POST', dataType: 'json',
                timeout: 600000,  // 下载可能比较久
                data: $.extend({ _action: action, csrf_token: window.adminCsrfToken || '' }, data || {})
            });
        }

        // 每步的执行函数：返回 Promise（成功 → resolve；失败 → reject(Error)）
        var runners = {
            preflight: function () {
                setStep('preflight', 'running', '检查写权限 / 磁盘空间 / PHP 版本');
                return callStep('preflight', {
                    version: target.version || '',
                    min_from_version: target.min_version || '',
                    // 服务端不再下发包大小，传 0 让预检走 50MB 保底
                    package_size: 0
                }).then(function (res) {
                    if (res.code !== 200) throw new Error(res.msg || '预检失败');
                    var d = res.data || {};
                    if (d.csrf_token) window.adminCsrfToken = d.csrf_token;
                    if (!d.ok) throw new Error('预检不通过：' + (d.errors || []).join('；'));
                    (d.warnings || []).forEach(function (w) { log('警告：' + w, 'info'); });
                    setStep('preflight', 'done', 'PHP ' + d.php_version);
                    log('预检通过（PHP ' + d.php_version + '，可用磁盘 ' + formatBytes(d.disk_free) + '）', 'ok');
                });
            },
            download: function () {
                setStep('download', 'running', '下载中…');
                log('开始下载：' + target.package_url);
                // 服务端不下发 SHA256，完整性靠后端「https + 授权线路同域」白名单把关
                return callStep('download', {
                    package_url: target.package_url
                }).then(function (res) {
                    if (res.code !== 200) throw new Error(res.msg || '下载失败');
                    state.zip_path = res.data.path;
                    setStep('download', 'done', formatBytes(res.data.size));
                    log('下载完成 · 大小 ' + formatBytes(res.data.size), 'ok');
                });
            },
            extract: function () {
                setStep('extract', 'running', '解压中…');
                return callStep('extract', { zip_path: state.zip_path }).then(function (res) {
                    if (res.code !== 200) throw new Error(res.msg || '解压失败');
                    state.extract_path = res.data.extract_path;
                    setStep('extract', 'done', '共 ' + res.data.files + ' 个文件');
                    log('解压完成 · 共 ' + res.data.files + ' 个文件（升级包已删除）', 'ok');
                });
            },
            apply: function () {
                setStep('apply', 'running', '覆盖文件…');
                return callStep('apply', {
                    extract_path: state.extract_path
                }).then(function (res) {
                    if (res.code !== 200) throw new Error(res.msg || '应用失败');
                    state.manifest_file = res.data.manifest_file;
                    setStep('apply', 'done', '替换 ' + res.data.replaced + ' · 新增 ' + res.data.added + ' · 跳过 ' + res.data.skipped);
                    log('文件覆盖完成', 'ok');
                });
            },
            migrate: function () {
                setStep('migrate', 'running', '运行数据库迁移…');
                return callStep('migrate').then(function (res) {
                    if (res.code !== 200) {
                        throw new Error(res.msg || '数据库迁移失败');
                    }
                    if (res.data.applied && res.data.applied.length > 0) {
                        setStep('migrate', 'done', '执行 ' + res.data.applied.length + ' 个脚本');
                        res.data.applied.forEach(function (f) { log('  ✓ ' + f, 'ok'); });
                    } else {
                        setStep('migrate', 'done', '无新增迁移');
                    }
                });
            },
            finalize: function () {
                setStep('finalize', 'running', '清理临时文件…');
                return callStep('finalize').then(function (res) {
                    if (res.code !== 200) throw new Error(res.msg || '收尾失败');
                    if (res.data.csrf_token) window.adminCsrfToken = res.data.csrf_token;
                    setStep('finalize', 'done', '升级成功！');
                    log('🎉 升级完成！请刷新页面验证新版本', 'ok');
                });
            }
        };

        function runAll() {
            var chain = $.Deferred().resolve();
            STEPS.forEach(function (s) {
                chain = chain.then(function () { return runners[s.id](); });
            });
            return chain;
        }

        // 按钮事件
        $(document).off('click.dashWizard');
        $(document).on('click.dashWizard', '#dashWizardStart', function () {
            var $start = $(this);
            var $close = $('#dashWizardClose');
            $start.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 升级中…');
            $close.prop('disabled', true);
            runAll().then(function () {
                $('#dashWizardHint').text('升级完成，请刷新页面验证新版本');
                $start.hide();
                $close.prop('disabled', false).addClass('dash-wizard__btn--primary')
                      .html('<i class="fa fa-refresh"></i> 刷新页面');
                $close.off('click').on('click', function () { location.reload(); });
            }, function (err) {
                var msg = (err && (err.message || err.statusText)) || '升级失败';
                log(msg, 'err');
                $('#dashWizardHint').text('升级失败，可查看下方日志排查');
                $('.dash-wizard__step.is-running').each(function () {
                    setStep($(this).data('step'), 'fail', msg);
                });
                $start.hide();
                $close.prop('disabled', false);
                if (state.manifest_file) $('#dashWizardRollback').show();
            });
        });
        $(document).on('click.dashWizard', '#dashWizardRollback', function () {
            var $btn = $(this);
            layui.layer.confirm(
                '将删除本次升级新增的文件；已覆盖的旧文件无法自动还原，确定继续吗？',
                function (cidx) {
                    layui.layer.close(cidx);
                    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 处理中');
                    callStep('rollback').then(function (res) {
                        if (res.data && res.data.csrf_token) window.adminCsrfToken = res.data.csrf_token;
                        log('已删除本次新增文件（共 ' + (res.data.restored_files || 0) + ' 个）', 'ok');
                        $btn.hide();
                        $('#dashWizardClose').addClass('dash-wizard__btn--primary').html('<i class="fa fa-refresh"></i> 刷新页面');
                        $('#dashWizardClose').off('click').on('click', function () { location.reload(); });
                    }, function () {
                        log('回滚请求失败', 'err');
                        $btn.prop('disabled', false).html('<i class="fa fa-undo"></i> 再试一次');
                    });
                }
            );
        });
        $(document).on('click.dashWizard', '#dashWizardClose', function () {
            layui.layer.close(idx);
        });
    }

    // 未授权 Hero 上的"获取授权码"按钮 → 打开通用 agent_config 弹窗
    $('#dashGetLicense').on('click', function () {
        if (typeof layui === 'undefined' || !layui.layer) return;
        layui.layer.open({
            type: 2,
            title: '获取正版授权码',
            skin: 'admin-modal',
            maxmin: false,
            area: [window.innerWidth >= 640 ? '580px' : '94%', window.innerHeight >= 640 ? '580px' : '88%'],
            shadeClose: true,
            content: '/admin/license.php?_popup=agent'
        });
    });

    // ========== 附加操作卡（授权码 / 下载）==========
    // 授权码卡：正常情况直接用首页初始化时已拉到的 buy_links 渲染，
    // 不再打开 /admin/license.php?_popup=agent —— 那个 iframe 会再向上游请求一次 base-data；
    // 上次请求失败则点「获取」时重新请求接口，成功后再弹。
    function openBuyLinksPopup() {
        var links = __dashIndexData.buy_links || [];
        var content;
        if (!links.length) {
            content = '<div class="dash-announce__empty"><i class="fa fa-inbox"></i>暂无购买渠道</div>';
        } else {
            var notice = __dashIndexData.used_fallback
                ? '<div class="dash-dl-notice"><i class="fa fa-info-circle"></i> 当前身份标识未匹配到代理商，以下为官方通用配置</div>'
                : '';
            content = '<div class="dash-dl-wrap">' + notice + links.map(function (u) {
                return '<a href="' + escapeHtml(u.url) + '" target="_blank" class="dash-dl-item dash-dl-item--license">' +
                       '<span class="dash-dl-ico"><i class="fa fa-shopping-cart"></i></span>' +
                       '<div class="dash-dl-body">' +
                           '<div class="dash-dl-name">' + escapeHtml(u.name || u.url) + '</div>' +
                           '<div class="dash-dl-url">' + escapeHtml(u.url) + '</div>' +
                       '</div>' +
                       '<i class="fa fa-arrow-right dash-dl-arrow"></i></a>';
            }).join('') + '</div>';
        }
        layui.layer.open({
            type: 1,
            title: '获取正版授权码',
            skin: 'admin-modal',
            area: [window.innerWidth >= 640 ? '460px' : '94%', 'auto'],
            shadeClose: true,
            content: content
        });
    }
    $('#dashGetLicenseBtn').on('click', function () {
        if (typeof layui === 'undefined' || !layui.layer) return;
        dashEnsureIndexData(openBuyLinksPopup);
    });

    

    // 下载安装包：从 admin_index_data 返回的 download_links 取下载源，始终用弹层展示，由用户点具体条目后再打开
    function openDownloadPopup() {
        var urls = __dashIndexData.download_links || [];
        if (!urls.length) {
            layui.layer.msg('暂未提供下载链接，请联系客服获取');
            return;
        }
        var items = urls.map(function (u) {
            return '<a href="' + escapeHtml(u.url) + '" target="_blank" class="dash-dl-item">' +
                   '<span class="dash-dl-ico"><i class="fa fa-download"></i></span>' +
                   '<div class="dash-dl-body">' +
                       '<div class="dash-dl-name">' + escapeHtml(u.name) + '</div>' +
                       '<div class="dash-dl-url">' + escapeHtml(u.url) + '</div>' +
                   '</div>' +
                   '<i class="fa fa-arrow-right dash-dl-arrow"></i></a>';
        }).join('');
        layui.layer.open({
            type: 1,
            title: urls.length === 1 ? '下载安装包' : '选择下载源',
            skin: 'admin-modal',
            area: [window.innerWidth >= 640 ? '460px' : '94%', 'auto'],
            shadeClose: true,
            content: '<div class="dash-dl-wrap">' + items + '</div>'
        });
    }
    $('#dashGetDownloadBtn').on('click', function () {
        if (typeof layui === 'undefined' || !layui.layer) return;
        dashEnsureIndexData(openDownloadPopup);
    });

    // 官方线路卡：复用后台顶栏的线路切换菜单，不另做一套。
    // stopPropagation 是必须的 —— 顶栏那边有「点击外部关闭」的 document 监听，
    // 不拦住的话本次点击冒泡上去会把刚展开的菜单立刻关掉。
    $('#dashLineSwitchBtn').on('click', function (e) {
        e.stopPropagation();
        if (!$('#adminLineDropdown').hasClass('is-open')) {
            $('#adminLineSwitch .admin-line-switch__trigger').trigger('click');
        }
    });

    // 销售趋势折线图：初始化空图表，再用 AJAX 拉数据 + 按日期范围切换
    var elTrend = document.getElementById('dashChartTrend');
    if (elTrend) {
        var chartTrend = echarts.init(elTrend);
        // 基础 option：轴 / 样式 / 图例固定，只有 series.data 和 xAxis.data 会随筛选变
        var baseOption = {
            tooltip: {
                trigger: 'axis',
                backgroundColor: 'rgba(17, 24, 39, 0.95)',
                borderWidth: 0,
                textStyle: { color: '#fff', fontSize: 12 },
                padding: [8, 12],
                axisPointer: { type: 'cross', lineStyle: { color: '#c7d2fe' } }
            },
            grid: { left: '3%', right: '3%', top: 24, bottom: 28, containLabel: true },
            xAxis: {
                type: 'category', data: [],
                axisLine: { lineStyle: { color: '#e5e7eb' } },
                axisTick: { show: false },
                axisLabel: { color: '#9ca3af', fontSize: 11 }
            },
            yAxis: [
                { type: 'value', name: '收入 (¥)', nameTextStyle: { color: '#9ca3af', fontSize: 11 },
                  axisLine: { show: false }, axisTick: { show: false },
                  splitLine: { lineStyle: { color: '#f3f4f6' } },
                  axisLabel: { color: '#9ca3af', fontSize: 11 } },
                { type: 'value', name: '订单数', nameTextStyle: { color: '#9ca3af', fontSize: 11 },
                  axisLine: { show: false }, axisTick: { show: false },
                  splitLine: { show: false },
                  axisLabel: { color: '#9ca3af', fontSize: 11 } }
            ],
            series: [
                { name: '收入', type: 'line', smooth: true, symbol: 'circle', symbolSize: 6,
                  itemStyle: { color: '#6366f1' }, lineStyle: { width: 2.5 },
                  areaStyle: {
                      color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                          { offset: 0, color: 'rgba(99,102,241,0.22)' },
                          { offset: 1, color: 'rgba(99,102,241,0.02)' }
                      ])
                  }, data: [] },
                { name: '订单数', type: 'bar', yAxisIndex: 1, barWidth: 16,
                  itemStyle: {
                      color: new echarts.graphic.LinearGradient(0, 0, 0, 1, [
                          { offset: 0, color: '#34d399' },
                          { offset: 1, color: '#10b981' }
                      ]),
                      borderRadius: [6, 6, 0, 0]
                  }, data: [] }
            ]
        };
        chartTrend.setOption(baseOption);
        // 用 jQuery + .dashHome 命名空间绑定，PJAX 切回时顶部的 off('.dashHome') 会清掉旧 resize
        $(window).on('resize.dashHome', function () { chartTrend.resize(); });

        // 拉某个日期范围的数据并更新图表
        function loadTrend(range) {
            chartTrend.showLoading({ text: '加载中...', color: '#6366f1', textColor: '#9ca3af', maskColor: 'rgba(255,255,255,0.7)' });
            $.ajax({
                url: '/admin/home.php',
                method: 'GET',
                data: { _action: 'trend', range: range, _t: Date.now() },
                dataType: 'json',
                timeout: 15000
            }).done(function (resp) {
                if (!resp || resp.code !== 200 || !resp.data) return;
                var d = resp.data;
                chartTrend.setOption({
                    xAxis: { data: d.labels || [] },
                    series: [{ data: d.revenue || [] }, { data: d.orders || [] }]
                });
                $('#dashTrendSubtitle').text(d.range_label + ' 已完成订单的收入与订单量');
            }).fail(function () {
                $('#dashTrendSubtitle').text('数据加载失败，请重试');
            }).always(function () {
                chartTrend.hideLoading();
            });
        }

        // 默认加载最近 7 天
        loadTrend('7d');

        // 日期筛选按钮：点击切换 range
        $(document).on('click.dashHome', '#dashTrendFilter .dash-trend-filter__item', function () {
            var $it = $(this);
            if ($it.hasClass('is-active')) return;
            $it.siblings().removeClass('is-active');
            $it.addClass('is-active');
            loadTrend($it.data('range'));
        });
    }
});
</script>
