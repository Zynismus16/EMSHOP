<?php

declare(strict_types=1);

// 在线安装向导入口：不依赖 init.php / config.php

if (php_sapi_name() === 'cli') {
    http_response_code(400);
    echo "installer is web-only\n";
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('EM_ROOT', dirname(__DIR__));

require_once EM_ROOT . '/include/lib/Autoloader.php';
Autoloader::register([
    EM_ROOT . '/include/lib',
    EM_ROOT . '/include/model',
    EM_ROOT . '/include/service',
    EM_ROOT . '/include/controller',
]);

/**
 * 读取系统版本（不加载 init.php，避免依赖 config.php/数据库）。
 */
function installer_system_version(): string
{
    $initFile = EM_ROOT . '/init.php';
    if (!is_file($initFile) || !is_readable($initFile)) {
        return 'unknown';
    }
    $content = file_get_contents($initFile);
    if ($content === false) {
        return 'unknown';
    }
    if (preg_match("/define\\(\\s*'EM_VERSION'\\s*,\\s*'([^']+)'\\s*\\)\\s*;/", $content, $m)) {
        return (string) $m[1];
    }
    return 'unknown';
}

/**
 * 安装锁（存在则视为已安装）。
 */
const EM_INSTALL_LOCK = EM_ROOT . '/install/install.lock';

function installer_is_installed(): bool
{
    return is_file(EM_INSTALL_LOCK);
}


function installer_is_ajax_request(): bool
{
    $xrw = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return is_string($xrw) && strtolower($xrw) === 'xmlhttprequest';
}

function installer_admin_url(): string
{
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    return $scheme . '://' . $host . '/admin/';
}

function installer_detect_site_url(): string
{
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto !== '' && strpos($forwardedProto, ',') !== false) {
        $forwardedProto = trim((string) explode(',', $forwardedProto, 2)[0]);
    }
    $forwardedHost = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
    if ($forwardedHost !== '' && strpos($forwardedHost, ',') !== false) {
        $forwardedHost = trim((string) explode(',', $forwardedHost, 2)[0]);
    }
    $host = $forwardedHost !== '' ? $forwardedHost : trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }

    $scheme = 'http';
    if ($forwardedProto === 'https' || $forwardedProto === 'http') {
        $scheme = $forwardedProto;
    } else {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((int) ($_SERVER['SERVER_PORT'] ?? 80) === 443);
        $scheme = $isHttps ? 'https' : 'http';
    }

    return $scheme . '://' . $host;
}

function installer_messages_text(array $messages): string
{
    $texts = [];
    foreach ($messages as $message) {
        if (is_array($message) && isset($message['text']) && is_string($message['text']) && $message['text'] !== '') {
            $texts[] = $message['text'];
        }
    }
    if ($texts === []) {
        return '安装失败，请检查环境后重试';
    }
    return implode("\n", $texts);
}

function installer_fail_response(string $action, array $messages, array $defaults): void
{
    if ($action === 'install' && installer_is_ajax_request()) {
        Response::error(installer_messages_text($messages), ['messages' => $messages]);
    }
    installer_render($messages, $defaults, 'form');
}

function installer_success_response(string $action, string $message, array $defaults, array $data = []): void
{
    if ($action === 'install' && installer_is_ajax_request()) {
        Response::success($message, $data);
    }
    installer_render([['type' => 'ok', 'text' => $message]], $defaults, 'form');
}

/**
 * 把数据库驱动的原始报错翻译成用户看得懂的提示。
 * 优先按驱动错误码判断：报错文案在中文 Windows 等环境下会被本地化，按文本匹配会漏。
 * 认不出来的原样返回，不做二次包装。
 *
 * @param array<string, mixed> $db
 * @param int $errno 驱动错误码（mysqli_connect_errno / mysqli_errno / PDO errorInfo[1]）
 */
function installer_friendly_db_error(string $raw, array $db, int $errno = 0): string
{
    $user = (string) ($db['username'] ?? '');
    $host = (string) ($db['host'] ?? '');
    $name = (string) ($db['dbname'] ?? '');

    // PDO 的 SQLSTATE 消息里带 [1045] 这样的驱动错误码，直接取出来
    if ($errno === 0 && preg_match('/\[(\d{4})\]/', $raw, $m)) {
        $errno = (int) $m[1];
    }

    if ($errno === 1044) {
        // 该账号对这个库没权限（库不存在时也可能报这条，MySQL 不区分）
        return sprintf('数据库「%s」不存在，或账号「%s」没有访问权限，请检查数据库名与账号权限', $name, $user);
    }
    if ($errno === 1045) {
        // 用户名不存在 / 密码错误，MySQL 出于安全不区分两者
        return '数据库用户名或密码错误，请检查后重试';
    }
    if ($errno === 1049) {
        return sprintf('数据库「%s」不存在，请先在数据库面板中创建', $name);
    }
    if ($errno === 2005) {
        return sprintf('数据库地址「%s」无法解析，请检查地址是否填写正确', $host);
    }
    if ($errno === 2002 || $errno === 2003 || $errno === 2006) {
        // 连不上和解析不了都是 2002，只能靠这段 ASCII 报错区分（后面的中文会被系统本地化）
        if (preg_match('/getaddrinfo|php_network_getaddresses|Unknown MySQL server host/i', $raw)) {
            return sprintf('数据库地址「%s」无法解析，请检查地址是否填写正确', $host);
        }
        return sprintf('无法连接数据库服务器，请检查地址（%s）、端口是否正确，以及数据库服务是否已启动', $host);
    }

    // 错误码缺失或不认识时，退回文本匹配
    if (stripos($raw, 'Access denied for user') !== false) {
        if (preg_match("/to database\s+'([^']*)'/i", $raw, $m)) {
            return sprintf('数据库「%s」不存在，或账号「%s」没有访问权限，请检查数据库名与账号权限', $m[1], $user);
        }
        return '数据库用户名或密码错误，请检查后重试';
    }
    if (stripos($raw, 'Unknown database') !== false) {
        return sprintf('数据库「%s」不存在，请先在数据库面板中创建', $name);
    }
    if (stripos($raw, 'Unknown MySQL server host') !== false || stripos($raw, 'getaddrinfo') !== false) {
        return sprintf('数据库地址「%s」无法解析，请检查地址是否填写正确', $host);
    }
    if (stripos($raw, 'Connection refused') !== false
        || stripos($raw, "Can't connect to MySQL server") !== false
        || stripos($raw, 'Connection timed out') !== false
        || stripos($raw, 'No such file or directory') !== false) {
        return sprintf('无法连接数据库服务器，请检查地址（%s）、端口是否正确，以及数据库服务是否已启动', $host);
    }
    return $raw;
}

/**
 * 不依赖 Database 类的连接测试（避免 Database 连接失败时触发 Emmsg::error 的致命参数错误）。
 *
 * @param array<string, mixed> $db
 * @return array{ok:bool, driver:string, message:string}
 */
function installer_test_db_connection(array $db): array
{
    $host = (string) ($db['host'] ?? '127.0.0.1');
    $port = (int) ($db['port'] ?? 3306);
    $user = (string) ($db['username'] ?? '');
    $pass = (string) ($db['password'] ?? '');

    // 先优先 mysqli
    if (extension_loaded('mysqli')) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @mysqli_connect($host, $user, $pass, null, $port);
        if ($mysqli === false) {
            return [
                'ok' => false,
                'driver' => 'mysqli',
                'message' => installer_friendly_db_error((string) mysqli_connect_error(), $db, (int) mysqli_connect_errno()),
            ];
        }
        @mysqli_close($mysqli);
        return ['ok' => true, 'driver' => 'mysqli', 'message' => '数据库连接成功'];
    }

    if (extension_loaded('pdo_mysql')) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
            new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            return ['ok' => true, 'driver' => 'pdo', 'message' => '数据库连接成功'];
        } catch (Throwable $e) {
            return ['ok' => false, 'driver' => 'pdo', 'message' => installer_friendly_db_error($e->getMessage(), $db, (int) ($e->errorInfo[1] ?? 0))];
        }
    }

    return ['ok' => false, 'driver' => 'none', 'message' => '环境缺少 mysqli 或 pdo_mysql 扩展'];
}

function installer_fetch_tables(array $db): array
{
    $host = (string) ($db['host'] ?? '127.0.0.1');
    $port = (int) ($db['port'] ?? 3306);
    $name = (string) ($db['dbname'] ?? '');
    $user = (string) ($db['username'] ?? '');
    $pass = (string) ($db['password'] ?? '');
    if ($name === '') {
        return ['ok' => false, 'driver' => 'none', 'message' => '数据库名为空，无法获取表快照', 'tables' => []];
    }

    if (extension_loaded('mysqli')) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @mysqli_connect($host, $user, $pass, $name, $port);
        if ($mysqli === false) {
            return [
                'ok' => false,
                'driver' => 'mysqli',
                'message' => installer_friendly_db_error((string) mysqli_connect_error(), $db, (int) mysqli_connect_errno()),
                'tables' => [],
            ];
        }
        @mysqli_set_charset($mysqli, 'utf8mb4');
        $result = @mysqli_query($mysqli, 'SHOW TABLES');
        if ($result === false) {
            $error = installer_friendly_db_error((string) mysqli_error($mysqli), $db, (int) mysqli_errno($mysqli));
            @mysqli_close($mysqli);
            return ['ok' => false, 'driver' => 'mysqli', 'message' => '读取数据表失败：' . $error, 'tables' => []];
        }
        $tables = [];
        while ($row = mysqli_fetch_row($result)) {
            if (isset($row[0])) {
                $tables[] = (string) $row[0];
            }
        }
        mysqli_free_result($result);
        @mysqli_close($mysqli);
        return ['ok' => true, 'driver' => 'mysqli', 'message' => 'ok', 'tables' => $tables];
    }

    if (extension_loaded('pdo_mysql')) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN, 0);
            $tables = [];
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $tables[] = (string) $row;
                }
            }
            return ['ok' => true, 'driver' => 'pdo', 'message' => 'ok', 'tables' => $tables];
        } catch (Throwable $e) {
            return ['ok' => false, 'driver' => 'pdo', 'message' => '读取数据表失败：' . installer_friendly_db_error($e->getMessage(), $db, (int) ($e->errorInfo[1] ?? 0)), 'tables' => []];
        }
    }

    return ['ok' => false, 'driver' => 'none', 'message' => '环境缺少 mysqli 或 pdo_mysql 扩展', 'tables' => []];
}

/**
 * 从表清单里挑出属于当前前缀的表。
 * 安装只清理自己的表；前缀为空时返回空数组（否则会匹配到库里的每一张表）。
 *
 * @param array<int, string> $tables
 * @return array<int, string>
 */
function installer_filter_prefixed_tables(array $tables, string $prefix): array
{
    $prefixLower = strtolower(trim($prefix));
    if ($prefixLower === '') {
        return [];
    }
    $matched = [];
    foreach ($tables as $table) {
        $table = (string) $table;
        if (strpos(strtolower($table), $prefixLower) === 0) {
            $matched[] = $table;
        }
    }
    return $matched;
}

function installer_drop_tables(array $db, array $tables): array
{
    $host = (string) ($db['host'] ?? '127.0.0.1');
    $port = (int) ($db['port'] ?? 3306);
    $name = (string) ($db['dbname'] ?? '');
    $user = (string) ($db['username'] ?? '');
    $pass = (string) ($db['password'] ?? '');
    if ($name === '') {
        return ['ok' => false, 'driver' => 'none', 'message' => '数据库名为空，无法回滚表', 'dropped' => 0, 'failed' => []];
    }

    $validTables = [];
    foreach ($tables as $table) {
        $table = (string) $table;
        if (preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            $validTables[] = $table;
        }
    }
    if ($validTables === []) {
        return ['ok' => true, 'driver' => 'none', 'message' => '无需回滚', 'dropped' => 0, 'failed' => []];
    }

    if (extension_loaded('mysqli')) {
        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @mysqli_connect($host, $user, $pass, $name, $port);
        if ($mysqli === false) {
            return [
                'ok' => false,
                'driver' => 'mysqli',
                'message' => 'mysqli 连接失败：' . (string) mysqli_connect_error(),
                'dropped' => 0,
                'failed' => $validTables,
            ];
        }
        @mysqli_query($mysqli, 'SET FOREIGN_KEY_CHECKS=0');
        $failed = [];
        $dropped = 0;
        for ($i = count($validTables) - 1; $i >= 0; $i--) {
            $table = $validTables[$i];
            $sql = 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`';
            if (@mysqli_query($mysqli, $sql) === false) {
                $failed[] = $table;
                continue;
            }
            $dropped++;
        }
        @mysqli_query($mysqli, 'SET FOREIGN_KEY_CHECKS=1');
        @mysqli_close($mysqli);
        return ['ok' => $failed === [], 'driver' => 'mysqli', 'message' => $failed === [] ? 'ok' : '部分回滚失败', 'dropped' => $dropped, 'failed' => $failed];
    }

    if (extension_loaded('pdo_mysql')) {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
            $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $failed = [];
            $dropped = 0;
            for ($i = count($validTables) - 1; $i >= 0; $i--) {
                $table = $validTables[$i];
                try {
                    $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
                    $dropped++;
                } catch (Throwable $e) {
                    $failed[] = $table;
                }
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            return ['ok' => $failed === [], 'driver' => 'pdo', 'message' => $failed === [] ? 'ok' : '部分回滚失败', 'dropped' => $dropped, 'failed' => $failed];
        } catch (Throwable $e) {
            return ['ok' => false, 'driver' => 'pdo', 'message' => '回滚连接失败：' . $e->getMessage(), 'dropped' => 0, 'failed' => $validTables];
        }
    }

    return ['ok' => false, 'driver' => 'none', 'message' => '环境缺少 mysqli 或 pdo_mysql 扩展', 'dropped' => 0, 'failed' => $validTables];
}

/**
 * 生成 config.php 内容。
 *
 * @param array<string, mixed> $db
 */
function installer_generate_config_php(array $db): string
{
    $lockGuard = <<<'PHP'
// 未安装（缺少安装锁）时：自动跳转到在线安装向导
if (!is_file(__DIR__ . '/install/install.lock')) {
    header('Location: /install/');
    exit;
}
PHP;

    $config = [
        'db' => [
            'host' => (string) ($db['host'] ?? '127.0.0.1'),
            'port' => (int) ($db['port'] ?? 3306),
            'dbname' => (string) ($db['dbname'] ?? ''),
            'username' => (string) ($db['username'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
            // 安装固定使用 utf8mb4，避免用户误选导致表/索引不兼容
            'charset' => 'utf8mb4',
            'prefix' => (string) ($db['prefix'] ?? 'em_'),
        ],
        'auth' => [
            'session_key' => 'em_admin_auth',
            'remember_cookie' => 'em_admin_remember',
            'remember_days_default' => 7,
            'remember_days_checked' => 365,
            'csrf_key' => 'em_admin_csrf',
        ],
        'avatar' => '/content/static/img/default-admin-avatar.jpg',
        'placeholder_img' => '/content/static/img/img-1.png',
        // 授权服务器地址不再写进 config.php：固定内置在 init.php 的 EM_LICENSE_SERVER_URL，
        // 免得用户更新程序后 config.php 里的旧地址还在生效
    ];

    $export = var_export($config, true);
    $export = preg_replace('/^(\s*)array\s*\(/m', '$1[', (string) $export);
    $export = preg_replace('/\)(,?)$/m', ']$1', (string) $export);

    return "<?php\n\n" . $lockGuard . "\n\nreturn " . $export . ";\n";
}

/**
 * 输出安装页 UI（不复用前台模板，避免依赖 init.php）。
 *
 * @param array<int, array{type:string, text:string}> $messages
 * @param array<string, mixed> $defaults
 * @param string $step intro=第一步（介绍与环境建议） / form=第二步（配置表单）
 * @param bool $demo 调试用：带上 ?demo=1 时用假数据直接弹出「安装成功」弹窗，方便调样式
 */
function installer_render(array $messages, array $defaults, string $step = 'intro', bool $demo = false): void
{

    $systemVersion = installer_system_version();

    $db = $defaults['db'] ?? [];
    $dbHost = is_array($db) ? (string) ($db['host'] ?? '127.0.0.1') : '127.0.0.1';
    $dbPort = is_array($db) ? (string) ($db['port'] ?? '3306') : '3306';
    $dbName = is_array($db) ? (string) ($db['dbname'] ?? '') : '';
    $dbUser = is_array($db) ? (string) ($db['username'] ?? '') : '';
    $dbPass = is_array($db) ? (string) ($db['password'] ?? '') : '';
    $dbPrefix = is_array($db) ? (string) ($db['prefix'] ?? 'em_') : 'em_';

    $adminUsername = (string) (($defaults['admin']['username'] ?? '') ?: 'admin');
    $adminEmail = (string) (($defaults['admin']['email'] ?? '') ?: '');

    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>EMSHOP 在线安装</title>
    <link rel="stylesheet" href="/content/static/lib/layui-v2.13.5/layui/css/layui.css">
    <link rel="stylesheet" href="/content/static/css/em-toast.css">
    <style>
        :root {
            --accent: #6366f1;
            --accent-strong: #4f46e5;
            --bg: #f6f7fb;
            --ink: #131a2e;
            --ink-2: #47506a;
            --muted: #7a839a;
            --line: #e6e8f0;
            --line-strong: #d3d8e4;
            --radius: 12px;
            --ring: 0 0 0 3px rgba(99, 102, 241, 0.18);
            --field-bg: #fcfcfe;
            --shadow-panel: 0 24px 60px -34px rgba(15, 23, 41, 0.3), 0 1px 2px rgba(15, 23, 41, 0.04);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0 0 72px;
            min-height: 100vh;
            color: var(--ink);
            background: var(--bg);
            font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif;
            -webkit-font-smoothing: antialiased;
        }
        /* 背景：柔和光晕 + 渐隐点阵 */
        body::before,
        body::after { content: ""; position: fixed; inset: 0; z-index: 0; pointer-events: none; }
        body::before {
            background:
                radial-gradient(56% 46% at 2% -8%, rgba(99, 102, 241, 0.17), transparent 68%),
                radial-gradient(52% 48% at 100% 2%, rgba(168, 85, 247, 0.13), transparent 70%),
                radial-gradient(46% 40% at 50% 112%, rgba(99, 102, 241, 0.08), transparent 72%);
        }
        body::after {
            background-image: radial-gradient(rgba(15, 23, 41, 0.06) 1px, transparent 1px);
            background-size: 22px 22px;
            -webkit-mask-image: linear-gradient(180deg, #000, transparent 72%);
            mask-image: linear-gradient(180deg, #000, transparent 72%);
        }

        /* 主体 */
        .page { position: relative; z-index: 1; max-width: 680px; margin: 0 auto; padding: 84px 20px 0; }
        .page-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .eyebrow {
            display: inline-flex; align-items: center; gap: 9px;
            font-size: 12px; font-weight: 600; letter-spacing: 0.16em; color: var(--accent-strong);
        }
        .eyebrow::before {
            content: ""; width: 6px; height: 6px; border-radius: 50%;
            background: var(--accent); box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.14);
            animation: pulse 2.4s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.14); }
            50% { box-shadow: 0 0 0 7px rgba(99, 102, 241, 0.05); }
        }
        .version {
            flex: none; padding: 5px 11px;
            font: 500 11.5px/1 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            color: var(--muted); background: #fff;
            border: 1px solid var(--line); border-radius: 999px;
        }
        .title {
            margin: 16px 0 0; font-size: 38px; font-weight: 700; line-height: 1.16; letter-spacing: -0.02em;
            background: linear-gradient(180deg, #131a2e 24%, #4d5a79);
            -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        .lede { margin: 14px 0 0; max-width: 30em; font-size: 14.5px; color: var(--muted); text-wrap: pretty; }

        /* 面板 */
        .panel {
            margin-top: 32px; overflow: hidden;
            background: linear-gradient(180deg, #fff, #fdfdff);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: var(--shadow-panel);
        }

        /* 提示 */
        .alerts { padding: 20px 20px 0; }
        .msg {
            position: relative; margin-bottom: 10px; padding: 13px 16px 13px 19px;
            font-size: 13px; white-space: pre-line;
            border: 1px solid transparent; border-radius: var(--radius);
        }
        .msg:last-child { margin-bottom: 0; }
        .msg::before {
            content: ""; position: absolute; top: 13px; bottom: 13px; left: 0;
            width: 3px; border-radius: 999px; background: currentColor; opacity: 0.85;
        }
        .msg.ok { color: #067647; background: #ecfdf3; border-color: #abefc6; }
        .msg.bad { color: #b42318; background: #fef3f2; border-color: #fecdca; }
        .msg.warn { color: #b54708; background: #fffaeb; border-color: #fedf89; }

        /* 分区 */
        .section { padding: 28px 20px; }
        .section + .section { border-top: 1px solid #eef0f6; }
        .section-head { display: flex; gap: 12px; margin-bottom: 20px; }
        .section-head::before {
            content: ""; flex: none; width: 3px; margin: 3px 0; border-radius: 999px;
            background: var(--accent);
        }
        .section-head h2 { margin: 0; font-size: 15px; font-weight: 600; letter-spacing: -0.01em; }
        .section-head p { margin: 4px 0 0; font-size: 12.5px; color: var(--muted); }

        /* 运行环境建议 */
        .env { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .env-item {
            padding: 16px; border-radius: var(--radius);
            background: #fafbfd; border: 1px solid var(--line);
        }
        .env-badge { display: block; height: 20px; }
        .env-desc { margin: 12px 0 0; font-size: 12.5px; line-height: 1.6; color: var(--muted); }
        .env-note { margin: 14px 0 0; font-size: 12.5px; color: var(--muted); }

        /* 表单：两列等宽 */
        .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px 16px; }
        .field label { display: block; margin-bottom: 8px; font-size: 12.5px; font-weight: 500; color: #5a6478; }
        .field label.required::after { content: "*"; margin-left: 3px; color: #e5484d; }
        .control { position: relative; display: flex; align-items: center; }
        .control svg {
            position: absolute; left: 14px; width: 16px; height: 16px;
            color: #a3abbd; transition: color 0.18s; pointer-events: none;
        }
        .control input {
            width: 100%; height: 48px; padding: 0 14px 0 42px;
            font-size: 14px; font-family: inherit; color: var(--ink);
            background: var(--field-bg); border: 1px solid #e3e6ef; border-radius: var(--radius);
            transition: border-color 0.18s, box-shadow 0.18s, background 0.18s;
        }
        .control input::placeholder { color: #a3abbd; }
        .control input:hover { border-color: var(--line-strong); }
        .control input:focus {
            outline: none; background: #fff;
            border-color: var(--accent); box-shadow: var(--ring);
        }
        .control:focus-within svg { color: var(--accent); }
        .control input:-webkit-autofill {
            -webkit-text-fill-color: var(--ink);
            -webkit-box-shadow: 0 0 0 1000px var(--field-bg) inset;
        }

        /* 底部操作区 */
        .panel-foot {
            display: flex; align-items: center; justify-content: space-between; gap: 18px;
            padding: 18px 20px;
            background: #fafbfd;
            border-top: 1px solid #eef0f6;
        }
        .foot-hint { font-size: 12.5px; color: var(--muted); }
        .foot-btns { display: flex; gap: 10px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            height: 44px; padding: 0 22px;
            font-size: 14px; font-weight: 600; font-family: inherit; text-decoration: none;
            border: 1px solid transparent; border-radius: var(--radius); cursor: pointer;
            transition: transform 0.18s, box-shadow 0.18s, background 0.18s, border-color 0.18s, color 0.18s;
        }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-primary {
            color: #fff;
            background: linear-gradient(180deg, #6366f1, #4338ca);
            box-shadow: 0 12px 24px -12px rgba(79, 70, 229, 0.6);
        }
        .btn-primary:hover:not(:disabled) { transform: translateY(-1px); box-shadow: 0 16px 30px -12px rgba(79, 70, 229, 0.75); }
        .btn-ghost { color: var(--ink-2); background: #fff; border-color: #e3e6ef; }
        .btn-ghost:hover:not(:disabled) { color: var(--ink); background: #f8fafc; border-color: var(--line-strong); }

        /* 浮动提示 / 加载遮罩的样式已抽到公共组件：/content/static/css/em-toast.css */

        /* 安装成功弹窗：极简版（图标 + 标题 + 一张信息表 + 一个按钮） */
        .install-success-skin {
            overflow: hidden;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 30px 70px -34px rgba(15, 23, 41, 0.45), 0 1px 2px rgba(15, 23, 41, 0.04);
        }
        .install-success-skin .layui-layer-setwin { display: none; }
        .install-success-skin .layui-layer-content { padding: 0; overflow: hidden; background: #fff; }
        .install-success-skin .layui-layer-btn {
            margin: 0; padding: 18px 22px 22px; text-align: center;
            background: #fff; border: 0;
        }
        .install-success-skin .layui-layer-btn a {
            height: 44px; line-height: 42px; margin: 0; padding: 0 30px;
            font-family: inherit; font-size: 14px; font-weight: 600; color: #fff;
            background: linear-gradient(180deg, #6366f1, #4338ca);
            border: 0; border-radius: var(--radius);
            box-shadow: 0 12px 24px -14px rgba(79, 70, 229, 0.8);
            transition: transform 0.18s, box-shadow 0.18s;
        }
        .install-success-skin .layui-layer-btn a:hover {
            transform: translateY(-1px);
            box-shadow: 0 16px 30px -14px rgba(79, 70, 229, 0.95);
        }

        .install-success-modal { color: var(--ink); }

        /* 头部：字体图标 + 标题 + 一行说明，全部居中 */
        .install-success-modal .modal-hero { position: relative; padding: 30px 22px 22px; text-align: center; }
        .install-success-modal .hero-icon { display: block; font-size: 42px; line-height: 1; color: var(--accent); }
        .install-success-modal .hero-title { margin-top: 14px; font-size: 19px; font-weight: 700; letter-spacing: -0.01em; }
        .install-success-modal .hero-sub { margin-top: 7px; font-size: 13px; line-height: 1.6; color: var(--muted); }
        .install-success-modal .modal-close {
            position: absolute; top: 12px; right: 12px;
            width: 30px; height: 30px; margin: 0; padding: 0;
            font-size: 16px; line-height: 30px; text-align: center; color: var(--muted);
            background: transparent; border: 0; border-radius: 50%;
            cursor: pointer; transition: color 0.18s, background 0.18s;
        }
        .install-success-modal .modal-close:hover { color: var(--ink); background: #f1f3f8; }

        /* 信息表：一张卡三行，标签在左、值在右，发丝线分隔 */
        .install-success-modal .modal-body {
            position: relative; padding: 0 22px;
            overflow: auto; overscroll-behavior: contain; -webkit-overflow-scrolling: touch;
        }
        .install-success-modal .info-grid {
            display: grid; overflow: hidden;
            background: #fcfcfe; border: 1px solid var(--line); border-radius: var(--radius);
        }
        .install-success-modal .info-item {
            display: grid; grid-template-columns: 74px minmax(0, 1fr);
            align-items: baseline; gap: 12px; padding: 12px 14px;
        }
        .install-success-modal .info-item + .info-item { border-top: 1px solid #eef0f6; }
        .install-success-modal .info-label { font-size: 12.5px; color: var(--muted); }
        .install-success-modal .info-value {
            min-width: 0; font-size: 13.5px; font-weight: 600; color: var(--ink);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Courier New", monospace;
            word-break: break-all; -webkit-user-select: all; user-select: all;
        }
        .install-success-modal .info-item.url .info-value { font-family: inherit; font-weight: 500; }
        .install-success-modal .info-value a { color: var(--accent-strong); text-decoration: none; }
        .install-success-modal .info-value a:hover { text-decoration: underline; }

        /* 脚注：一行小字，不加任何框 */
        .install-success-modal .tips {
            margin: 14px 0 0; font-size: 12px; line-height: 1.6; color: var(--muted); text-align: center;
        }
        @media (max-width: 640px) {
            .page { padding: 52px 20px 0; }
            .title { font-size: 28px; }
            .panel { margin-top: 26px; border-radius: 16px; }
            .section { padding: 22px 20px; }
            .env { grid-template-columns: 1fr; }
            .fields { grid-template-columns: 1fr; }
            .panel-foot { flex-direction: column-reverse; align-items: stretch; padding: 16px 20px 20px; }
            .foot-btns .btn { flex: 1; }
            .foot-hint { text-align: center; }
            .install-success-skin { border-radius: 16px; }
            .install-success-skin .layui-layer-btn { padding: 16px 18px 18px; }
            .install-success-skin .layui-layer-btn a { display: block; width: 100%; padding: 0; }

            .install-success-modal .modal-hero { padding: 24px 18px 18px; }
            .install-success-modal .hero-icon { font-size: 36px; }
            .install-success-modal .hero-title { margin-top: 12px; font-size: 17px; }
            .install-success-modal .hero-sub { font-size: 12.5px; }
            .install-success-modal .modal-body { padding: 0 18px; }
            .install-success-modal .info-item { grid-template-columns: 64px minmax(0, 1fr); gap: 10px; padding: 11px 12px; }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="page-head">
        <span class="eyebrow">步骤 <?php echo $step === 'intro' ? '1' : '2'; ?> / 2</span>
        <span class="version">v<?php echo htmlspecialchars($systemVersion); ?></span>
    </div>
    <h1 class="title"><?php echo $step === 'intro' ? '安装向导 - EMSHOP' : '配置安装信息 - EMSHOP'; ?></h1>
    <p class="lede"><?php
        echo $step === 'intro'
            ? '本向导会引导你完成数据库配置与管理员账号创建。'
            : '填写数据库连接信息并创建管理员账号，完成后系统将自动初始化数据表并写入 config.php。';
    ?></p>

    <div class="panel">
        <?php if (!empty($messages)): ?>
            <div class="alerts">
                <?php foreach ($messages as $m): ?>
                    <div class="msg <?php echo htmlspecialchars($m['type']); ?>"><?php echo htmlspecialchars($m['text']); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 'intro'): ?>
            <section class="section">
                <div class="section-head">
                    <div>
                        <h2>运行环境建议</h2>
                        <p>满足以下条件可获得更好的运行与安全体验</p>
                    </div>
                </div>
                <div class="env">
                    <div class="env-item">
                        <img class="env-badge" src="/content/static/img/php.svg" alt="PHP 8.2" width="65" height="20">
                        <p class="env-desc">建议PHP版本 8.2</p>
                    </div>
                    <div class="env-item">
                        <img class="env-badge" src="/content/static/img/mysql.svg" alt="MySQL 5.7" width="80" height="20">
                        <p class="env-desc">建议MySQL版本 5.7</p>
                    </div>
                </div>
                <p class="env-note">安装过程会写入 config.php 并初始化数据表，请确保网站目录权限为755。</p>
            </section>

            <div class="panel-foot">
                <span class="foot-hint">确认服务器满足以上要求后再继续</span>
                <div class="foot-btns">
                    <a class="btn btn-primary" href="?action=install">
                        开始安装
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13M13 6l6 6-6 6" /></svg>
                    </a>
                </div>
            </div>
        <?php else: ?>
            <form id="installForm" method="post" action="?action=install">
                <section class="section">
                    <div class="section-head">
                        <div>
                            <h2>数据库连接</h2>
                            <p>数据库需已创建，安装程序会写入数据表</p>
                        </div>
                    </div>
                    <div class="fields">
                        <div class="field">
                            <label for="dbHost" class="required">数据库地址</label>
                            <div class="control">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="7" x="3" y="3" rx="2" /><rect width="18" height="7" x="3" y="14" rx="2" /><path d="M7 6.5h.01M7 17.5h.01" /></svg>
                                <input id="dbHost" name="db[host]" value="<?php echo htmlspecialchars($dbHost); ?>" placeholder="127.0.0.1">
                            </div>
                        </div>
                        <div class="field">
                            <label for="dbPort" class="required">数据库端口</label>
                            <div class="control">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18" /></svg>
                                <input id="dbPort" name="db[port]" value="<?php echo htmlspecialchars($dbPort); ?>" placeholder="3306">
                            </div>
                        </div>
                        <div class="field">
                            <label for="dbName" class="required">数据库名</label>
                            <div class="control">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3" /><path d="M3 5v14a9 3 0 0 0 18 0V5" /><path d="M3 12a9 3 0 0 0 18 0" /></svg>
                                <input id="dbName" name="db[dbname]" value="<?php echo htmlspecialchars($dbName); ?>" placeholder="">
                            </div>
                        </div>
                        <div class="field">
                            <label for="dbPrefix" class="required">数据表前缀</label>
                            <div class="control">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12.6 2.6a2 2 0 0 0-1.4-.6H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4z" /><circle cx="7.5" cy="7.5" r=".6" fill="currentColor" /></svg>
                                <input id="dbPrefix" name="db[prefix]" value="<?php echo htmlspecialchars($dbPrefix); ?>" placeholder="em_">
                            </div>
                        </div>
                        <div class="field">
                            <label for="dbUser" class="required">数据库用户名</label>
                            <div class="control">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                                <input id="dbUser" name="db[username]" value="<?php echo htmlspecialchars($dbUser); ?>" placeholder="root">
                            </div>
                        </div>
                        <div class="field">
                            <label for="dbPass" class="required">数据库密码</label>
                            <div class="control">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                                <input id="dbPass" name="db[password]" value="<?php echo htmlspecialchars($dbPass); ?>" placeholder="数据库密码">
                            </div>
                        </div>
                    </div>
                </section>

                <section class="section">
                    <div class="section-head">
                        <div>
                            <h2>管理员账号</h2>
                            <p>用于登录后台，密码至少 6 位</p>
                        </div>
                    </div>
                    <div class="fields">
                        <div class="field">
                            <label for="adminUser" class="required">登录账号</label>
                            <div class="control">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2" /><circle cx="12" cy="7" r="4" /></svg>
                                <input id="adminUser" name="admin[username]" value="<?php echo htmlspecialchars($adminUsername); ?>" placeholder="admin">
                            </div>
                        </div>
                        <div class="field">
                            <label for="adminEmail">邮箱（选填）</label>
                            <div class="control">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2" /><path d="m22 7-9 5.7a2 2 0 0 1-2 0L2 7" /></svg>
                                <input id="adminEmail" name="admin[email]" value="<?php echo htmlspecialchars($adminEmail); ?>" placeholder="admin@example.com">
                            </div>
                        </div>
                        <div class="field">
                            <label for="adminPass" class="required">登录密码</label>
                            <div class="control">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" /><path d="M7 11V7a5 5 0 0 1 10 0v4" /></svg>
                                <input id="adminPass" name="admin[password]" value="" placeholder="至少 6 位，建议更长">
                            </div>
                        </div>
                        <div class="field">
                            <label for="adminPass2" class="required">确认密码</label>
                            <div class="control">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.7 9a1 1 0 0 1-.6 0C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.2-2.7a1.2 1.2 0 0 1 1.6 0C14.5 3.8 17 5 19 5a1 1 0 0 1 1 1z" /><path d="m9 12 2 2 4-4" /></svg>
                                <input id="adminPass2" name="admin[password2]" value="" placeholder="再次输入密码">
                            </div>
                        </div>
                    </div>
                </section>

                <div class="panel-foot">
                    <span class="foot-hint">安装将写入 config.php 并初始化数据表</span>
                    <div class="foot-btns">
                        <button class="btn btn-ghost" type="button" id="btnTestDb">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                            测试连接
                        </button>
                        <button class="btn btn-primary" type="submit" name="mode" value="install">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7" /></svg>
                            执行安装
                        </button>
                    </div>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>
<script src="/content/static/lib/jquery.min.3.5.1.js"></script>
<script src="/content/static/lib/layui-v2.13.5/layui/layui.js"></script>
<script src="/content/static/js/em-toast.js"></script>
<script>
// 调试用：URL 带 demo=1 时用假数据直接弹出成功弹窗（不落库、不写文件）
var INSTALL_DEMO = <?php echo $demo
    ? json_encode([
        'admin_url' => installer_admin_url(),
        'admin_username' => 'admin',
        'admin_password' => 'Emshop@2026',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    : 'null'; ?>;
layui.use(function () {
  var layer = layui.layer;
  var $form = $('#installForm');
  if ($form.length === 0) return;
  var $btn = $('#btnTestDb');
  var $btnInstall = $form.find('button[type="submit"][name="mode"][value="install"]');
  var testLoadingIndex = null;
  var installLoadingIndex = null;

  // 通知式浮动提示统一走公共组件 EmToast（样式见 /content/static/css/em-toast.css）
  var toast = EmToast.msg;

  function escapeHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function showInstallSuccessDialog(data) {
    var adminUrl = data.admin_url || '/admin/';
    var adminUser = data.admin_username || '';
    var adminPass = data.admin_password || '';
    var viewportWidth = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
    var viewportHeight = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
    var dialogWidth = viewportWidth <= 768 ? '92%' : Math.min(560, Math.max(400, viewportWidth - 40)) + 'px';
    var contentMaxHeight = Math.max(240, viewportHeight - 250);
    var html = ''
      + '<div class="install-success-modal">'
      + '  <div class="modal-hero">'
      + '    <button type="button" class="modal-close" aria-label="关闭"><i class="layui-icon layui-icon-close"></i></button>'
      + '    <i class="layui-icon layui-icon-ok-circle hero-icon"></i>'
      + '    <div class="hero-title">安装成功</div>'
      + '    <div class="hero-sub">系统已完成初始化，请使用以下信息登录后台</div>'
      + '  </div>'
      + '  <div class="modal-body" style="max-height:' + contentMaxHeight + 'px;">'
      + '    <div class="info-grid">'
      + '      <div class="info-item url">'
      + '        <div class="info-label">后台地址</div>'
      + '        <div class="info-value"><a href="' + escapeHtml(adminUrl) + '" target="_blank" rel="noopener">' + escapeHtml(adminUrl) + '</a></div>'
      + '      </div>'
      + '      <div class="info-item">'
      + '        <div class="info-label">登录账号</div>'
      + '        <div class="info-value">' + escapeHtml(adminUser) + '</div>'
      + '      </div>'
      + '      <div class="info-item">'
      + '        <div class="info-label">登录密码</div>'
      + '        <div class="info-value">' + escapeHtml(adminPass) + '</div>'
      + '      </div>'
      + '    </div>'
      + '    <div class="tips">请妥善保存以上信息，登录后建议立即修改密码</div>'
      + '  </div>'
      + '</div>';
    var layerIndex = layer.open({
      type: 1,
      title: false,
      skin: 'install-success-skin',
      area: [dialogWidth, 'auto'],
      content: html,
      closeBtn: 0,
      shade: [0.32, '#0f172a'],
      shadeClose: false,
      btnAlign: 'c',
      btn: ['进入后台'],
      success: function (layero, index) {
        $(layero).find('.modal-close').on('click', function () {
          layer.close(index);
        });
      },
      yes: function () {
        window.location.href = adminUrl;
      }
    });
  }

  $btn.on('click', function () {
    if ($btn.length === 0) return;

    $btn.prop('disabled', true);
    testLoadingIndex = EmToast.loading();
    $.ajax({
      url: '?action=test_db',
      method: 'POST',
      data: $form.serialize(),
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      var ok = res && res.code === 200;
      toast((res && res.msg) ? res.msg : (ok ? '连接成功' : '连接失败'), ok ? 'ok' : 'err');
    }).fail(function (xhr) {
      var text = xhr && xhr.responseJSON && xhr.responseJSON.msg
        ? xhr.responseJSON.msg
        : '请求失败：网络或服务异常';
      toast(text, 'err');
    }).always(function () {
      if (testLoadingIndex !== null) {
        EmToast.close(testLoadingIndex);
        testLoadingIndex = null;
      }
      $btn.prop('disabled', false);
    });
  });

  $form.on('submit', function (e) {
    e.preventDefault();
    var formData = $form.serializeArray();
    formData.push({ name: 'mode', value: 'install' });

    $btnInstall.prop('disabled', true);
    $btn.prop('disabled', true);
    installLoadingIndex = EmToast.loading(0.22);
    $.ajax({
      url: '?action=install',
      method: 'POST',
      data: $.param(formData),
      dataType: 'json',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).done(function (res) {
      var ok = res && res.code === 200;
      var message = (res && res.msg) ? res.msg : (ok ? '安装完成' : '安装失败');
      if (ok) {
        showInstallSuccessDialog((res && res.data) ? res.data : {});
      } else {
        toast(message, 'err');
      }
    }).fail(function (xhr) {
      var text = xhr && xhr.responseJSON && xhr.responseJSON.msg
        ? xhr.responseJSON.msg
        : '安装请求失败：网络或服务异常';
      toast(text, 'err');
    }).always(function () {
      if (installLoadingIndex !== null) {
        EmToast.close(installLoadingIndex);
        installLoadingIndex = null;
      }
      $btnInstall.prop('disabled', false);
      $btn.prop('disabled', false);
    });
  });

  // 演示模式：直接弹出成功弹窗，方便调样式
  if (INSTALL_DEMO) {
    showInstallSuccessDialog(INSTALL_DEMO);
  }
});
</script>
</body>
</html>
    <?php
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : '';
if (installer_is_installed()) {
    // 已安装：安装入口一律按 404 处理，不暴露安装目录，也不给重定向提示。
    http_response_code(404);
    exit;
}
// 第一步：不带参数展示介绍与运行环境建议
if ($action === '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        Response::error('非法请求');
    }
    installer_render([], [], 'intro');
}

// 第二步：GET ?action=install 展示配置表单（POST 则继续往下执行安装）
// 附加 demo=1 时为演示模式，页面加载后用假数据弹出成功弹窗，方便调试样式
if ($action === 'install' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    installer_render([], [], 'form', !empty($_GET['demo']));
}

if ($action !== 'install' && $action !== 'test_db') {
    Response::error('非法请求');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('非法请求');
}


$mode = (string) ($_POST['mode'] ?? 'test');
$db = $_POST['db'] ?? [];
$admin = $_POST['admin'] ?? [];
if (!is_array($db)) $db = [];
if (!is_array($admin)) $admin = [];

$dbClean = [
    'host' => trim((string) ($db['host'] ?? '127.0.0.1')),
    'port' => (int) ($db['port'] ?? 3306),
    'dbname' => trim((string) ($db['dbname'] ?? '')),
    'username' => trim((string) ($db['username'] ?? '')),
    'password' => (string) ($db['password'] ?? ''),
    // 安装固定使用 utf8mb4（不展示给用户，也不允许覆盖）
    'charset' => 'utf8mb4',
    'prefix' => trim((string) ($db['prefix'] ?? 'em_')),
];

$adminClean = [
    'username' => trim((string) ($admin['username'] ?? 'admin')),
    'email' => trim((string) ($admin['email'] ?? '')),
    'password' => (string) ($admin['password'] ?? ''),
    'password2' => (string) ($admin['password2'] ?? ''),
];

$messages = [];

$generatedConfig = installer_generate_config_php($dbClean);

// AJAX 测试数据库连接：返回 JSON，不刷新页面（只测账号连通性，不受表单必填项影响）
if ($action === 'test_db') {
    $test = installer_test_db_connection($dbClean);
    if ($test['ok']) {
        Response::success($test['message'], ['driver' => $test['driver']]);
    }
    Response::error($test['message'], ['driver' => $test['driver']]);
}

if ($mode === 'test') {
    $test = installer_test_db_connection($dbClean);
    installer_render(
        [['type' => $test['ok'] ? 'ok' : 'bad', 'text' => $test['message']]],
        ['db' => $dbClean, 'admin' => $adminClean],
        'form'
    );
}

// 表单校验：严格按页面上字段的展示顺序逐项校验，命中第一条即返回，不堆积多条提示
// 端口要区分「没填」和「填了但非法」，所以用原始输入判断（空串被 (int) 转成 0 后会丢失区别）
$portRaw = trim((string) ($db['port'] ?? ''));
// mbstring 不保证开启，缺失时退回字节长度（安装程序尽量不引入新的扩展依赖）
$adminPasswordLength = function_exists('mb_strlen')
    ? mb_strlen($adminClean['password'])
    : strlen($adminClean['password']);

$error = '';
if ($dbClean['host'] === '') {
    $error = '请填写数据库地址';
} elseif ($portRaw === '') {
    $error = '请填写数据库端口';
} elseif (!preg_match('/^\d+$/', $portRaw) || (int) $portRaw < 1 || (int) $portRaw > 65535) {
    $error = '数据库端口需为 1-65535 之间的数字';
} elseif ($dbClean['dbname'] === '') {
    $error = '请填写数据库名';
} elseif ($dbClean['prefix'] === '') {
    $error = '请填写数据表前缀';
} elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $dbClean['prefix'])) {
    $error = '数据表前缀只能包含字母、数字和下划线';
} elseif (strlen($dbClean['prefix']) > 20) {
    $error = '数据表前缀不能超过 20 个字符';
} elseif ($dbClean['username'] === '') {
    $error = '请填写数据库用户名';
} elseif ($dbClean['password'] === '') {
    $error = '请填写数据库密码';
} elseif ($adminClean['username'] === '') {
    $error = '请输入登录账号';
} elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $adminClean['username'])) {
    $error = '登录账号只能包含字母、数字和下划线';
} elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $adminClean['username'])) {
    $error = '登录账号长度需为 3-50 个字符';
} elseif ($adminClean['email'] !== '' && !filter_var($adminClean['email'], FILTER_VALIDATE_EMAIL)) {
    $error = '请输入正确的邮箱地址';
} elseif ($adminClean['password'] === '') {
    $error = '请输入登录密码';
} elseif ($adminPasswordLength < 6) {
    $error = '登录密码不能少于 6 位';
} elseif ($adminClean['password'] !== $adminClean['password2']) {
    $error = '两次密码输入不一致';
}

if ($error !== '') {
    installer_fail_response($action, [['type' => 'bad', 'text' => $error]], ['db' => $dbClean, 'admin' => $adminClean]);
}

$configPath = EM_ROOT . '/config.php';

// 连接测试（确保不会触发 Database 失败路径）
$test = installer_test_db_connection($dbClean);
if (!$test['ok']) {
    $messages[] = ['type' => 'bad', 'text' => $test['message']];
    installer_fail_response($action, $messages, ['db' => $dbClean, 'admin' => $adminClean]);
}

// 清理同前缀的残留表：安装只做「删表重建」，避免旧表被 IF NOT EXISTS 跳过、
// 导致最终结构停留在旧版本（半新半旧最难排查）。只动当前前缀的表，其它一律不碰。
$droppedTables = 0;
$existing = installer_fetch_tables($dbClean);
if (!$existing['ok']) {
    $messages[] = ['type' => 'bad', 'text' => '安装前读取数据表失败：' . (string) $existing['message']];
    installer_fail_response($action, $messages, ['db' => $dbClean, 'admin' => $adminClean]);
}
$staleTables = installer_filter_prefixed_tables((array) ($existing['tables'] ?? []), (string) $dbClean['prefix']);
if ($staleTables !== []) {
    $cleanup = installer_drop_tables($dbClean, $staleTables);
    if (!$cleanup['ok']) {
        $failed = (array) ($cleanup['failed'] ?? []);
        $detail = $failed === [] ? (string) $cleanup['message'] : implode('、', $failed);
        $messages[] = ['type' => 'bad', 'text' => '清理旧数据表失败：' . $detail . '（请确认数据库账号具备 DROP 权限）'];
        installer_fail_response($action, $messages, ['db' => $dbClean, 'admin' => $adminClean]);
    }
    $droppedTables = (int) $cleanup['dropped'];
}

// 清理之后再拍快照，作为安装失败时的回滚基线（没清理过就直接复用上面那次结果）
$snapshotBefore = $staleTables === [] ? $existing : installer_fetch_tables($dbClean);
if (!$snapshotBefore['ok']) {
    $messages[] = ['type' => 'bad', 'text' => '安装前读取表快照失败：' . (string) $snapshotBefore['message']];
    installer_fail_response($action, $messages, ['db' => $dbClean, 'admin' => $adminClean]);
}

// 切换到项目 Database：用内存 EM_CONFIG 启动 InstallService
// 安装调试开关：让 Database 在异常信息里附带 SQL/参数上下文（便于定位建表/建索引失败点）
define('EM_INSTALLER_DEBUG', true);
define('EM_CONFIG', [
    'db' => [
        'host' => $dbClean['host'],
        'port' => $dbClean['port'],
        'dbname' => $dbClean['dbname'],
        'username' => $dbClean['username'],
        'password' => $dbClean['password'],
        'charset' => 'utf8mb4',
        'prefix' => $dbClean['prefix'] ?: 'em_',
    ],
]);

try {
    $installer = new InstallService();
    $installer->setup([
        'site_url' => installer_detect_site_url(),
        'admin' => [
            'username' => $adminClean['username'],
            'email' => $adminClean['email'],
            'password' => $adminClean['password'],
        ],
    ]);
} catch (Throwable $e) {
    // 这里的 $e->getMessage() 可能已包含 SQL/params（来自 Database 的安装调试增强）
    $messages[] = ['type' => 'bad', 'text' => '安装执行失败：' . $e->getMessage()];

    // AJAX 下额外返回调试信息（不影响页面模式）
    $debug = [
        'exception' => get_class($e),
        'code' => (int) $e->getCode(),
    ];
    if ($e->getPrevious() instanceof Throwable) {
        $debug['previous_exception'] = get_class($e->getPrevious());
        $debug['previous_message'] = $e->getPrevious()->getMessage();
    }
    $trace = $e->getTraceAsString();
    if (is_string($trace) && $trace !== '') {
        // 避免返回过大
        $debug['trace'] = substr($trace, 0, 6000);
    }

    // 附加最近一次执行的 SQL 上下文（用于精准定位哪条建表/建索引失败）
    try {
        if (class_exists('Database') && method_exists('Database', 'lastSqlContext')) {
            $debug['sql_context'] = Database::lastSqlContext();
        }
    } catch (Throwable $ignored) {
        // ignore
    }

    $snapshotAfter = installer_fetch_tables($dbClean);
    if ($snapshotAfter['ok']) {
        $beforeMap = [];
        foreach ((array) ($snapshotBefore['tables'] ?? []) as $tableName) {
            $beforeMap[strtolower((string) $tableName)] = true;
        }
        $prefixLower = strtolower((string) ($dbClean['prefix'] ?? ''));
        $newTables = [];
        foreach ((array) ($snapshotAfter['tables'] ?? []) as $tableName) {
            $tableName = (string) $tableName;
            $tableKey = strtolower($tableName);
            if (isset($beforeMap[$tableKey])) {
                continue;
            }
            if ($prefixLower !== '' && strpos($tableKey, $prefixLower) !== 0) {
                continue;
            }
            $newTables[] = $tableName;
        }
        $rollback = installer_drop_tables($dbClean, $newTables);
        if ($rollback['ok']) {
            $messages[] = ['type' => 'warn', 'text' => '检测到安装中断，已自动清理本次新建表：' . (string) $rollback['dropped'] . ' 张'];
        } else {
            $failed = (array) ($rollback['failed'] ?? []);
            $failedText = $failed === [] ? '无' : implode(', ', $failed);
            $messages[] = ['type' => 'warn', 'text' => '自动清理部分失败，请手动检查表：' . $failedText];
        }
    } else {
        $messages[] = ['type' => 'warn', 'text' => '安装失败后无法读取表快照，请手动检查并清理本次新建表'];
    }
    if ($action === 'install' && installer_is_ajax_request()) {
        Response::error(installer_messages_text($messages), [
            'messages' => $messages,
            'debug' => $debug,
        ]);
    }
    installer_fail_response($action, $messages, ['db' => $dbClean, 'admin' => $adminClean]);
}

// 写 config.php（失败即中断，提示先修复环境后重试）
$written = @file_put_contents($configPath, $generatedConfig, LOCK_EX);
if ($written === false) {
    $messages[] = ['type' => 'bad', 'text' => '写入 config.php 失败：请先修复目录权限后重试安装'];
    installer_fail_response($action, $messages, ['db' => $dbClean, 'admin' => $adminClean]);
}

// 写安装锁（用于禁用安装入口）
//
// 必须检查写入结果。此前是 `@file_put_contents(...)` 直接忽略返回值 ——
// 目录不可写时锁没写成功，安装程序却照样汇报「安装完成」，
// 于是站点处于**无锁状态**：任何人都能再次打开 /install/ 重跑安装，
// 用自己的管理员账号覆盖整个站点（还会清库）。
$lockPayload = json_encode([
    'installed_at' => date('c'),
    'db' => [
        'host' => $dbClean['host'],
        'port' => $dbClean['port'],
        'dbname' => $dbClean['dbname'],
        'prefix' => $dbClean['prefix'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if (@file_put_contents(EM_INSTALL_LOCK, (string) $lockPayload, LOCK_EX) === false) {
    $messages[] = [
        'type' => 'bad',
        'text' => '写入安装锁 install/install.lock 失败。站点数据已初始化，但安装入口**仍处于开放状态** —— '
                . '请立即修复 install/ 目录写权限并手动创建该文件，否则任何人都能重跑安装程序并接管站点。',
    ];
    installer_fail_response($action, $messages, ['db' => $dbClean, 'admin' => $adminClean]);
}

// 跟授权服务器报一次「这台机器装好了」（服务端落在安装记录表里，代理端能看见）。
//
// **放在最后一步**：前面的步骤全过了才报，装失败的机器不该留一条安装记录。
// **绝不影响安装**：InstallReporter 内部把所有异常都吞了、超时压到 5 秒、不重试，
// 连不上就是这一次记录丢掉（细节和代价见那个类的注释）—— 所以这里不用判返回值。
InstallReporter::report([
    'version' => installer_system_version(),
    'site_url' => installer_detect_site_url(),
    'admin_username' => $adminClean['username'],
    'admin_email' => $adminClean['email'],
    'is_reinstall' => $droppedTables > 0,
]);

installer_success_response(
    $action,
    $droppedTables > 0
        ? sprintf('安装完成：已删除 %d 张旧表并重建数据结构，配置与管理员账号已重新写入。', $droppedTables)
        : '安装完成：已初始化数据库并写入安装锁。',
    ['db' => $dbClean, 'admin' => $adminClean],
    [
        'admin_url' => installer_admin_url(),
        'admin_username' => $adminClean['username'],
        'admin_password' => $adminClean['password'],
    ]
);

