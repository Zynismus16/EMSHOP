<?php

declare(strict_types=1);

require __DIR__ . '/global.php';
 
/**
 * 后台控制台首页。 
 *
 * 流程：
 *   - 鉴权；授权状态核对（revalidateCurrent）改由首页的 admin_index_data 请求异步触发，
 *     不再阻塞首屏渲染
 *   - 渲染由 view/home.php 自查统计数据（解耦 controller，无论 PJAX 还是默认入口都能直接渲染）
 *
 * 入口说明：
 *   /admin/home.php         本文件 → PJAX 加载 / 直接访问都走这里
 *   /admin/index.php        后台入口 → 默认 $adminContentView = view/home.php，同样能渲染
 */
adminRequireLogin();
$user = $adminUser;
$siteName = Config::get('sitename', 'EMSHOP');
$siteUrl = trim((string) (Config::get('site_url') ?? ''));

if ($siteUrl === '') {
    if ((string) Input::get('_action', '') !== '') {
        Response::error('请先在基础设置中配置站点地址（site_url）');
    }
    Response::redirect('/admin/settings.php?action=base&_need_site_url=1');
}

/**
 * AJAX：拉取首页需要的官方服务数据（授权状态 / 版本 / 公告 / 广告位 / 代理商联系方式）
 * 走独立 action 是为了页面不阻塞 —— 页面先渲染框架，JS 再异步拉这块
 *
 * 顺便返回这次上游请求的往返耗时（latency_ms）。
 */
if ((string) Input::get('_action', '') === 'admin_index_data') {
    // 先释放 session 文件锁：本请求要等上游（最坏 线路数 × 8s），
    // 不解锁的话同页面的 trend 请求会一直排队，整个首页显得很卡。
    // 本分支之后不再写 session，释放是安全的。
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    // 授权状态核对：原先在页面渲染前同步执行，上游慢时首屏也要一起等；
    // 挪到这里异步做，首屏先出来，核对结果晚一步生效。
    LicenseService::revalidateCurrent();

    // 每次都实时拉（不做本地缓存；接口不通则返回失败态，latency_ms = -1）
    $start = microtime(true);
    $data  = null;
    $error = '';
    try {
        $data = LicenseService::fetchBaseData();
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
    $latency = $data !== null ? (int) round((microtime(true) - $start) * 1000) : -1;

    // 当前线路的连接速度（首页「官方线路」卡）：直接用上面这次请求的往返耗时。
    // 与「获取信息」同一个接口、同一条线路，不再额外发探测请求。
    // 数据没拿到（请求失败）时按失败处理，避免出现"数据失败但速度正常"的割裂。
    $lineLatency = $data !== null ? LicenseClient::lastRequestLatencyMs() : null;

    // 注意：这里的 authorized / license_type 只作为展示数据回给前端，
    // **不用来改本地授权状态** —— 授权判定只走
    // LicenseService::revalidateCurrent()（api/open/v1/em/license/status）。
    Response::success('', ($data ?? [
        'authorized'       => null,
        'license_type'     => '',
        'license_label'    => '',
        'identity_matched' => false,
        'used_fallback'    => false,
        'update'           => [
            'has_new' => false, 'version' => '', 'force' => false,
            'min_version' => '', 'package_url' => '',
        ],
        'announcements'    => [],
        'ad_slots'         => [],
        'buy_links'        => [],
        'download_links'   => [],
        'contact'          => [],
    ]) + [
        'latency_ms'      => $latency,
        'line_latency_ms' => $lineLatency,
        'error'           => $error,
        'current_version' => EM_VERSION,
    ]);
}

/**
 * AJAX：销售趋势数据（按日期范围聚合）
 * 前端 dashTrendFilter 点击触发，切换图表数据
 *
 * range 支持：
 *   today / yesterday         —— 按小时聚合（24 点）
 *   week / 7d / month / 30d   —— 按天聚合
 *   6m / year / 12m           —— 按月聚合
 */
if ((string) Input::get('_action', '') === 'trend') {
    // 只读本地库：先释放 session 锁，不跟同一页面的首页数据请求互相排队
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $range = (string) Input::get('range', '7d');
    $prefix = Database::prefix();
    $orderTable = $prefix . 'order';

    // 1. 根据 range 确定起止时间 + 聚合粒度 + 时间桶 label 列表
    $now = time();
    $today = date('Y-m-d', $now);

    $granularity = 'day';   // hour / day / month
    $labels = [];           // x 轴标签
    $bucketDates = [];      // 精确到粒度的 key，用于 ORDER GROUP BY 结果回填

    switch ($range) {
        case 'today':
        case 'yesterday':
            $granularity = 'hour';
            $base = $range === 'today' ? $today : date('Y-m-d', strtotime('-1 day'));
            $startTs = strtotime($base . ' 00:00:00');
            $endTs   = strtotime($base . ' 23:59:59');
            for ($h = 0; $h < 24; $h++) {
                $labels[] = sprintf('%02d:00', $h);
                $bucketDates[] = $base . ' ' . sprintf('%02d', $h);  // YYYY-MM-DD HH
            }
            break;

        case 'week': // 本周（周一 ~ 今日）
            $granularity = 'day';
            $dow = (int) date('N', $now); // 1=周一
            $monday = date('Y-m-d', strtotime('-' . ($dow - 1) . ' days'));
            $startTs = strtotime($monday . ' 00:00:00');
            $endTs   = strtotime($today . ' 23:59:59');
            for ($i = 0; $i < $dow; $i++) {
                $d = date('Y-m-d', strtotime($monday . ' +' . $i . ' days'));
                $labels[] = date('m-d', strtotime($d));
                $bucketDates[] = $d;
            }
            break;

        case 'month': // 本月 1 号到今天
            $granularity = 'day';
            $firstDay = date('Y-m-01', $now);
            $startTs = strtotime($firstDay . ' 00:00:00');
            $endTs   = strtotime($today . ' 23:59:59');
            $dayOfMonth = (int) date('j', $now);
            for ($i = 0; $i < $dayOfMonth; $i++) {
                $d = date('Y-m-d', strtotime($firstDay . ' +' . $i . ' days'));
                $labels[] = date('m-d', strtotime($d));
                $bucketDates[] = $d;
            }
            break;

        case '30d':
            $granularity = 'day';
            $startTs = strtotime('-29 days', strtotime($today . ' 00:00:00'));
            $endTs   = strtotime($today . ' 23:59:59');
            for ($i = 29; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $labels[] = date('m-d', strtotime($d));
                $bucketDates[] = $d;
            }
            break;

        case '6m': // 最近半年（6 个月，按月聚合）
            $granularity = 'month';
            $startTs = strtotime('-5 months', strtotime(date('Y-m-01') . ' 00:00:00'));
            $endTs   = strtotime($today . ' 23:59:59');
            for ($i = 5; $i >= 0; $i--) {
                $m = date('Y-m', strtotime("-{$i} months"));
                $labels[] = $m;
                $bucketDates[] = $m;
            }
            break;

        case 'year': // 本年 Jan-Dec
            $granularity = 'month';
            $thisYear = (int) date('Y', $now);
            $startTs = strtotime($thisYear . '-01-01 00:00:00');
            $endTs   = strtotime($thisYear . '-12-31 23:59:59');
            for ($m = 1; $m <= 12; $m++) {
                $monthKey = sprintf('%d-%02d', $thisYear, $m);
                $labels[] = $monthKey;
                $bucketDates[] = $monthKey;
            }
            break;

        case '12m': // 最近 12 个月
            $granularity = 'month';
            $startTs = strtotime('-11 months', strtotime(date('Y-m-01') . ' 00:00:00'));
            $endTs   = strtotime($today . ' 23:59:59');
            for ($i = 11; $i >= 0; $i--) {
                $m = date('Y-m', strtotime("-{$i} months"));
                $labels[] = $m;
                $bucketDates[] = $m;
            }
            break;

        case '7d':
        default:
            $range = '7d';
            $granularity = 'day';
            $startTs = strtotime('-6 days', strtotime($today . ' 00:00:00'));
            $endTs   = strtotime($today . ' 23:59:59');
            for ($i = 6; $i >= 0; $i--) {
                $d = date('Y-m-d', strtotime("-{$i} days"));
                $labels[] = date('m-d', strtotime($d));
                $bucketDates[] = $d;
            }
            break;
    }

    // 2. 按粒度聚合查询已完成订单
    try {
        if ($granularity === 'hour') {
            $sql = "SELECT DATE_FORMAT(`complete_time`, '%Y-%m-%d %H') AS bucket,
                           COALESCE(SUM(`pay_amount`), 0) AS revenue,
                           COUNT(*) AS orders
                    FROM `{$orderTable}`
                    WHERE `status` = ? AND `complete_time` >= ? AND `complete_time` <= ?
                    GROUP BY bucket";
        } elseif ($granularity === 'month') {
            $sql = "SELECT DATE_FORMAT(`complete_time`, '%Y-%m') AS bucket,
                           COALESCE(SUM(`pay_amount`), 0) AS revenue,
                           COUNT(*) AS orders
                    FROM `{$orderTable}`
                    WHERE `status` = ? AND `complete_time` >= ? AND `complete_time` <= ?
                    GROUP BY bucket";
        } else { // day
            $sql = "SELECT DATE(`complete_time`) AS bucket,
                           COALESCE(SUM(`pay_amount`), 0) AS revenue,
                           COUNT(*) AS orders
                    FROM `{$orderTable}`
                    WHERE `status` = ? AND `complete_time` >= ? AND `complete_time` <= ?
                    GROUP BY bucket";
        }
        $rows = Database::query($sql, ['completed', date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $endTs)]);
        $index = [];
        foreach ($rows as $r) $index[(string) $r['bucket']] = $r;
    } catch (Throwable $e) {
        $index = [];
    }

    $revenueSeries = [];
    $orderSeries   = [];
    foreach ($bucketDates as $key) {
        $hit = $index[$key] ?? null;
        $revenueSeries[] = $hit ? round(((int) $hit['revenue']) / 1000000, 2) : 0;
        $orderSeries[]   = $hit ? (int) $hit['orders'] : 0;
    }

    // 生成副标题说明用的时间范围文案
    $rangeLabels = [
        'today' => '今日', 'yesterday' => '昨日',
        'week'  => '本周', '7d' => '最近 7 天',
        'month' => '本月', '30d' => '最近 30 天',
        '6m'    => '最近半年', 'year' => '本年', '12m' => '最近 1 年',
    ];

    Response::success('', [
        'range'    => $range,
        'range_label' => $rangeLabels[$range] ?? $range,
        'labels'   => $labels,
        'revenue'  => $revenueSeries,
        'orders'   => $orderSeries,
    ]);
}

if (Input::get('popup', '') === 'rewrite') {
    include __DIR__ . '/view/popup/rewrite.php';
    return;
}
 
/**
 * 弹窗：任务服务说明 / 升级后重启提示（layer.open type:2 加载）
 */
if (Input::get('popup', '') === 'server_guide') {
    include __DIR__ . '/view/popup/server_guide.php';
    return;
}
if ((string) Input::get('_popup', '') === 'server_restart') {
    include __DIR__ . '/view/popup/server_restart.php';
    return;
}

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/home.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/home.php';
    require __DIR__ . '/index.php';
}
