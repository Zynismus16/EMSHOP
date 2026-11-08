<?php

declare(strict_types=1);

if (defined('EM_INITIALIZED')) {
    return;
}


if (
    isset($_SERVER['HTTP_X_FORWARDED_PROTO']) &&
    $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https'
) {
    $_SERVER['HTTPS'] = 'on';
}

/**
 * 是否启用演示模式（DEMO MODE）。
 * 开启：true 关闭：false
 */
define('EM_DEMO_MODE', false);

/**
 * 系统初始化文件。
 *
 * 负责加载配置、注册自动加载器，并初始化常用基础能力。
 */

// 启用输出缓冲，防止插件或钩子在 session/setcookie 之前产生输出
if (ob_get_level() === 0) {
    ob_start();
}
if (!defined('EM_ROOT')) {
    define('EM_ROOT', __DIR__);
}
define('EM_INITIALIZED', true); 
define('EM_VERSION', '1.3.28');
define('EM_VERSION_TIMESTAMP', '1328');

// 授权服务器地址定义在 Config::load() 之后（要读「开发模式」开关），见下方。

require EM_ROOT . '/base.php';

// 加载站点配置（包含 db / auth 等）
$emFileConfig = require EM_ROOT . '/config.php';
define('EM_CONFIG', $emFileConfig);

// PHP 8.0 字符串函数的 polyfill —— 项目需支持 PHP 7.4+
// 避免在低版本环境下代码里直接用 str_starts_with / str_contains 等抛 undefined function
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || ($needle !== '' && substr_compare($haystack, $needle, -strlen($needle)) === 0);
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

// 尽早启动 session（Csrf 验证和登录状态依赖 session，尽早启动可避免 CSRF 验证失败）
// CLI 环境下跳过 session
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // 每个站点独立的 session 名称 + 目录：避免同主机其它应用共用 PHPSESSID / 默认
    // save_path 时互相读写 session 文件。目录放系统临时目录（不可经 Web 访问）；
    // 建不出来就退回 PHP 默认值，不影响正常启动。
    $emSessionName = 'EMSHOP_' . substr(hash('sha256', EM_ROOT), 0, 16);
    session_name($emSessionName);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $emSessionDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $emSessionName;
    if (!is_dir($emSessionDir)) {
        @mkdir($emSessionDir, 0700, true);
    }
    if (is_dir($emSessionDir) && is_writable($emSessionDir)) {
        session_save_path($emSessionDir);
    }

    // 会话 Cookie 安全属性。此前完全依赖 PHP 默认值：HttpOnly 默认开、SameSite 默认空
    // （由浏览器按 Lax 处理）、Secure 默认关。显式写出来有两个好处：
    //   1) 老浏览器 SameSite 默认是 None 时，这里强制降到 Lax
    //   2) HTTPS 站点补上 Secure，避免 Cookie 在明文链路上泄露
    $emHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        // 只在 HTTPS 下打 Secure：否则 HTTP 部署（含开发环境）会因 Cookie 不被回传而无法登录
        'secure'   => $emHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

require_once EM_ROOT . '/include/lib/Autoloader.php';

Autoloader::register([
    EM_ROOT . '/include/cli',
    EM_ROOT . '/include/lib',
    EM_ROOT . '/include/model',
    EM_ROOT . '/include/service',
    EM_ROOT . '/include/controller',
]);

Hooks::boot();

Config::load();

// 授权服务器地址（多线路）：
//   线路常量写在 LicenseClient 里（全站唯一一份）：默认是官方线路组，后台
//   「设置 › 安全设置 › 开发模式」开启后整组换成本地开发线路，本地联调不用改代码。
//   实际请求只走当前选中的线路（LicenseClient 不自动换线），这里 define 的就是
//   这条线路的地址，给安装上报等 init.php 之后才运行的外部模块用。
// 必须等 Config::load() 之后才定义 —— 这之前的代码（base.php / config.php）不读它。
define('EM_LICENSE_SERVER_URL', LicenseClient::defaultBaseUrl());

// 应用后台设置的时区，让 php 的 date() 与 mysql NOW() 保持一致；
// 不然 web 环境 php.ini 没有 date.timezone 时 date() 返回 UTC，
// 会导致 CommissionLogModel::createFrozen 等地方写入的时间比本地时间晚 8 小时。
$emTimezone = (string) Config::get('site_timezone', 'Asia/Shanghai');
if ($emTimezone !== '' && @date_default_timezone_set($emTimezone) === false) {
    date_default_timezone_set('Asia/Shanghai');
}

Cache::init();

// 加载模板辅助函数（提供 url_goods()、url_blog() 等模板函数）
require_once EM_ROOT . '/include/lib/TemplateHelpers.php';

// 商户（分站）上下文识别：提前到插件/模板加载之前，让后面能按商户 scope 隔离
//   - 命中 /s/{slug}/ 模式会同步改写 $_SERVER['REQUEST_URI']/PATH_INFO，让 Dispatcher 按正常路径解析
//   - 识别失败时按主站处理，不阻断请求
try {
    MerchantContext::resolve();
} catch (Throwable $e) {
    // 识别失败时按主站渲染，不阻断请求
}

// 当前请求作用域：商户命中就用 merchant_{id}，否则走主站 main
//   该 scope 决定"加载哪些插件/模板"—— 商户有自己独立的启用列表
//   同步挂到 $GLOBALS，让 TemplateStorage / Storage 等组件读到统一值
//   （后台请求还会在 admin/merchant global.php 里再覆盖一次；前台请求只靠这里）
$emCurrentScope = MerchantContext::currentId() > 0
    ? 'merchant_' . MerchantContext::currentId()
    : 'main';
$GLOBALS['__em_current_scope'] = $emCurrentScope;

// echo '<pre>';
// print_r([
//     'http_host' => $_SERVER['HTTP_HOST'] ?? '',
//     'main_domain' => Config::get('main_domain', ''),
//     'merchant_id' => MerchantContext::currentId(),
//     'blocked_reason' => MerchantContext::blockedReason(),
// ]);
// die;

// 加载插件系统：按当前 scope 读已启用插件，执行其 addAction 注册钩子
// $emHooks 由插件中的 addAction() 函数填充，供 doAction() 使用
//
// scope 解析（getRuntimeNames）：
//   - 主站 scope：加载 main 启用的所有插件
//   - 商户 scope：SYSTEM_PLUGINS（支付插件 / 商品类型 / 共享店铺）强制从 main 取，
//     其它分类按商户自身 scope 取 —— 这三类属于商城底层能力，由主站统一管控；其它分类
//     给商户保留差异化扩展空间。
//
// 加载是严格按 em_plugin.is_enabled 驱动的，禁用=不加载（语义纯净）。
// 禁用商品类型的副作用（已有该类型商品在前台没类型配置、无法下单）由 OrderModel 统一报错拦截。
$emHooks = [];
try {
    $pluginModel = new PluginModel();
    $activePlugins = $pluginModel->getRuntimeNames($emCurrentScope);

    if (is_array($activePlugins) && $activePlugins !== []) {
        foreach ($activePlugins as $pluginFile) {
            $pluginPath = EM_ROOT . '/content/plugin/' . ltrim($pluginFile, '/') . '/' . ltrim($pluginFile, '/') . '.php';
            if (is_file($pluginPath)) {
                // 标记「正在加载哪个插件」，让这个文件里 addAction 注册的回调
                // 带上归属插件 —— 事件订阅 worker 靠它只调自己的回调，
                // 否则同一事件有 N 个订阅方时每个插件会被执行 N 次。
                // try/finally 保证即使插件文件抛错也能复位，不会把归属串到下一个插件。
                Hooks::setActingPlugin((string) $pluginFile);
                try {
                    include_once $pluginPath;
                } finally {
                    Hooks::setActingPlugin(null);
                }
            }
        }
    }
} catch (Throwable $e) {
    // 插件加载失败不影响系统启动
}

// 加载当前启用模板的系统挂载文件（按当前 scope）
try {
    $templateModel = new TemplateModel();
    $activeTemplates = array_filter([
        $templateModel->getEffectiveTheme('pc', $emCurrentScope),
        $templateModel->getEffectiveTheme('mobile', $emCurrentScope),
    ]);

    foreach (array_unique($activeTemplates) as $templateName) {
        $templatePluginFile = $templateModel->getPluginFilePath((string) $templateName);
        if (is_file($templatePluginFile)) {
            include_once $templatePluginFile;
        }
    }
} catch (Throwable $e) {
    // 模板加载失败不影响系统启动
}

// 注册商品类型：插件/模板加载完后，统一收集所有已注册的商品类型
// 此钩子的回调声明了引用参数 function(&$types)，需要直接调用以保留引用语义
// doAction 的 ...$args 展开运算符不保留引用，会触发 Warning
$types = [];
$callbacks = Hooks::getCallbacks('goods_type_register');
foreach ($callbacks as $fn) {
    call_user_func_array($fn, [&$types]);
}
foreach ($types as $type => $config) {
    GoodsTypeManager::registerType($type, $config);
}

// 访客 ?r=XXX 归因：首次进入时写 10 年 Cookie，用于注册/下单时绑定上级
// 注意：仅主站做归因。商户站不再支持推广返佣，访问 1022.em.cc/?r=XXX 时不写 cookie，
// 也避免商户子域 cookie 污染主站会话。
if (php_sapi_name() !== 'cli' && MerchantContext::currentId() === 0) {
    try {
        InviteToken::captureFromQuery();
    } catch (Throwable $e) {
        // 归因失败不影响正常请求
    }
}

// 注册关闭函数，确保 dirty 数据落库
register_shutdown_function(function () {
    try {
        Option::flush();
    } catch (Throwable $e) {
        // ignore
    }
});


// usleep(500000); // 模拟慢速环境，方便测试加载中的loading特效等，不要删除