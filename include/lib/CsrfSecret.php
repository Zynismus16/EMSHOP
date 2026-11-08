<?php

declare(strict_types=1);

/**
 * CSRF cookie 签名密钥的生成与持久化。
 *
 * 新装在安装时已把随机密钥写进 config.php 的 auth.secret；老站没有这一项，
 * 由本类在后台首次访问时生成并写回 config.php，此后所有请求共用同一密钥。
 * 这样 Csrf 只有「有密钥」一种模式，不再拿公开常量当签名密钥。
 */
final class CsrfSecret
{
    /** 本次请求内解析出的密钥 */
    private static ?string $secret = null;

    /** 是否已经解析过（用于区分「没解析」和「解析为空」） */
    private static bool $resolved = false;

    /**
     * 读取密钥；没有时返回空串，不写文件。
     */
    public static function get(): string
    {
        if (!self::$resolved) {
            self::$resolved = true;
            $value = (string) (EM_CONFIG['auth']['secret'] ?? EM_CONFIG['app']['secret'] ?? '');
            self::$secret = $value !== '' ? $value : null;
        }

        return self::$secret ?? '';
    }

    /**
     * 确保密钥存在；老站没有时生成并写回 config.php。
     *
     * 只在后台登录后的入口调用（admin/global.php 的 adminRequireLogin），
     * 保证公开页面不会触发文件写入。
     *
     * @return string 密钥；无法写入时返回空串
     */
    public static function ensure(): string
    {
        $secret = self::get();
        if ($secret !== '') {
            return $secret;
        }

        self::$secret = self::persistSecret();
        self::$resolved = true;

        return self::$secret ?? '';
    }

    /**
     * 生成随机密钥并写回 config.php。
     */
    private static function persistSecret(): ?string
    {
        $path = EM_ROOT . '/config.php';
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        // 锁放在系统临时目录：config.php 所在目录的写权限不一定有，但并发必须挡住
        $lockPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
            . 'emshop_csrf_secret_' . md5($path) . '.lock';
        $lock = @fopen($lockPath, 'c');
        if ($lock === false) {
            return null;
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                return null;
            }

            // 拿到锁后重新读一次：别的请求可能已经写入了
            $current = self::readConfig($path);
            $existing = is_array($current) ? (string) ($current['auth']['secret'] ?? '') : '';
            if ($existing !== '') {
                return $existing;
            }

            $source = @file_get_contents($path);
            if ($source === false) {
                return null;
            }

            $secret = bin2hex(random_bytes(32));
            $patched = self::injectSecret($source, $secret);
            if ($patched === null) {
                return null;
            }

            // 先确认改完仍是合法 PHP，避免写出无法解析的文件
            try {
                token_get_all($patched, TOKEN_PARSE);
            } catch (Throwable $e) {
                return null;
            }

            if (!self::replaceFile($path, $patched, $secret)) {
                error_log('[EMSHOP] 无法把 CSRF 密钥写入 config.php，cookie 兜底将停用：' . $path);
                return null;
            }

            return $secret;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 读取 config.php 的返回值（刷新 opcache，避免拿到旧内容）。
     *
     * @return array<string, mixed>|null
     */
    private static function readConfig(string $path): ?array
    {
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        clearstatcache(true, $path);

        try {
            $config = @include $path;
        } catch (Throwable $e) {
            return null;
        }

        return is_array($config) ? $config : null;
    }

    /**
     * 把 secret 插到 auth 数组里，保留原文件的格式、注释和换行风格。
     */
    private static function injectSecret(string $source, string $secret): ?string
    {
        $line = "'secret' => " . var_export($secret, true) . ',';

        // 锚点用 session_key（每次安装都会写），回退到 csrf_key。
        // 要求行首是空白 + 键名，不会命中注释行。
        foreach (['session_key', 'csrf_key'] as $anchor) {
            $pattern = '/^([ \t]*)([\'"]' . $anchor . '[\'"]\s*=>[^\r\n]*?)(\r?\n)/m';
            if (preg_match($pattern, $source, $m, PREG_OFFSET_CAPTURE)) {
                $indent = $m[1][0];
                $eol = $m[3][0];
                $end = $m[0][1] + strlen($m[0][0]);

                return substr($source, 0, $end) . $indent . $line . $eol . substr($source, $end);
            }
        }

        return null;
    }

    /**
     * 原子替换 config.php：临时文件验证通过后 rename，避免写一半把站点写坏。
     */
    private static function replaceFile(string $path, string $content, string $secret): bool
    {
        $dir = dirname($path);
        $tmp = @tempnam($dir, '.emcfg');
        if ($tmp === false) {
            return false;
        }

        $tmpDir = realpath(dirname($tmp));
        $targetDir = realpath($dir);
        if ($tmpDir === false || $targetDir === false || strcasecmp($tmpDir, $targetDir) !== 0) {
            @unlink($tmp);
            return false;
        }

        if (@file_put_contents($tmp, $content) !== strlen($content)) {
            @unlink($tmp);
            return false;
        }

        // 验证新内容能被解析，且 secret 确实落在 auth 数组里
        try {
            $check = @include $tmp;
        } catch (Throwable $e) {
            $check = null;
        }
        if (!is_array($check) || (string) ($check['auth']['secret'] ?? '') !== $secret) {
            @unlink($tmp);
            return false;
        }

        $mode = @fileperms($path);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }

        if ($mode !== false) {
            @chmod($path, $mode & 0777);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
        clearstatcache(true, $path);

        return true;
    }
}
