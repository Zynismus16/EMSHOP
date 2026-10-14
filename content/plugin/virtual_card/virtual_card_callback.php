<?php
/**
 * 虚拟商品（自动发货）插件 — 生命周期回调
 *
 * callback_init(): 启用插件时执行，创建卡密库存表
 * callback_rm():   删除插件时执行，清理数据
 * callback_up():   更新插件时执行，处理表结构变更
 */
defined('EM_ROOT') || exit('Access Denied');


// 启用插件时执行：创建所需数据库表
function callback_init()
{
    $sqlFile = __DIR__ . '/install.sql';
    if (!is_file($sqlFile)) {
        return;
    }

    $content = file_get_contents($sqlFile);
    if ($content === false) {
        return;
    }

    // 按分号+换行拆分 SQL 语句，逐条执行
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $content);
    foreach ($statements as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '' || str_starts_with($stmt, '--')) {
            continue;
        }
        // 过滤注释行
        $lines = preg_split('/\r?\n/', $stmt);
        $filtered = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && !str_starts_with($trimmed, '--')) {
                $filtered[] = $line;
            }
        }
        $sql = trim(implode("\n", $filtered));
        if ($sql !== '') {
            Database::statement($sql);
        }
    }

    // 老库补索引：install.sql 用的是 CREATE TABLE IF NOT EXISTS，表已存在时不会补列/补索引，
    // 而「已删订单」查询没有这个索引会慢到不可用（详见 card_actions.php 里的说明）。
    // 索引不存在才建，存在则跳过 —— 本函数由插件管理页每次「启用」触发，必须幂等。
    $prefix = Database::prefix();
    $table  = $prefix . 'goods_virtual_card';
    $index  = 'idx_goods_order_sold';
    $has = Database::fetchOne(
        "SELECT 1 AS ok FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1",
        [$table, $index]
    );
    if (!$has) {
        Database::statement("ALTER TABLE `{$table}` ADD INDEX `{$index}` (`goods_id`, `order_id`, `sold_at`, `status`)");
    }
}

// 删除插件时执行：清理插件数据
function callback_rm()
{
    // 删除卡密库存表（谨慎：会丢失所有卡密数据）
    // $prefix = Database::prefix();
    // Database::statement("DROP TABLE IF EXISTS `{$prefix}goods_virtual_card`");
}

// 更新插件时执行：处理表结构升级
function callback_update()
{
    CliServer::markPluginChanged('virtual_card');
    // 新版本仅支持全新库安装，不再执行老库升级补丁
}

