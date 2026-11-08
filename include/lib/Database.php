<?php

declare(strict_types=1);

/**
 * 数据库访问类。
 *
 * 优先使用 mysqli，若环境不支持则自动回退到 PDO MySQL。
 * 这样可以兼容更多 PHP 7.4 ~ 8.5 环境。
 */
final class Database
{
    /**
     * 建表 DDL 依赖 DATETIME DEFAULT CURRENT_TIMESTAMP，MySQL 5.6.5 起才支持。
     * 低于此版本（如 5.5）建表会报 Invalid default value，安装/升级流程据此拦截。
     */
    public const MIN_MYSQL_VERSION = '5.6.5';

    /**
     * @var array<string, mixed>
     */
    private static $config = [];

    /**
     * @var string|null
     */
    private static $driver = null;

    /**
     * @var mysqli|PDO|null
     */
    private static $connection = null;

    /**
     * 事务嵌套深度。在长进程（如 CLI worker）下，连接失效时只有不在事务中才能安全重连重试 ——
     * 事务中重连意味着锁/SAVEPOINT 状态全丢，应直接抛错让上层 rollBack。
     *
     * @var int
     */
    private static $transactionDepth = 0;

    /**
     * 嵌套层级 => 该层的 SAVEPOINT 名（下标 1 为真实事务，值为 null）。
     *
     * 嵌套 begin() 不再向 MySQL 再发一次 START TRANSACTION —— mysqli 下那会让
     * MySQL 隐式 COMMIT 掉外层事务，外层 rollBack() 随即退化成空操作。
     * 改用 SAVEPOINT 后，内层的 commit()/rollBack() 只作用到本层，
     * 最终是否落库由最外层决定。
     *
     * @var array<int, string|null>
     */
    private static $savepoints = [];

    /**
     * 当前事务内已生成的 SAVEPOINT 序号，保证同一事务内名字不重复。
     */
    private static $savepointSeq = 0;

    /**
     * 记录最近一次执行的 SQL 上下文（用于安装器定位）。
     *
     * @var array<string, mixed>
     */
    private static $lastSqlContext = [];

    /**
     * 安装器/调试模式下：把 SQL 上下文附加到异常信息里，方便定位失败语句。
     *
     * 注意：**不能**用 PHP_SELF / SCRIPT_NAME 判断。它们受请求路径影响
     * （如 /index.php/install/xxx 的 PATH_INFO），任意游客都能借此把全站
     * 切成「异常里附带 SQL 与参数」模式，等于给攻击者一个 SQL 结构泄露开关。
     * 这里改用 SCRIPT_FILENAME —— 它由 SAPI 给出（真实执行的脚本文件），
     * 不随 URL 变化。
     */
    private static function shouldAttachSqlToError(): bool
    {
        if (defined('EM_INSTALLER_DEBUG') && EM_INSTALLER_DEBUG) {
            return true;
        }

        $scriptFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($scriptFile === '') {
            return false;
        }
        $real = realpath($scriptFile);
        if ($real === false) {
            return false;
        }

        $installDir = str_replace('\\', '/', EM_ROOT) . '/install/';
        return strpos(str_replace('\\', '/', $real), $installDir) === 0;
    }

    /**
     * 脱敏参数，避免密码/密钥/令牌进入异常消息或日志。
     *
     * 两级处理：
     *   1) 键名命中敏感关键字 → 整个值打码（含嵌套数组，如 insert() 传的 data）
     *   2) 位置参数（int 键）无从按键名判断，改按值特征兜底：密码哈希、
     *      JWT、长 hex/base64 串一律打码 —— 它们的排障价值低于泄露代价
     *
     * @param array<string, mixed> $params
     */
    private static function maskParams(array $params): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $out[$k] = self::maskParams($v);
                continue;
            }

            $key = is_string($k) ? strtolower($k) : '';
            if ($key !== '' && self::isSensitiveKey($key)) {
                $out[$k] = '***';
                continue;
            }

            $out[$k] = self::maskSensitiveValue($v);
        }
        return $out;
    }

    /**
     * 键名是否命中敏感关键字。
     */
    private static function isSensitiveKey(string $key): bool
    {
        static $needles = [
            'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey',
            'private_key', 'app_secret', 'credential', 'signature', 'sign',
        ];
        foreach ($needles as $needle) {
            if (strpos($key, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 按值特征兜底脱敏：密码哈希、JWT、长 hex / 长随机串。
     *
     * @param mixed $value
     * @return mixed
     */
    private static function maskSensitiveValue($value)
    {
        if (!is_string($value)) {
            return $value;
        }
        $len = strlen($value);
        if ($len < 32) {
            return $value;
        }

        // bcrypt / argon2 哈希
        if (preg_match('/^\$(?:2[aby]|argon2)[\x21-\x7e]{20,}$/', $value) === 1) {
            return '***';
        }
        // JWT
        if (strpos($value, 'eyJ') === 0 && strpos($value, '.') !== false) {
            return '***';
        }
        // 32 位以上纯 hex（md5 / sha1 / sha256 / 各类签名）
        if (preg_match('/^[A-Fa-f0-9]{32,}$/', $value) === 1) {
            return '***';
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function buildSqlDebug(string $sql, array $params = []): array
    {
        // 避免返回超大 payload（比如长文本/HTML）
        $maxLen = 2000;
        $sqlTrim = (string) preg_replace('/\s+/', ' ', trim($sql));
        if (strlen($sqlTrim) > $maxLen) {
            $sqlTrim = substr($sqlTrim, 0, $maxLen) . '...';
        }

        $paramsMasked = self::maskParams($params);
        // 也对单个参数做简单截断
        foreach ($paramsMasked as $k => $v) {
            if (is_string($v) && strlen($v) > 300) {
                $paramsMasked[$k] = substr($v, 0, 300) . '...';
            }
        }

        $ctx = [
            'driver' => self::$driver ?? 'unknown',
            'sql' => $sqlTrim,
            'params' => $paramsMasked,
        ];
        self::$lastSqlContext = $ctx;
        return $ctx;
    }

    /**
     * 返回最近一次执行的 SQL 上下文（用于安装器输出）。
     *
     * @return array<string, mixed>
     */
    public static function lastSqlContext(): array
    {
        return self::$lastSqlContext;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function throwSqlContextException(string $operation, Throwable $e, string $sql, array $params = []): void
    {
        if (!self::shouldAttachSqlToError()) {
            throw $e;
        }

        $debug = self::buildSqlDebug($sql, $params);
        $extra = '';

        // PDOException 的 errorInfo 更贴近真实原因
        if ($e instanceof PDOException && property_exists($e, 'errorInfo') && is_array($e->errorInfo)) {
            $sqlState = (string) ($e->errorInfo[0] ?? '');
            $driverErrno = (string) ($e->errorInfo[1] ?? '');
            $driverMsg = (string) ($e->errorInfo[2] ?? '');
            $extra = " | PDO errorInfo: [{$sqlState}, {$driverErrno}, {$driverMsg}]";
        }

        $msg = sprintf(
            '%s 失败：%s%s | driver=%s | SQL=%s | params=%s',
            $operation,
            $e->getMessage(),
            $extra,
            (string) ($debug['driver'] ?? 'unknown'),
            (string) ($debug['sql'] ?? ''),
            json_encode($debug['params'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        throw new RuntimeException($msg, (int) $e->getCode(), $e);
    }

    /**
     * 返回数据库配置。
     *
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        if (self::$config !== []) {
            return self::$config;
        }

        self::$config = EM_CONFIG['db'];
        // 环境变量覆盖（WSL 等环境下数据库 host 可能不同）
        $envHost = getenv('EM_DB_HOST');
        if ($envHost !== false && $envHost !== '') {
            self::$config['host'] = $envHost;
        }
        return self::$config;
    }

    /**
     * 返回表前缀。
     */
    public static function prefix(): string
    {
        return (string) self::config()['prefix'];
    }

    /**
     * 返回当前使用的驱动名称。
     */
    public static function driver(): string
    {
        if (self::$driver !== null) {
            return self::$driver;
        }

        if (extension_loaded('mysqli')) {
            self::$driver = 'mysqli';
            return self::$driver;
        }

        if (extension_loaded('pdo_mysql')) {
            self::$driver = 'pdo';
            return self::$driver;
        }

        throw new RuntimeException('当前 PHP 环境既不支持 mysqli，也不支持 pdo_mysql，无法连接数据库');
    }

    /**
     * 返回 MySQL/MariaDB 服务端版本号（如 "5.5.62"、"8.0.36"、"10.4.28-MariaDB"）。
     *
     * 用 `SELECT VERSION()` 而非连接句柄的 `server_info`：MariaDB 在握手阶段会给
     * 老客户端伪造一个 "5.5.5-" 前缀（兼容性补丁），`server_info` 拿到的是带前缀的
     * 假版本，会干扰版本判断；`SELECT VERSION()` 返回真实版本号。
     *
     * @return string 版本号；查询失败时返回空串
     */
    public static function serverVersion(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }

        try {
            $row = self::fetchOne('SELECT VERSION() AS `v`');
            $version = (string) ($row['v'] ?? '');
        } catch (Throwable $e) {
            $version = '';
        }

        return $version;
    }

    /**
     * 判断当前数据库是否满足建表所需的最低 MySQL 版本。
     *
     * 建表 DDL 用到 DATETIME DEFAULT CURRENT_TIMESTAMP，MySQL 5.6.5 起才支持。
     * MariaDB 已支持该特性（本项目要求的 10.3+ 必然满足），故对 MariaDB 一律视为兼容。
     * 版本探测失败（空串）视为兼容，不阻断安装/升级，交给后续建表报错兜底。
     *
     * @return bool
     */
    public static function mysqlVersionOk(): bool
    {
        $version = self::serverVersion();
        if ($version === '') {
            return true;
        }
        if (stripos($version, 'mariadb') !== false) {
            return true;
        }
        return version_compare($version, self::MIN_MYSQL_VERSION, '>=');
    }

    /**
     * 获取数据库连接。
     *
     * @param bool $withoutDatabase 为 true 时只连接到数据库服务，不指定 dbname
     * @return mysqli|PDO
     */
    public static function connect(bool $withoutDatabase = false)
    {
        if (!$withoutDatabase && self::$connection !== null) {
            return self::$connection;
        }

        $driver = self::driver();
        if ($driver === 'mysqli') {
            $connection = self::createMysqliConnection($withoutDatabase);
        } else {
            $connection = self::createPdoConnection($withoutDatabase);
        }

        if (!$withoutDatabase) {
            self::$connection = $connection;
        }

        return $connection;
    }

    /**
     * 执行查询并返回一行结果。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $rows = self::query($sql, $params);
        if ($rows === []) {
            return null;
        }

        return $rows[0];
    }

    /**
     * 按主键查单条记录（自动加前缀）。
     *
     * @param string $table 表名（不含前缀）
     * @param int    $id    主键 id
     * @return array<string, mixed>|null
     */
    public static function find(string $table, int $id): ?array
    {
        if ($id <= 0) return null;
        $sql = 'SELECT * FROM `' . self::prefix() . $table . '` WHERE `id` = ? LIMIT 1';
        return self::fetchOne($sql, [$id]);
    }

    /**
     * 执行查询并返回全部结果。
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public static function query(string $sql, array $params = []): array
    {
        self::buildSqlDebug($sql, $params);
        try {
            return self::withReconnectRetry(function () use ($sql, $params) {
                if (self::driver() === 'mysqli') {
                    $stmt = self::prepareMysqli($sql, $params);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $rows = [];

                    if ($result !== false) {
                        $rows = $result->fetch_all(MYSQLI_ASSOC);
                        $result->free();
                    }

                    $stmt->close();
                    return $rows;
                }

                /** @var PDO $pdo */
                $pdo = self::connect();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                return is_array($rows) ? $rows : [];
            });
        } catch (Throwable $e) {
            self::throwSqlContextException('query()', $e, $sql, $params);
            return [];
        }
    }

    /**
     * 执行写入、更新、删除语句。
     *
     * @param array<string, mixed> $params
     */
    public static function execute(string $sql, array $params = []): int
    {
        self::buildSqlDebug($sql, $params);
        try {
            return self::withReconnectRetry(function () use ($sql, $params) {
                if (self::driver() === 'mysqli') {
                    $stmt = self::prepareMysqli($sql, $params);
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();
                    return $affected;
                }

                /** @var PDO $pdo */
                $pdo = self::connect();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return (int) $stmt->rowCount();
            });
        } catch (Throwable $e) {
            self::throwSqlContextException('execute()', $e, $sql, $params);
            return 0;
        }
    }

    /**
     * 执行不带绑定参数的原始 SQL。
     */
    public static function statement(string $sql, bool $withoutDatabase = false): bool
    {
        self::buildSqlDebug($sql, []);
        // $withoutDatabase 时连的是临时无库连接，没必要走重连逻辑
        if ($withoutDatabase) {
            try {
                if (self::driver() === 'mysqli') {
                    return (bool) self::connect(true)->query($sql);
                }
                return self::connect(true)->exec($sql) !== false;
            } catch (Throwable $e) {
                self::throwSqlContextException('statement(withoutDatabase)', $e, $sql, []);
                return false;
            }
        }

        try {
            return self::withReconnectRetry(function () use ($sql) {
                if (self::driver() === 'mysqli') {
                    return (bool) self::connect()->query($sql);
                }
                return self::connect()->exec($sql) !== false;
            });
        } catch (Throwable $e) {
            self::throwSqlContextException('statement()', $e, $sql, []);
            return false;
        }
    }

    /**
     * 插入数据到指定表。
     *
     * @param string $table 不带前缀的表名
     * @param array<string, mixed> $data 字段名 => 值
     * @return int 返回插入的自增 ID
     */
    public static function insert(string $table, array $data): int
    {
        if ($data === []) {
            return 0;
        }

        $table = self::prefix() . $table;
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        $values = array_values($data);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            '`' . implode('`, `', $columns) . '`',
            implode(', ', $placeholders)
        );

        // INSERT 返回 lastInsertId，UPDATE/DELETE 返回 affected rows
        // 传具名 $data（而非原来的 ['__values' => $values]）—— 后者会让 maskParams
        // 看不到键名，密码类字段的明文就会被写进异常消息与 lastSqlContext。
        self::buildSqlDebug($sql, $data);
        try {
            return self::withReconnectRetry(function () use ($sql, $values) {
                if (self::driver() === 'mysqli') {
                    /** @var mysqli $conn */
                    $conn = self::connect();
                    $types = '';
                    foreach ($values as $v) {
                        $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
                    }
                    $refs = [];
                    $refs[] = &$types;
                    $localValues = $values;          // 副本：保证 retry 时 references 仍指向有效变量
                    foreach ($localValues as $i => $v) {
                        $refs[] = &$localValues[$i];
                    }
                    $stmt = $conn->prepare($sql);
                    if (count($refs) > 1) {
                        call_user_func_array([$stmt, 'bind_param'], $refs);
                    }
                    $stmt->execute();
                    $id = (int) $stmt->insert_id;
                    $stmt->close();
                    return $id;
                }

                /** @var PDO $pdo */
                $pdo = self::connect();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
                return (int) $pdo->lastInsertId();
            });
        } catch (Throwable $e) {
            // 传具名 $data，让 maskParams 能按字段名识别 password / token 等敏感列；
            // 传 ['__values' => $values] 会丢掉键名，短密钥（如 40 字符以内的签名）
            // 就绕过了值特征兜底，明文进异常消息与日志。
            self::throwSqlContextException('insert()', $e, $sql, $data);
            return 0;
        }
    }

    /**
     * 更新指定表的数据。
     *
     * @param string $table 不带前缀的表名
     * @param array<string, mixed> $data 字段名 => 新值
     * @param int|array|string $where 主键值（直接是 ID）、或 WHERE 条件字符串、或 WHERE 条件数组 ['id' => ?]
     * @param array<int, mixed> $whereParams 当 $where 为字符串时的绑定参数
     * @return int 受影响的行数
     */
    public static function update(string $table, array $data, $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }

        $table = self::prefix() . $table;
        $sets = [];
        $params = [];

        foreach ($data as $key => $value) {
            $sets[] = "`{$key}` = ?";
            $params[] = $value;
        }

        // 处理 WHERE 条件
        if (is_int($where) || is_string($where) && preg_match('/^\d+$/', (string) $where)) {
            // 纯数字 ID
            $whereCond = '`id` = ?';
            $params[] = (int) $where;
        } elseif (is_string($where) && $where !== '') {
            // 字符串条件只允许「代码编写、带 ? 占位符」的写法。
            // 调用方如果把未过滤的输入拼进 WHERE（如 $id = "0 OR 1=1"），
            // 这里直接拒绝，避免静默更新全表。
            if (strpos($where, '?') === false
                || preg_match('/[;\'"#\x00]/', $where)
                || strpos($where, '--') !== false
                || strpos($where, '/*') !== false
                || strpos($where, '*/') !== false
            ) {
                throw new InvalidArgumentException('update() 的字符串 $where 必须使用 ? 占位符，且不能包含引号、注释或分号');
            }
            $whereCond = $where;
            foreach ($whereParams as $wp) {
                $params[] = $wp;
            }
        } elseif (is_array($where) && $where !== []) {
            // 数组条件，如 ['id' => 5, 'status' => 1]
            $conds = [];
            foreach ($where as $k => $v) {
                $conds[] = "`{$k}` = ?";
                $params[] = $v;
            }
            $whereCond = implode(' AND ', $conds);
        } else {
            throw new InvalidArgumentException('update() 的 $where 参数无效');
        }

        $sql = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $sets), $whereCond);
        return self::execute($sql, $params);
    }

    /**
     * 开启事务；嵌套调用时在当前事务内打 SAVEPOINT。
     *
     * 支持嵌套是必需的：模型层（如 UserBalanceLogModel）与调用它的 service/controller
     * 各自都会 begin()，若嵌套时直接再发一次 START TRANSACTION，mysqli 下 MySQL 会
     * 隐式 COMMIT 掉外层事务，此后外层的 rollBack() 回滚不了任何东西 —— 资金类操作
     * 会变成「扣了钱但记录没写、还回滚不掉」。
     *
     * 维护 $transactionDepth：事务中连接断开时不能透明重连（事务状态会丢），
     * withReconnectRetry 会感知此计数并跳过重试，让上层 catch + rollBack。
     */
    public static function begin(): void
    {
        // 最外层：开真实事务
        if (self::$transactionDepth === 0) {
            if (self::driver() === 'mysqli') {
                self::connect()->begin_transaction();
            } else {
                self::connect()->beginTransaction();
            }
            self::$transactionDepth = 1;
            self::$savepoints = [1 => null];
            self::$savepointSeq = 0;
            return;
        }

        // 嵌套层：打 SAVEPOINT。用 statement() 而非 execute()——
        // MySQL 不允许 SAVEPOINT 走服务端预处理，而 mysqli 的 execute() 走的是预处理协议。
        $name = 'em_sp_' . (++self::$savepointSeq);
        self::statement('SAVEPOINT ' . $name);

        // statement() 失败会抛异常，此时上面两行不会执行，事务状态保持一致
        self::$transactionDepth++;
        self::$savepoints[self::$transactionDepth] = $name;
    }

    /**
     * 提交事务。嵌套层只释放本层 SAVEPOINT，是否真正落库由最外层 commit() 决定。
     */
    public static function commit(): void
    {
        // 没有处于事务中：忽略（此前会向连接发一条裸 COMMIT，PDO 下还会抛异常）
        if (self::$transactionDepth === 0) {
            return;
        }

        // 最外层：真正提交
        if (self::$transactionDepth === 1) {
            try {
                self::connect()->commit();
            } finally {
                self::resetTransactionState();
            }
            return;
        }

        // 嵌套层：释放本层 SAVEPOINT
        $name = self::$savepoints[self::$transactionDepth] ?? null;
        if ($name !== null) {
            self::statement('RELEASE SAVEPOINT ' . $name);
        }
        unset(self::$savepoints[self::$transactionDepth]);
        self::$transactionDepth--;
    }

    /**
     * 回滚事务。嵌套层只回滚到本层 SAVEPOINT，整体去留交给上层判断。
     */
    public static function rollBack(): void
    {
        if (self::$transactionDepth === 0) {
            return;
        }

        try {
            if (self::$transactionDepth === 1) {
                self::connect()->rollBack();
            } else {
                $name = self::$savepoints[self::$transactionDepth] ?? null;
                if ($name !== null) {
                    self::statement('ROLLBACK TO SAVEPOINT ' . $name);
                    self::statement('RELEASE SAVEPOINT ' . $name);
                }
            }
        } finally {
            // 即使底层抛错也要复位计数，避免后续查询误以为还在事务里而拒绝重连
            if (self::$transactionDepth === 1) {
                self::resetTransactionState();
            } else {
                unset(self::$savepoints[self::$transactionDepth]);
                self::$transactionDepth--;
            }
        }
    }

    /**
     * 复位事务状态。最外层提交/回滚后调用——MySQL 会一并销毁事务内所有 SAVEPOINT。
     */
    private static function resetTransactionState(): void
    {
        self::$transactionDepth = 0;
        self::$savepoints = [];
        self::$savepointSeq = 0;
    }

    /**
     * 当前是否处于事务中（含嵌套层）。
     */
    public static function inTransaction(): bool
    {
        return self::$transactionDepth > 0;
    }

    /**
     * 返回底层原始连接，仅在必须时使用。
     *
     * @param bool $withoutDatabase
     * @return mysqli|PDO
     */
    public static function rawConnection(bool $withoutDatabase = false)
    {
        return self::connect($withoutDatabase);
    }

    /**
     * 创建 mysqli 连接。
     *
     * @return mysqli
     */
    private static function createMysqliConnection(bool $withoutDatabase): mysqli
    {
        $config = self::config();
        $database = $withoutDatabase ? '' : (string) $config['dbname'];

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $mysqli = mysqli_init();
        try{
            $mysqli->real_connect(
                (string) $config['host'],
                (string) $config['username'],
                (string) $config['password'],
                $database,
                (int) $config['port']
            );
        } catch (Throwable $e) {
            // 连接失败的原始异常里含数据库主机、用户名、库名，而 Emmsg::error 会把
            // 异常消息 + **绝对文件路径 + 行号**直接渲染在错误页上（见 Emmsg::render）。
            // 数据库一挂，任何访客都能看到这些。真实原因只写 PHP 错误日志。
            error_log('[EMSHOP] 数据库连接失败：' . $e->getMessage());

            if (PHP_SAPI === 'cli') {
                throw new RuntimeException('数据库连接失败，请检查数据库配置', 0, $e);
            }
            Emmsg::error('数据库连接失败', '请检查数据库配置，或联系技术支持');
        }

        $mysqli->set_charset((string) $config['charset']);

        return $mysqli;
    }

    /**
     * 创建 PDO 连接。
     *
     * @return PDO
     */
    private static function createPdoConnection(bool $withoutDatabase): PDO
    {
        $config = self::config();
        $dsn = sprintf(
            'mysql:host=%s;port=%d;%scharset=%s',
            $config['host'],
            (int) $config['port'],
            $withoutDatabase ? '' : 'dbname=' . $config['dbname'] . ';',
            $config['charset']
        );

        try {
            return new PDO($dsn, (string) $config['username'], (string) $config['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (Throwable $e) {
            // 同 mysqli 路径：原始异常含主机 / 用户名 / 库名，未捕获时在开启
            // display_errors 的环境会连绝对路径一起显示给访客。
            // 这里记真实原因，抛一个不含敏感信息的异常，保持「失败即抛错」的原有契约。
            error_log('[EMSHOP] 数据库连接失败：' . $e->getMessage());
            throw new RuntimeException('数据库连接失败，请检查数据库配置', 0, $e);
        }
    }

    /**
     * mysqli 只支持问号占位符，因此需要把命名参数转换掉。
     *
     * @param array<string, mixed> $params
     * @return mysqli_stmt
     */
    private static function prepareMysqli(string $sql, array $params): mysqli_stmt
    {
        $normalizedSql = self::convertNamedToQuestion($sql, $params);
        $stmt = self::connect()->prepare($normalizedSql);

        if ($params !== []) {
            $values = array_values($params);
            $types = '';
            foreach ($values as $value) {
                if (is_int($value)) {
                    $types .= 'i';
                } elseif (is_float($value)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
            }

            $references = [];
            $references[] = $types;
            foreach ($values as $index => $value) {
                $references[] = &$values[$index];
            }

            call_user_func_array([$stmt, 'bind_param'], $references);
        }

        return $stmt;
    }

    /**
     * 把 :name 占位符转换成 ?，并按出现顺序重排参数。
     *
     * @param array<string, mixed> $params
     */
    private static function convertNamedToQuestion(string $sql, array &$params): string
    {
        $ordered = [];
        $convertedSql = preg_replace_callback('/:[a-zA-Z_][a-zA-Z0-9_]*/', function (array $matches) use (&$ordered, $params) {
            $name = substr($matches[0], 1);
            $ordered[] = array_key_exists($name, $params) ? $params[$name] : null;
            return '?';
        }, $sql);

        // 只有实际替换了命名占位符时才覆盖 params
        if ($ordered !== []) {
            $params = $ordered;
        }
        return $convertedSql === null ? $sql : $convertedSql;
    }

    /**
     * 长进程下（如 CLI 常驻 worker）连接被服务端 wait_timeout 断开是常态。
     * 这里识别"连接已死"类错误：mysqli/PDO 的 errno 2006 / 2013。
     */
    private static function isConnectionLost(Throwable $e): bool
    {
        $code = (int) $e->getCode();
        if ($code === 2006 || $code === 2013) return true;
        // PDOException：errorCode() 返回 SQLSTATE，真实 driver errno 在 errorInfo[1]
        if ($e instanceof PDOException && property_exists($e, 'errorInfo') && is_array($e->errorInfo)) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($driverCode === 2006 || $driverCode === 2013) return true;
        }
        // 兜底：看消息文本（部分场景 errno 是 0 但消息明确）
        $msg = $e->getMessage();
        return stripos($msg, 'gone away') !== false
            || stripos($msg, 'Lost connection') !== false;
    }

    /**
     * 是否为唯一键冲突（MySQL errno 1062）。
     *
     * 用于把「唯一索引挡住了重复写入」识别成幂等成功，而不是当成错误抛出去。
     * 三种情况都要覆盖：
     *   - mysqli  ：MYSQLI_REPORT_STRICT 下抛 mysqli_sql_exception，code 就是 1062
     *   - PDO     ：抛 PDOException，但 getCode() 返回 SQLSTATE 字符串 '23000'，
     *               真实 errno 在 errorInfo[1] —— 只看 getCode() 会漏判
     *   - 安装器环境：throwSqlContextException 会把上面两种包成 RuntimeException，
     *               需要顺着 getPrevious() 往下找
     */
    public static function isDuplicateKeyError(Throwable $e): bool
    {
        if ($e instanceof mysqli_sql_exception) {
            return (int) $e->getCode() === 1062;
        }

        if ($e instanceof PDOException && property_exists($e, 'errorInfo') && is_array($e->errorInfo)) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return true;
            }
        }

        // 包装层：递归找根因
        $prev = $e->getPrevious();
        if ($prev !== null && $prev !== $e) {
            return self::isDuplicateKeyError($prev);
        }

        // 最后一层兜底：消息文本（部分驱动/包装后 errno 丢失，但消息明确）
        $msg = $e->getMessage();
        return stripos($msg, 'Duplicate entry') !== false
            || stripos($msg, '1062') !== false;
    }

    /**
     * 通用包装：调用 $fn 执行查询；如果撞上连接已死，丢弃旧连接 + 重连 + 重试一次。
     * 事务进行中（$transactionDepth > 0）时不重试 —— 事务状态会随旧连接一起丢，
     * 透明重试只会让上层基于"事务已生效"的假设崩盘，必须抛错让上层 rollBack。
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private static function withReconnectRetry(callable $fn)
    {
        try {
            return $fn();
        } catch (Throwable $e) {
            if (self::$transactionDepth > 0 || !self::isConnectionLost($e)) {
                throw $e;
            }
            // 丢掉死连接，下次 connect() 会重新建立
            self::$connection = null;
            return $fn();
        }
    }

    /**
     * 判断表字段是否已存在（用于兼容未跑迁移的老库）。
     */
    public static function columnExists(string $table, string $column): bool
    {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $table);
        $column = preg_replace('/[^a-zA-Z0-9_]/', '', $column);
        if ($table === '' || $column === '') {
            return false;
        }

        $dbName = (string) (self::config()['dbname'] ?? '');
        if ($dbName === '') {
            return false;
        }

        $row = self::fetchOne(
            'SELECT 1 AS ok FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$dbName, $table, $column]
        );

        return $row !== null;
    }
}
