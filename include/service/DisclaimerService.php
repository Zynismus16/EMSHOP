<?php

declare(strict_types=1);

/**
 * 免责协议签署状态。
 *
 * 协议正文在后台布局 admin/view/index.php 中渲染；本类只负责版本号与签署记录，
 * 记录写入 config 表的 disclaimer_accepted 键（JSON），全站保留最近一次签署。
 */
final class DisclaimerService
{
    /**
     * 当前协议版本。
     *
     * 修改协议正文时必须同步递增；版本变化后，已签署用户会重新看到签署弹窗。
     */
    public const VERSION = '1';

    private const CONFIG_KEY = 'disclaimer_accepted';

    /**
     * 当前站点是否已签署当前版本的协议。
     */
    public static function accepted(): bool
    {
        $record = self::record();

        return $record !== null && (string) ($record['version'] ?? '') === self::VERSION;
    }

    /**
     * 读取最近一次签署记录。
     *
     * @return array<string, mixed>|null
     */
    public static function record(): ?array
    {
        $raw = Config::get(self::CONFIG_KEY, '');
        if ($raw === '') {
            return null;
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /**
     * 写入签署记录。
     */
    public static function accept(int $adminId, string $account, string $ip): bool
    {
        $payload = json_encode([
            'version'  => self::VERSION,
            'admin_id' => $adminId,
            'account'  => $account,
            'ip'       => $ip,
            'time'     => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Config::set(self::CONFIG_KEY, (string) $payload);
    }
}
