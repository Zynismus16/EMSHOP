<?php
declare(strict_types=1);
final class InstallService
{
    public function setup(array $options = []): void
    {
        $config = Database::config();
        $prefix = Database::prefix();
        $siteUrl = rtrim(trim((string) ($options['site_url'] ?? '')), '/');
        if ($siteUrl !== '') {
            $parts = @parse_url($siteUrl);
            $scheme = strtolower((string) (is_array($parts) ? ($parts['scheme'] ?? '') : ''));
            $host = trim((string) (is_array($parts) ? ($parts['host'] ?? '') : ''));
            $port = (int) (is_array($parts) ? ($parts['port'] ?? 0) : 0);
            if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
                $siteUrl = '';
            } else {
                $siteUrl = $scheme . '://' . $host . ($port > 0 ? ':' . $port : '') . '/';
            }
        }

        Database::statement(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $config['dbname']
        ), true);

        // 建表 DDL 依赖 DATETIME DEFAULT CURRENT_TIMESTAMP（MySQL 5.6.5 起才支持），
        // 低于此版本（如 5.5）建表会报 Invalid default value，这里提前给出明确提示。
        if (!Database::mysqlVersionOk()) {
            throw new RuntimeException(
                '数据库版本过低：需要 MySQL ' . Database::MIN_MYSQL_VERSION . ' 及以上（或 MariaDB），'
                . '当前 ' . Database::serverVersion() . '。请升级数据库后重试安装。'
            );
        }

        // 系统配置表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `config_name` VARCHAR(100) NOT NULL COMMENT \'配置项名称\',
                `config_value` TEXT NOT NULL COMMENT \'配置项值\',
                `description` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'配置项描述\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_config_name` (`config_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'系统配置表\'',
            $prefix . 'config'
        ));
        $siteConfigSql = sprintf('INSERT INTO `%s` (`config_name`, `config_value`, `description`) VALUES (:config_name, :config_value, :description) ON DUPLICATE KEY UPDATE `config_value` = VALUES(`config_value`), `description` = VALUES(`description`)', $prefix . 'config');
        $serverVersionTs = (string) time();
        $defaultConfigs = [
            ['config_name' => 'sitename', 'config_value' => 'EMSHOP', 'description' => '站点名称'],
            ['config_name' => 'site_url', 'config_value' => $siteUrl, 'description' => '站点地址（含 http(s)）'],
            ['config_name' => 'site_enabled', 'config_value' => '1', 'description' => '站点开启'],
            ['config_name' => 'site_favicon', 'config_value' => '', 'description' => '网站 favicon（留空使用根目录 /favicon.ico）'],
            ['config_name' => 'site_logo_type', 'config_value' => 'text', 'description' => 'Logo 显示方式'],
            ['config_name' => 'homepage_mode', 'config_value' => 'mall', 'description' => '首页入口'],
            ['config_name' => 'site_timezone', 'config_value' => 'Asia/Shanghai', 'description' => '服务器时区'],
            ['config_name' => 'url_format', 'config_value' => 'default', 'description' => '链接格式'],
            ['config_name' => 'user_register', 'config_value' => '1', 'description' => '开放注册'],
            ['config_name' => 'user_login', 'config_value' => '1', 'description' => '开放登录'],
            // 默认不勾选任何必填项：新装站注册只要求账号 + 密码，手机号/邮箱都选填
            ['config_name' => 'user_register_fields', 'config_value' => '', 'description' => '注册必填项（mobile/email，逗号分隔；空=都选填）'],
            ['config_name' => 'user_credit_name', 'config_value' => '经验', 'description' => '前台经验名称展示'],
            ['config_name' => 'user_credit_initial', 'config_value' => '0', 'description' => '注册赠送初始经验值'],
            ['config_name' => 'user_exp_per_yuan', 'config_value' => '0', 'description' => '每消费1元赠送经验值'],
            ['config_name' => 'shop_balance_enabled', 'config_value' => '1', 'description' => '余额购买'],
            ['config_name' => 'shop_guest_balance_enabled', 'config_value' => '1', 'description' => '游客余额购买'],
            ['config_name' => 'shop_min_recharge', 'config_value' => '10000000', 'description' => '单次最低充值'],
            ['config_name' => 'shop_max_recharge', 'config_value' => '500000000', 'description' => '单次最高充值'],
            ['config_name' => 'shop_withdraw_min', 'config_value' => '10000000', 'description' => '最低提现额'],
            ['config_name' => 'shop_withdraw_max', 'config_value' => '2000000000', 'description' => '最高提现额'],
            ['config_name' => 'shop_order_expire_minutes', 'config_value' => '10', 'description' => '订单超时时间（分钟）'],
            ['config_name' => 'shop_enable_coupon', 'config_value' => '1', 'description' => '启用优惠券'],
            ['config_name' => 'shop_display_stock', 'config_value' => '1', 'description' => '前台显示库存'],
            ['config_name' => 'shop_display_sales', 'config_value' => '1', 'description' => '前台显示销量'],
            ['config_name' => 'shop_block_sold_out_access', 'config_value' => '1', 'description' => '已售罄商品禁止访问'],
            ['config_name' => 'shop_announcement', 'config_value' => '<p><span style="color: rgb(207, 19, 34);">这里是系统自带的默认公告。如需修改，请前往后台管理面板 - 基础设置 - 商城设置页面处更改</span></p><p><span style="color: rgb(56, 158, 13);">注意本系统遵循GPLv3开源协议发布，使用者造成的一切法律后果与作者无关</span></p>', 'description' => '商城公告内容'],
            ['config_name' => 'shop_announcement_positions', 'config_value' => 'home,goods_list', 'description' => '商城公告显示位置'],
            ['config_name' => 'guest_find_contact_enabled', 'config_value' => '0', 'description' => '联系方式查单开关'],
            ['config_name' => 'guest_find_contact_type', 'config_value' => 'any', 'description' => '联系方式查单类型'],
            ['config_name' => 'guest_find_contact_checkout_placeholder', 'config_value' => '请输入您的联系方式', 'description' => '联系方式下单页占位提示'],
            ['config_name' => 'guest_find_contact_lookup_placeholder', 'config_value' => '请输入您下单时填写的联系方式', 'description' => '联系方式查单页占位提示'],
            ['config_name' => 'guest_find_password_enabled', 'config_value' => '1', 'description' => '订单密码查单开关'],
            ['config_name' => 'guest_find_password_checkout_placeholder', 'config_value' => '请设置您的订单密码', 'description' => '订单密码下单页占位提示'],
            ['config_name' => 'guest_find_password_lookup_placeholder', 'config_value' => '请输入您的订单密码', 'description' => '订单密码查单页占位提示'],
            ['config_name' => 'shop_enable_rebate', 'config_value' => '0', 'description' => '推广返佣总开关'],
            ['config_name' => 'rebate_level1_rate', 'config_value' => '1000', 'description' => '一级佣金比例（万分位）'],
            ['config_name' => 'rebate_level2_rate', 'config_value' => '300', 'description' => '二级佣金比例（万分位）'],
            ['config_name' => 'rebate_calculate_mode', 'config_value' => 'amount', 'description' => '推广返佣计算方式'],
            ['config_name' => 'rebate_freeze_days', 'config_value' => '3', 'description' => '推广返佣冷却天数'],
            ['config_name' => 'blog_article_per_page', 'config_value' => '10', 'description' => '博客每页文章数'],
            ['config_name' => 'blog_comment_need_verify', 'config_value' => '1', 'description' => '博客评论需审核'],
            ['config_name' => 'blog_show_author', 'config_value' => '1', 'description' => '博客显示作者'],
            ['config_name' => 'blog_show_views', 'config_value' => '1', 'description' => '博客显示阅读量'],
            ['config_name' => 'blog_rss_enabled', 'config_value' => '0', 'description' => '博客 RSS 开关'],
            ['config_name' => 'mail_port', 'config_value' => '465', 'description' => '邮箱端口'],
            ['config_name' => 'substation_enabled', 'config_value' => '1', 'description' => '启用商户'],
            ['config_name' => 'merchant_enable_self_open', 'config_value' => '1', 'description' => '允许自动开通商户'],
            ['config_name' => 'merchant_default_theme', 'config_value' => 'default', 'description' => '分站默认模板'],
            ['config_name' => 'active_template_pc', 'config_value' => 'default', 'description' => '主站 PC 启用模板'],
            ['config_name' => 'active_template_mobile', 'config_value' => 'default', 'description' => '主站手机启用模板'],
            // 任务服务「代码版本」：pending > applied 时管家重启 worker 加载新代码。
            // 只建新命名 —— 历史遗留 key（local_/new_swoole_file_version）不在这里预建：
            // 商店里尚未迁移的老插件 bump 时会用 Config::set 自行创建，读侧仍然认它。
            ['config_name' => 'server_file_version_applied', 'config_value' => $serverVersionTs, 'description' => '任务服务代码版本（已由 worker 加载）'],
            ['config_name' => 'server_file_version_pending', 'config_value' => $serverVersionTs, 'description' => '任务服务代码版本（待 worker 加载）'],
            ['config_name' => 'enabled_plugins', 'config_value' => 'tips,virtual_card', 'description' => '主站默认启用插件'],
        ];
        foreach ($defaultConfigs as $configRow) Database::execute($siteConfigSql, $configRow);

        // 用户表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `username` VARCHAR(50) NOT NULL COMMENT \'用户名（登录账号）\',
                `email` VARCHAR(120) NOT NULL DEFAULT \'\' COMMENT \'邮箱地址\',
                `password` VARCHAR(255) NOT NULL COMMENT \'密码（bcrypt加密）\',
                `nickname` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'昵称\',
                `avatar` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'头像URL\',
                `mobile` VARCHAR(20) NOT NULL DEFAULT \'\' COMMENT \'手机号码\',
                `money` BIGINT NOT NULL DEFAULT 0 COMMENT \'账户余额（实际金额×1000000）\',
                `secret` VARCHAR(64) DEFAULT NULL COMMENT \'API密钥\',
                `role` VARCHAR(20) NOT NULL DEFAULT \'user\' COMMENT \'角色：admin=管理员 user=普通用户\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'状态：1=正常 0=禁用\',
                `last_login_ip` VARCHAR(45) DEFAULT NULL COMMENT \'最后登录IP\',
                `last_login_at` DATETIME DEFAULT NULL COMMENT \'最后登录时间\',
                `remember_token` CHAR(64) DEFAULT NULL COMMENT \'记住我令牌\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'注册时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                `invite_code` VARCHAR(16) DEFAULT NULL COMMENT \'邀请码\',
                `inviter_l1` BIGINT UNSIGNED DEFAULT NULL COMMENT \'直接上级用户ID\',
                `inviter_l2` BIGINT UNSIGNED DEFAULT NULL COMMENT \'二级上级用户ID\',
                `commission_frozen` BIGINT NOT NULL DEFAULT 0 COMMENT \'冻结佣金（×1000000）\',
                `commission_available` BIGINT NOT NULL DEFAULT 0 COMMENT \'可提现佣金（×1000000）\',
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'所属商户 id；0=非商户主\',
                `shop_balance` BIGINT NOT NULL DEFAULT 0 COMMENT \'店铺余额 ×1000000（仅商户主有值）\',
                `level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'用户等级 user_levels.id；0=未设置\',
                `total_consumption` BIGINT NOT NULL DEFAULT 0 COMMENT \'累计消费金额×1000000\',
                `experience` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'经验值\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_username` (`username`),
                KEY `idx_email_role` (`email`, `role`),
                UNIQUE KEY `uk_invite_code` (`invite_code`),
                KEY `idx_role_status` (`role`, `status`),
                KEY `idx_remember_token` (`remember_token`),
                KEY `idx_inviter_l1` (`inviter_l1`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'用户表\'',
            $prefix . 'user'
        ));
        $admin = isset($options['admin']) && is_array($options['admin']) ? $options['admin'] : [];
        $adminUsername = trim((string) ($admin['username'] ?? ''));
        $adminEmail = trim((string) ($admin['email'] ?? ''));
        $adminPassword = (string) ($admin['password'] ?? '');
        // 邮箱非必填：留空则写入空串（`user`.`email` 为 NOT NULL DEFAULT ''，且无唯一约束）
        if ($adminUsername === '' || $adminPassword === '') throw new InvalidArgumentException('管理员账号信息不完整');
        $hasher = new PasswordHash(8, true);
        $hash = $hasher->HashPassword($adminPassword);
        $sql = sprintf(
            'INSERT INTO `%s` (`username`, `email`, `password`, `nickname`, `avatar`, `role`, `status`)
             VALUES (:username, :email, :password, :nickname, :avatar, :role, :status)
             ON DUPLICATE KEY UPDATE
                `email` = VALUES(`email`),
                `password` = VALUES(`password`),
                `nickname` = VALUES(`nickname`),
                `avatar` = VALUES(`avatar`),
                `role` = VALUES(`role`),
                `status` = VALUES(`status`),
                `updated_at` = CURRENT_TIMESTAMP',
            $prefix . 'user'
        );
        Database::execute($sql, [
            'username' => $adminUsername,
            'email' => $adminEmail,
            'password' => $hash,
            'nickname' => '管理员',
            'avatar' => '',
            'role' => 'admin',
            'status' => 1,
        ]);

        // 优惠券定义表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `code` VARCHAR(32) NOT NULL COMMENT \'公共兑换码（全站唯一）\',
                `name` VARCHAR(100) NOT NULL COMMENT \'后台识别名称\',
                `title` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'用户展示标题\',
                `description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'使用说明\',
                `type` VARCHAR(20) NOT NULL DEFAULT \'fixed_amount\' COMMENT \'类型：fixed_amount/percent/free_shipping\',
                `value` BIGINT NOT NULL DEFAULT 0 COMMENT \'券值（满减×1000000；打折为整数 0-100）\',
                `min_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'使用门槛 ×1000000\',
                `max_discount` BIGINT NOT NULL DEFAULT 0 COMMENT \'打折最大减免 ×1000000，0 表示无封顶\',
                `apply_scope` VARCHAR(20) NOT NULL DEFAULT \'all\' COMMENT \'适用范围：all/category/goods/goods_type\',
                `apply_ids` TEXT COMMENT \'适用目标 id 列表 JSON\',
                `start_at` DATETIME DEFAULT NULL COMMENT \'生效开始\',
                `end_at` DATETIME DEFAULT NULL COMMENT \'生效结束\',
                `total_usage_limit` INT NOT NULL DEFAULT -1 COMMENT \'总使用次数上限 -1=无限\',
                `used_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'已使用次数缓存\',
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'启用状态\',
                `show_on_front` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'前台领券中心展示：1=展示 0=私下发放\',
                `owner_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'所属者：0=主站，其他=分站\',
                `sort` INT NOT NULL DEFAULT 100 COMMENT \'排序\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_code` (`code`),
                KEY `idx_end_at` (`end_at`),
                KEY `idx_enabled` (`is_enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'优惠券定义\'',
            $prefix . 'coupon'
        ));

        // 用户优惠券表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'用户ID\',
                `coupon_id` INT UNSIGNED NOT NULL COMMENT \'优惠券ID\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'unused\' COMMENT \'unused/used\',
                `obtained_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'领取时间\',
                `used_at` DATETIME DEFAULT NULL COMMENT \'使用时间\',
                `order_id` BIGINT UNSIGNED DEFAULT NULL COMMENT \'使用该券的订单ID\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_coupon` (`user_id`, `coupon_id`),
                KEY `idx_coupon` (`coupon_id`),
                KEY `idx_user_status` (`user_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'用户持有的优惠券\'',
            $prefix . 'user_coupon'
        ));

        // 用户余额流水表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'用户ID\',
                `type` VARCHAR(20) NOT NULL COMMENT \'类型：increase=增加 decrease=减少\',
                `amount` BIGINT NOT NULL COMMENT \'变动金额（×1000000）\',
                `before_balance` BIGINT NOT NULL COMMENT \'变动前余额（×1000000）\',
                `after_balance` BIGINT NOT NULL COMMENT \'变动后余额（×1000000）\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'备注\',
                `operator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'操作人ID\',
                `operator_name` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'操作人名称\',
                `ip` VARCHAR(45) NOT NULL DEFAULT \'\' COMMENT \'操作IP\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'用户余额变动记录\'',
            $prefix . 'user_balance_log'
        ));

        // 佣金流水表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'佣金归属用户\',
                `order_id` BIGINT UNSIGNED NOT NULL COMMENT \'关联订单\',
                `order_no` VARCHAR(32) NOT NULL COMMENT \'订单号冗余\',
                `from_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'下单用户 0=游客\',
                `level` TINYINT UNSIGNED NOT NULL COMMENT \'分销级别 1/2/3\',
                `amount` BIGINT NOT NULL COMMENT \'佣金金额 ×1000000\',
                `rate` INT NOT NULL COMMENT \'计算时的比例（整数 500=5%%）\',
                `basis_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'计算基数（利润金额）×1000000\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'frozen\' COMMENT \'frozen/available/withdrawn/reverted\',
                `frozen_until` DATETIME DEFAULT NULL COMMENT \'冻结到期时间（NULL=已解冻或其他状态）\',
                `withdraw_id` BIGINT UNSIGNED DEFAULT NULL COMMENT \'关联提现记录\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user_status` (`user_id`, `status`),
                KEY `idx_order` (`order_id`),
                KEY `idx_frozen_until` (`frozen_until`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'佣金流水\'',
            $prefix . 'commission_log'
        ));

        // 用户充值订单表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `order_no` VARCHAR(32) NOT NULL COMMENT \'充值单号，R 开头\',
                `amount` BIGINT NOT NULL COMMENT \'充值金额 ×1000000\',
                `payment_code` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'支付方式 code\',
                `payment_plugin` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'所属支付插件 slug\',
                `trade_no` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'第三方流水号\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\' COMMENT \'pending=待支付 paid=已充值 cancelled=已取消\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `paid_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_user` (`user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'钱包充值订单\'',
            $prefix . 'user_recharge'
        ));

        // 用户提现申请表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `amount` BIGINT NOT NULL COMMENT \'提现金额 ×1000000（已从 user.money 扣除）\',
                `channel` VARCHAR(16) NOT NULL COMMENT \'收款方式：alipay/wxpay/bank\',
                `account_name` VARCHAR(50) NOT NULL COMMENT \'收款人姓名\',
                `account_no` VARCHAR(100) NOT NULL COMMENT \'收款账号\',
                `bank_name` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'开户行（仅银行卡）\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\' COMMENT \'pending=待审核 approved=审核通过 paid=已打款 rejected=已驳回\',
                `admin_remark` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'管理员审核备注\',
                `admin_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'最后处理人\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `processed_at` DATETIME NULL DEFAULT NULL COMMENT \'最后处理时间\',
                PRIMARY KEY (`id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'钱包提现申请\'',
            $prefix . 'user_withdraw'
        ));

        // 佣金提现记录表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `amount` BIGINT NOT NULL COMMENT \'提现金额 ×1000000\',
                `before_balance` BIGINT NOT NULL DEFAULT 0 COMMENT \'提现前可用佣金\',
                `after_balance` BIGINT NOT NULL DEFAULT 0 COMMENT \'提现后可用佣金\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'done\' COMMENT \'done=已入余额\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'佣金提现记录\'',
            $prefix . 'commission_withdraw'
        ));

        // 订单主表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `order_no` VARCHAR(32) NOT NULL COMMENT \'订单编号\',
                `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'用户ID；0=游客\',
                `guest_token` VARCHAR(64) DEFAULT NULL COMMENT \'游客身份标识\',
                `owner_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'归属者ID（分站隔离）\',
                `goods_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'商品总金额（×1000000）\',
                `discount_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'优惠总金额（×1000000）\',
                `coupon_code` VARCHAR(32) DEFAULT NULL,
                `pay_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'实付金额（×1000000）\',
                `payment_code` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'支付方式code\',
                `payment_name` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'支付方式名称\',
                `payment_plugin` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'支付插件标识\',
                `payment_plugin_name` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'支付插件中文名\',
                `payment_channel` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'支付通道\',
                `status` VARCHAR(20) NOT NULL DEFAULT \'pending\' COMMENT \'状态\',
                `contact_info` TEXT,
                `guest_contact` VARCHAR(255) DEFAULT NULL COMMENT \'游客联系方式（查单凭据）\',
                `order_password` VARCHAR(255) DEFAULT NULL,
                `pay_time` DATETIME DEFAULT NULL COMMENT \'支付时间\',
                `delivery_time` DATETIME DEFAULT NULL COMMENT \'发货时间\',
                `complete_time` DATETIME DEFAULT NULL COMMENT \'完成时间\',
                `ip` VARCHAR(45) NOT NULL DEFAULT \'\' COMMENT \'下单IP\',
                `source` VARCHAR(16) NOT NULL DEFAULT \'web\' COMMENT \'订单来源\',
                `remark` TEXT COMMENT \'用户备注\',
                `admin_remark` TEXT COMMENT \'管理员备注\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                `inviter_l1` BIGINT UNSIGNED DEFAULT NULL COMMENT \'下单时一级上级（快照）\',
                `inviter_l2` BIGINT UNSIGNED DEFAULT NULL COMMENT \'下单时二级上级（快照）\',
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'下单时所在商户id；0=主站订单\',
                `display_currency_code` VARCHAR(3) NOT NULL DEFAULT \'\' COMMENT \'下单时访客选择的展示货币代码；空=主货币\',
                `display_rate` BIGINT NOT NULL DEFAULT 0 COMMENT \'下单时汇率快照 ×1000000\',
                `shipping_address_snapshot` TEXT COMMENT \'收货地址快照 JSON\',
                `delivery_callback_url` VARCHAR(500) DEFAULT NULL COMMENT \'发货异步回调地址（同系统对接）\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_user` (`user_id`),
                KEY `idx_guest_token` (`guest_token`),
                KEY `idx_owner_status` (`owner_id`, `status`),
                KEY `idx_created` (`created_at`),
                KEY `idx_coupon_code` (`coupon_code`),
                KEY `idx_merchant_status` (`merchant_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'订单主表\'',
            $prefix . 'order'
        ));

        // 订单经验/消费结算记录（幂等，不污染 order 表）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `order_id` BIGINT UNSIGNED NOT NULL COMMENT \'订单ID\',
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'用户ID\',
                `pay_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'计入累计消费的实付×1000000\',
                `exp_gained` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'本单赠送经验\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`order_id`),
                KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'订单经验/消费结算记录\'',
            $prefix . 'user_experience_order'
        ));

        // 订单商品表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `order_id` BIGINT UNSIGNED NOT NULL COMMENT \'订单ID\',
                `goods_id` INT UNSIGNED NOT NULL COMMENT \'商品ID\',
                `spec_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'规格ID\',
                `goods_title` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'商品标题（快照）\',
                `spec_name` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'规格名称（快照）\',
                `cover_image` VARCHAR(512) NOT NULL DEFAULT \'\' COMMENT \'封面图（快照）\',
                `price` BIGINT NOT NULL DEFAULT 0 COMMENT \'单价（×1000000，快照）\',
                `quantity` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT \'购买数量\',
                `goods_type` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'商品类型（快照）\',
                `plugin_data` TEXT COMMENT \'插件私有数据\',
                `delivery_content` TEXT COMMENT \'发货内容（兼容摘要）\',
                `delivery_at` DATETIME DEFAULT NULL COMMENT \'发货时间\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `goods_owner_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'商品 owner_id 快照；0=主站货\',
                `cost_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'拿货价快照 ×1000000\',
                `fee_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'自建手续费快照 ×1000000\',
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`),
                KEY `idx_goods` (`goods_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'订单商品表\'',
            $prefix . 'order_goods'
        ));

        // 订单商品发货明细表（支持海量发货内容按行存储）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `order_goods_id` BIGINT UNSIGNED NOT NULL COMMENT \'订单商品ID\',
                `content` TEXT NOT NULL COMMENT \'单条发货内容\',
                `sort` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'排序\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                PRIMARY KEY (`id`),
                KEY `idx_order_goods_sort` (`order_goods_id`, `sort`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'订单商品发货明细表\'',
            $prefix . 'order_goods_delivery_item'
        ));

        // 订单支付记录表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `payment_code` VARCHAR(64) NOT NULL DEFAULT \'\',
                `payment_plugin` VARCHAR(64) NOT NULL DEFAULT \'\',
                `trade_no` VARCHAR(128) DEFAULT NULL,
                `amount` BIGINT NOT NULL DEFAULT 0,
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `paid_at` DATETIME DEFAULT NULL,
                `extra` TEXT,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`),
                KEY `idx_trade_no` (`trade_no`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'订单支付记录\'',
            $prefix . 'order_payment'
        ));

        // 语言表

        // 发货队列表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` BIGINT UNSIGNED NOT NULL,
                `order_goods_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `task_type` VARCHAR(32) NOT NULL DEFAULT \'delivery\',
                `goods_type` VARCHAR(64) NOT NULL DEFAULT \'\',
                `payload` TEXT,
                `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3,
                `last_error` TEXT,
                `callback_token` VARCHAR(64) DEFAULT NULL,
                `next_retry_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `completed_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_order` (`order_id`),
                KEY `idx_status` (`status`, `next_retry_at`),
                KEY `idx_callback` (`callback_token`),
                KEY `idx_order_goods_status` (`order_goods_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'发货队列任务表\'',
            $prefix . 'delivery_queue'
        ));

        // 插件事件表（跨插件通知的落库载体）
        //
        // 后台任务里的跨插件通知不再走内存钩子（那会让被通知方的代码住进
        // 通知方的进程，插件更新就必须重启别人的 worker），而是写一行事件，
        // 由订阅方自己的 worker 按游标取走处理。见 PluginEvent。
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键（同时是订阅方的游标值）\',
                `event` VARCHAR(64) NOT NULL COMMENT \'事件名，如 order_goods_delivery_queued_success\',
                `args` TEXT COMMENT \'JSON：事件的位置参数数组，与订阅方钩子签名一一对应\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                PRIMARY KEY (`id`),
                KEY `idx_event_id` (`event`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'插件事件表（发布-订阅，订阅方自行记录游标）\'',
            $prefix . 'plugin_event'
        ));

        // 语言表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `name` VARCHAR(50) NOT NULL COMMENT \'语言名称，如：简体中文\',
                `code` VARCHAR(20) NOT NULL COMMENT \'浏览器语言码，如：zh-CN\',
                `icon` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'语言图标/国旗图片URL\',
                `sort` INT NOT NULL DEFAULT 0 COMMENT \'排序，越小越靠前\',
                `enabled` CHAR(1) NOT NULL DEFAULT \'y\' COMMENT \'是否启用：y=启用 n=禁用\',
                `is_default` CHAR(1) NOT NULL DEFAULT \'n\' COMMENT \'是否默认语言：y=是 n=否\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_code` (`code`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'语言表\'',
            $prefix . 'language'
        ));
        Database::execute(sprintf(
            'INSERT IGNORE INTO `%s` (`id`, `name`, `code`, `icon`, `sort`, `is_default`, `enabled`) VALUES (1, \'简体中文\', \'zh-cn\', \'\', 0, \'y\', \'y\')',
            $prefix . 'language'
        ), []);
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `lang_id` BIGINT UNSIGNED NOT NULL COMMENT \'关联语言ID\',
                `translate` VARCHAR(200) NOT NULL COMMENT \'翻译语句原文\',
                `content` TEXT NOT NULL COMMENT \'翻译内容\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_lang_translate` (`lang_id`, `translate`(191)),
                KEY `idx_lang_id` (`lang_id`),
                CONSTRAINT `fk_lang_id` FOREIGN KEY (`lang_id`) REFERENCES `%s`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'翻译词条表\'',
            $prefix . 'lang',
            $prefix . 'language'
        ));

        // 商品分类表

        // 文章分类表

        // 导航表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'父级ID，0=顶级\',
                `name` VARCHAR(100) NOT NULL COMMENT \'分类名称\',
                `slug` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'URL别名\',
                `description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'分类描述\',
                `icon` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'图标\',
                `cover_image` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'封面图片\',
                `sort` INT NOT NULL DEFAULT 100 COMMENT \'排序值，越小越靠前\',
                `seo_title` VARCHAR(200) NOT NULL DEFAULT \'\' COMMENT \'SEO标题\',
                `seo_keywords` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'SEO关键词\',
                `seo_description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'SEO描述\',
                `rebate_config` TEXT NULL COMMENT \'分类级返佣配置 JSON：{"l1":500,"l2":300}（整数 rate/10000 = 百分比）\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'状态：1=启用 0=禁用\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                KEY `idx_parent_id` (`parent_id`),
                KEY `idx_slug` (`slug`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商品分类表\'',
            $prefix . 'goods_category'
        ));
        Database::insert('goods_category', ['parent_id' => 0, 'name' => '演示分类', 'slug' => 'demo-category', 'description' => '默认演示商品分类', 'sort' => 100, 'status' => 1]);
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'父级ID，0=顶级\',
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=主站；>0=商户\',
                `name` VARCHAR(100) NOT NULL COMMENT \'分类名称\',
                `slug` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'URL别名\',
                `description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'分类描述\',
                `icon` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'图标\',
                `cover_image` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'封面图片\',
                `sort` INT NOT NULL DEFAULT 100 COMMENT \'排序值，越小越靠前\',
                `seo_title` VARCHAR(200) NOT NULL DEFAULT \'\' COMMENT \'SEO标题\',
                `seo_keywords` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'SEO关键词\',
                `seo_description` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'SEO描述\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'状态：1=启用 0=禁用\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                KEY `idx_parent_id` (`parent_id`),
                KEY `idx_slug` (`slug`),
                KEY `idx_status` (`status`),
                KEY `idx_merchant_status` (`merchant_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'文章分类表\'',
            $prefix . 'blog_category'
        ));
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'父级ID，0=顶级\',
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=主站；>0=商户自定义导航；is_system=1 时永远 0\',
                `name` VARCHAR(100) NOT NULL COMMENT \'导航名称\',
                `type` VARCHAR(20) NOT NULL DEFAULT \'custom\' COMMENT \'类型：system/custom/goods_cat/blog_cat\',
                `type_ref_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'关联ID\',
                `link` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'链接地址\',
                `icon` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'图标\',
                `target` VARCHAR(20) NOT NULL DEFAULT \'_self\' COMMENT \'打开方式\',
                `sort` INT NOT NULL DEFAULT 100 COMMENT \'排序值\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'1=启用 0=禁用\',
                `is_system` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1=系统导航不可删除\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_parent_id` (`parent_id`),
                KEY `idx_sort` (`sort`),
                KEY `idx_status` (`status`),
                KEY `idx_merchant_status` (`merchant_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'导航表\'',
            $prefix . 'navi'
        ));
        $defaultNavi = [
            ['name' => '首页', 'type' => 'system', 'link' => '?',             'sort' => 1, 'is_system' => 1],
            ['name' => '商城', 'type' => 'system', 'link' => '?c=goods_list', 'sort' => 2, 'is_system' => 1],
            ['name' => '博客', 'type' => 'system', 'link' => '?c=blog_list',  'sort' => 3, 'is_system' => 1],
        ];
        foreach ($defaultNavi as $row) {
            $exists = Database::fetchOne(
                'SELECT `id` FROM `' . $prefix . 'navi' . '` WHERE `name` = ? AND `is_system` = 1 LIMIT 1',
                [$row['name']]
            );
            if ($exists === null) {
                Database::insert('navi', $row);
            }
        }

        // 文章表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'分类ID\',
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=主站文章；>0=商户文章\',
                `title` VARCHAR(255) NOT NULL COMMENT \'文章标题\',
                `slug` VARCHAR(255) DEFAULT NULL COMMENT \'URL别名\',
                `excerpt` TEXT COMMENT \'摘要\',
                `content` LONGTEXT COMMENT \'正文内容\',
                `cover_image` VARCHAR(500) DEFAULT NULL COMMENT \'封面图\',
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'作者用户ID\',
                `views_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'阅读量\',
                `is_top` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'是否置顶\',
                `sort` INT NOT NULL DEFAULT 0 COMMENT \'排序（越小越靠前）\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'状态：1=发布 0=草稿\',
                `created_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'创建人ID\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL COMMENT \'软删除时间，NULL表示未删除\',
                PRIMARY KEY (`id`),
                KEY `idx_category` (`category_id`),
                KEY `idx_status_sort` (`status`, `is_top`, `sort`, `id`),
                KEY `idx_views` (`views_count`),
                KEY `idx_deleted` (`deleted_at`),
                KEY `idx_merchant_status` (`merchant_id`, `status`, `deleted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'文章表\'',
            $prefix . 'blog'
        ));

        // 博客标签表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=主站标签池；>0=商户标签池\',
                `name` VARCHAR(50) NOT NULL COMMENT \'标签名称\',
                `slug` VARCHAR(50) DEFAULT \'\' COMMENT \'URL别名\',
                `color` VARCHAR(20) DEFAULT \'\' COMMENT \'标签颜色（可选）\',
                `sort` INT NOT NULL DEFAULT 0 COMMENT \'排序\',
                `article_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'文章数量（冗余计数）\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_merchant_name` (`merchant_id`, `name`),
                KEY `idx_sort` (`sort`, `id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'博客标签表\'',
            $prefix . 'blog_tag'
        ));
        // 应用市场表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `app_code` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'应用 slug = 物理目录名\',
                `type` ENUM(\'plugin\', \'template\') NOT NULL COMMENT \'应用类型\',
                `remote_app_id` INT UNSIGNED DEFAULT NULL COMMENT \'服务端 app_id\',
                `title` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'应用名称\',
                `version` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'版本号\',
                `category` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'分类\',
                `cover` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'封面/图标 URL\',
                `description` TEXT COMMENT \'简介\',
                `cost_price` BIGINT NOT NULL DEFAULT 0 COMMENT \'主站最近一次采购成本(微分)\',
                `retail_price` BIGINT NOT NULL DEFAULT 0 COMMENT \'主站定的分站售价(微分)\',
                `total_quota` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'累计采购次数\',
                `consumed_quota` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'已售出次数\',
                `is_listed` TINYINT NOT NULL DEFAULT 1 COMMENT \'是否对分站可见 1=上架 0=下架\',
                `remote_payload` LONGTEXT DEFAULT NULL COMMENT \'服务端返回的完整应用元数据快照\',
                `last_purchased_at` DATETIME DEFAULT NULL COMMENT \'主站最近一次采购时间\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_app_code_type` (`app_code`, `type`),
                KEY `idx_listed` (`is_listed`),
                KEY `idx_type` (`type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'应用商店:主站为分站采购的上架清单\'',
            $prefix . 'app_market'
        ));

        // 应用市场流水表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `market_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'关联 app_market.id\',
                `app_code` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'冗余:方便单表查询\',
                `type` ENUM(\'plugin\', \'template\') NOT NULL,
                `purchase_qty` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'本次采购的配额数量\',
                `cost_per_unit` BIGINT NOT NULL DEFAULT 0 COMMENT \'本次单价(微分)\',
                `total_cost` BIGINT NOT NULL DEFAULT 0 COMMENT \'本次总价 = qty * cost_per_unit\',
                `remote_order_no` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'服务端订单号\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'备注\',
                `purchased_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_market` (`market_id`),
                KEY `idx_purchased_at` (`purchased_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'应用商店:主站向服务端采购流水\'',
            $prefix . 'app_market_log'
        ));

        // 应用购买记录表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'分站商户 id\',
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'扣款的分站站长 user.id\',
                `app_code` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'应用 slug\',
                `type` ENUM(\'plugin\', \'template\') NOT NULL COMMENT \'应用类型\',
                `market_id` INT UNSIGNED DEFAULT NULL COMMENT \'关联 app_market.id\',
                `paid_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'成交价(微分);0=免费授权\',
                `balance_log_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'关联 user_balance_log.id;0=未扣款\',
                `purchased_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_merchant_app_type` (`merchant_id`, `app_code`, `type`),
                KEY `idx_market` (`market_id`),
                KEY `idx_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'应用商店:分站已购应用记录\'',
            $prefix . 'app_purchase'
        ));

        // 应用订单表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_no` VARCHAR(40) NOT NULL DEFAULT \'\' COMMENT \'应用订单号（AO 前缀）\',
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'分站 ID\',
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'购买用户 ID\',
                `market_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'关联 app_market.id\',
                `app_code` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'应用 slug\',
                `type` ENUM(\'plugin\', \'template\') NOT NULL COMMENT \'应用类型\',
                `app_title` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'应用标题快照\',
                `amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'成交价（微分）\',
                `pay_method` VARCHAR(20) NOT NULL DEFAULT \'balance\' COMMENT \'支付方式\',
                `status` VARCHAR(20) NOT NULL DEFAULT \'paid\' COMMENT \'订单状态\',
                `balance_log_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'关联 user_balance_log.id\',
                `before_balance` BIGINT NOT NULL DEFAULT 0 COMMENT \'扣款前余额（微分）\',
                `after_balance` BIGINT NOT NULL DEFAULT 0 COMMENT \'扣款后余额（微分）\',
                `note` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'备注\',
                `paid_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_order_no` (`order_no`),
                KEY `idx_merchant` (`merchant_id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_market` (`market_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'应用商店：分站应用购买订单\'',
            $prefix . 'app_order'
        ));

        // 附件表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED DEFAULT NULL COMMENT \'上传者用户ID，null表示游客或系统\',
                `file_name` VARCHAR(255) NOT NULL COMMENT \'原始文件名\',
                `file_path` VARCHAR(500) NOT NULL COMMENT \'服务器存储相对路径\',
                `file_url` VARCHAR(500) NOT NULL COMMENT \'访问URL\',
                `file_size` INT UNSIGNED NOT NULL COMMENT \'文件大小（字节）\',
                `file_ext` VARCHAR(20) NOT NULL COMMENT \'文件扩展名\',
                `mime_type` VARCHAR(100) NOT NULL COMMENT \'MIME类型\',
                `md5` CHAR(32) DEFAULT NULL COMMENT \'文件MD5（去重用）\',
                `driver` VARCHAR(20) NOT NULL DEFAULT \'local\' COMMENT \'存储驱动\',
                `context` VARCHAR(50) NOT NULL DEFAULT \'default\' COMMENT \'使用场景\',
                `context_id` BIGINT UNSIGNED DEFAULT NULL COMMENT \'关联记录ID\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_context` (`context`, `context_id`),
                KEY `idx_md5` (`md5`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'附件表\'',
            $prefix . 'attachment'
        ));

        // 博客评论表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `blog_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'文章ID\',
                `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'用户ID\',
                `parent_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'父评论ID（0=顶级评论）\',
                `reply_user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'被回复的用户ID\',
                `content` TEXT NOT NULL COMMENT \'评论内容\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'状态：0=待审核 1=已通过 2=已拒绝\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL COMMENT \'软删除时间\',
                PRIMARY KEY (`id`),
                KEY `idx_blog_id` (`blog_id`, `status`, `created_at`),
                KEY `idx_parent_id` (`parent_id`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'博客评论表\'',
            $prefix . 'blog_comment'
        ));

        // 博客标签关联表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `blog_id` INT UNSIGNED NOT NULL COMMENT \'文章ID\',
                `tag_id` INT UNSIGNED NOT NULL COMMENT \'标签ID\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_blog_tag` (`blog_id`, `tag_id`),
                KEY `idx_tag_id` (`tag_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'文章标签关联表\'',
            $prefix . 'blog_tag_relation'
        ));

        // 友情链接表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'链接名称\',
                `url` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'链接地址\',
                `image` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'图片地址\',
                `enabled` ENUM(\'y\', \'n\') NOT NULL DEFAULT \'y\' COMMENT \'是否启用\',
                `expire_time` DATETIME DEFAULT NULL COMMENT \'过期时间\',
                `description` TEXT COMMENT \'描述\',
                `sort` INT NOT NULL DEFAULT 0 COMMENT \'排序（越大越靠前）\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_enabled_sort` (`enabled`, `sort`),
                KEY `idx_expire_time` (`expire_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'友情链接表\'',
            $prefix . 'friend_link'
        ));

        // 迁移记录表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `filename` VARCHAR(255) NOT NULL COMMENT \'迁移文件名\',
                `batch` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'迁移批次\',
                `applied_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'执行时间\',
                `checksum` CHAR(64) NOT NULL DEFAULT \'\' COMMENT \'文件校验\',
                PRIMARY KEY (`id`),
                KEY `idx_batch` (`batch`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'数据库迁移追踪表\'',
            $prefix . 'migrations'
        ));

        // 配置项表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `type` VARCHAR(32) NOT NULL DEFAULT \'plugin\' COMMENT \'类型：plugin=插件，theme=模板\',
                `title` VARCHAR(128) NOT NULL COMMENT \'插件或模板名称\',
                `name` VARCHAR(128) NOT NULL COMMENT \'配置项名称\',
                `content` LONGTEXT NOT NULL COMMENT \'配置内容\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_type_title_merchant_name` (`type`, `title`, `merchant_id`, `name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'插件/模板配置\'',
            $prefix . 'options'
        ));

        // 系统日志表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'主键\',
                `level` ENUM(\'info\', \'warning\', \'error\') NOT NULL DEFAULT \'info\' COMMENT \'日志级别\',
                `type` ENUM(\'login\', \'logout\', \'admin_operation\', \'system\', \'api\') NOT NULL DEFAULT \'system\' COMMENT \'日志类型\',
                `action` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'操作名称\',
                `message` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'消息内容\',
                `detail` TEXT COMMENT \'详细信息（JSON）\',
                `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'操作用户ID\',
                `username` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'操作用户名\',
                `ip` VARCHAR(45) NOT NULL DEFAULT \'\' COMMENT \'IP地址\',
                `user_agent` VARCHAR(512) NOT NULL DEFAULT \'\' COMMENT \'浏览器UA\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                PRIMARY KEY (`id`),
                KEY `idx_level` (`level`),
                KEY `idx_type` (`type`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'系统日志表\'',
            $prefix . 'system_log'
        ));

        // 用户等级表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `name` VARCHAR(50) NOT NULL COMMENT \'等级名称\',
                `level` INT UNSIGNED NOT NULL COMMENT \'等级数字\',
                `discount` BIGINT NOT NULL COMMENT \'享受折扣（x折），存储时乘以1000000\',
                `self_open_price` BIGINT NOT NULL COMMENT \'自助开通价格（分），0=不允许开通\',
                `unlock_exp` BIGINT NOT NULL DEFAULT 0 COMMENT \'解锁所需经验值，0=不启用自动解锁\',
                `remark` VARCHAR(500) NOT NULL DEFAULT \'\' COMMENT \'备注\',
                `icon` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'等级图标URL\',
                `bg` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'等级背景图URL\',
                `enabled` CHAR(1) NOT NULL DEFAULT \'y\' COMMENT \'是否启用：y=启用 n=禁用\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT \'更新时间\',
                PRIMARY KEY (`id`),
                KEY `idx_enabled_sort` (`enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'用户等级表\'',
            $prefix . 'user_levels'
        ));
        // 商品主表（与线上一致）
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(32) NOT NULL COMMENT '商品唯一标识',
            `owner_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '所属者ID',
            `category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品分类ID',
            `category_source` ENUM('main', 'merchant') NOT NULL DEFAULT 'main' COMMENT '分类来源：main=主站分类表 / merchant=商户分类表',
            `title` VARCHAR(255) NOT NULL COMMENT '商品标题',
            `cover_images` TEXT COMMENT 'JSON数组，存储多张图片URL',
            `intro` TEXT COMMENT '商品简介',
            `content` LONGTEXT COMMENT '商品详情',
            `guide` TEXT COMMENT '使用教程',
            `configs` TEXT COMMENT 'JSON，扩展配置项',
            `api_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否开启API对接',
            `jump_url` VARCHAR(255) DEFAULT NULL COMMENT '跳转链接',
            `goods_type` VARCHAR(64) NOT NULL DEFAULT 'default' COMMENT '商品类型标识',
            `unit` VARCHAR(32) NOT NULL DEFAULT '件' COMMENT '商品单位',
            `is_on_sale` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否上架',
            `is_recommended` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否推荐商品',
            `is_top_home` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '首页置顶',
            `is_top_category` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '分类置顶',
            `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
            `views_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
            `plugin_data` TEXT COMMENT 'JSON，插件私有数据存储区',
            `source_type` VARCHAR(32) NOT NULL DEFAULT 'self' COMMENT '商品来源',
            `source_id` VARCHAR(128) DEFAULT NULL COMMENT '来源商品标识',
            `source_version` INT NOT NULL DEFAULT 0 COMMENT '来源数据版本号',
            `min_price` BIGINT NOT NULL DEFAULT 0 COMMENT '最低价（×1000000）',
            `max_price` BIGINT NOT NULL DEFAULT 0 COMMENT '最高价（×1000000）',
            `total_stock` INT NOT NULL DEFAULT 0 COMMENT '总库存',
            `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态',
            `created_by` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '创建人ID',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            `deleted_at` DATETIME DEFAULT NULL COMMENT '软删除时间，NULL表示未删除',
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_code` (`code`),
            KEY `idx_owner_status` (`owner_id`, `status`),
            KEY `idx_category` (`category_id`),
            KEY `idx_sale_sort` (`is_on_sale`, `sort`, `id`),
            KEY `idx_source` (`source_type`, `source_id`),
            KEY `idx_recommended` (`is_recommended`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品主表'");

        // 商品规格表（与线上一致）
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_spec` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `goods_id` INT UNSIGNED NOT NULL COMMENT '所属商品ID',
            `name` VARCHAR(255) NOT NULL COMMENT '规格名称',
            `spec_no` VARCHAR(64) DEFAULT NULL COMMENT '规格编号',
            `cover_image` VARCHAR(512) DEFAULT NULL COMMENT '规格专属封面图',
            `price` BIGINT NOT NULL DEFAULT 0 COMMENT '售价（×1000000）',
            `cost_price` BIGINT DEFAULT NULL COMMENT '成本价（×1000000），NULL表示未设置',
            `market_price` BIGINT DEFAULT NULL COMMENT '市场价（×1000000），NULL表示未设置',
            `stock` INT NOT NULL DEFAULT -1 COMMENT '库存数量，-1表示无限',
            `sold_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '已售数量',
            `min_buy` INT UNSIGNED DEFAULT NULL COMMENT '最低购买数量，NULL表示不限制',
            `max_buy` INT UNSIGNED DEFAULT NULL COMMENT '最大购买数量，NULL表示不限制',
            `tags` TEXT COMMENT 'JSON数组，规格标签',
            `configs` TEXT COMMENT 'JSON：规格级附加配置（目前仅 images）',
            `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
            `is_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否为默认选中的规格',
            `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_goods` (`goods_id`),
            KEY `idx_goods_status` (`goods_id`, `status`),
            KEY `idx_spec_no` (`spec_no`),
            KEY `idx_default` (`goods_id`, `is_default`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品规格表'");

        // 商品规格维度表（与线上一致）
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_spec_dim` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `goods_id` INT UNSIGNED NOT NULL COMMENT '所属商品ID',
            `name` VARCHAR(64) NOT NULL COMMENT '维度名称',
            `sort` INT NOT NULL DEFAULT 0 COMMENT '维度排序',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_goods` (`goods_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='多维规格-维度表'");

        // 商品规格值表（与线上一致）
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_spec_value` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `dim_id` INT UNSIGNED NOT NULL COMMENT '所属维度ID',
            `goods_id` INT UNSIGNED NOT NULL COMMENT '所属商品ID',
            `name` VARCHAR(128) NOT NULL COMMENT '维度值',
            `cover_image` VARCHAR(512) DEFAULT NULL COMMENT '维度值专属图片',
            `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_dim` (`dim_id`),
            KEY `idx_goods` (`goods_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='多维规格-维度值表'");

        // 商品规格组合表（与线上一致）
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_spec_combo` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `goods_id` INT UNSIGNED NOT NULL COMMENT '所属商品ID',
            `spec_id` INT UNSIGNED NOT NULL COMMENT 'SKU ID',
            `combo_hash` VARCHAR(64) NOT NULL COMMENT '组合哈希值',
            `combo_text` VARCHAR(512) NOT NULL COMMENT '组合文字描述',
            `value_ids` TEXT NOT NULL COMMENT 'JSON数组，按维度顺序存储维度值ID',
            `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_goods_combo` (`goods_id`, `combo_hash`),
            UNIQUE KEY `uk_spec` (`spec_id`),
            KEY `idx_goods` (`goods_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='多维规格-SKU组合映射表'");

        // 商品标签表（与线上一致）
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_tag` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(64) NOT NULL DEFAULT '' COMMENT '标签名',
            `slug` VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'URL 别名',
            `color` VARCHAR(16) NOT NULL DEFAULT '' COMMENT '颜色',
            `sort` INT NOT NULL DEFAULT 0 COMMENT '排序',
            `goods_count` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '商品数（冗余）',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品标签'");

        // 商品标签关联表（与线上一致）
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_tag_relation` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `goods_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `tag_id` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_goods_tag` (`goods_id`, `tag_id`),
            KEY `idx_tag_id` (`tag_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='商品-标签关联'");

        // 商品会员等级价格表（与线上一致）
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_price_level` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `spec_id` INT UNSIGNED NOT NULL COMMENT '规格ID',
            `level_id` INT UNSIGNED NOT NULL COMMENT '用户等级ID',
            `price` BIGINT NOT NULL COMMENT '该等级在该规格的价格',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_spec_level` (`spec_id`, `level_id`),
            KEY `idx_spec` (`spec_id`),
            KEY `idx_level` (`level_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户等级价格表'");

        // 商品指定用户价格表（与线上一致）
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_price_user` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `spec_id` INT UNSIGNED NOT NULL COMMENT '规格ID',
            `user_id` INT UNSIGNED NOT NULL COMMENT '用户ID',
            `price` BIGINT NOT NULL COMMENT '该用户在该规格的价格',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_spec_user` (`spec_id`, `user_id`),
            KEY `idx_spec` (`spec_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='用户专属价格表'");

        // 虚拟商品卡密表（与线上一致）
        Database::statement("CREATE TABLE IF NOT EXISTS `{$prefix}goods_virtual_card` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `goods_id` INT UNSIGNED NOT NULL COMMENT '所属商品ID',
            `spec_id` INT UNSIGNED DEFAULT NULL COMMENT '所属规格ID（可选，关联 em_goods_spec.id）',
            `card_no` TEXT NOT NULL COMMENT '卡号/卡密内容',
            `card_pwd` TEXT COMMENT '卡密密码（可选，部分卡密格式需要）',
            `price` DECIMAL(10,2) DEFAULT NULL COMMENT '采购价格（可选，用于成本核算）',
            `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态：1=可用，0=已售出，2=已作废',
            `order_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '关联订单ID（与 em_order.id 对齐，避免订单ID超过42.9亿时截断）',
            `order_goods_id` BIGINT UNSIGNED DEFAULT NULL COMMENT '关联订单商品记录ID（与 em_order_goods.id 对齐）',
            `sold_at` DATETIME DEFAULT NULL COMMENT '售出时间',
            `remark` VARCHAR(255) DEFAULT NULL COMMENT '备注（如批次号、采购渠道等）',
            `sell_priority` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售优先级',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_goods` (`goods_id`),
            KEY `idx_goods_status` (`goods_id`, `status`),
            KEY `idx_goods_spec_status` (`goods_id`, `spec_id`, `status`, `id`),
            KEY `idx_spec` (`spec_id`),
            KEY `idx_status` (`status`),
            KEY `idx_order` (`order_id`),
            -- 「已删订单」查询专用，列序承重别调：
            -- goods_id 等值 → order_id 供 MIN(order_id) 迭代定位与 IN 范围扫
            -- → sold_at 紧跟等值前缀，让单订单 MIN/MAX(sold_at) 退化成索引定位
            -- → status 放最后，让 goods_id + status + order_id 组合也走覆盖索引
            KEY `idx_goods_order_sold` (`goods_id`, `order_id`, `sold_at`, `status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='虚拟商品（卡密）库存表'");
        // 商户等级表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(64) NOT NULL COMMENT \'等级名称\',
                `price` BIGINT NOT NULL DEFAULT 0 COMMENT \'自助开通价 ×1000000；0=不允许自助开通\',
                `self_goods_fee_rate` INT NOT NULL DEFAULT 0 COMMENT \'自建商品手续费率（万分位，500=5%%）\',
                `withdraw_fee_rate` INT NOT NULL DEFAULT 0 COMMENT \'提现手续费率（万分位）\',
                `sub_merchant_rebate_rate` INT NOT NULL DEFAULT 0 COMMENT \'作为上级时的子商户返佣比例（万分位）\',
                `allow_url_dir` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'允许 /s/{slug}/ 访问\',
                `allow_subdomain` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'允许二级域名\',
                `allow_custom_domain` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'允许自定义顶级域名\',
                `allow_self_goods` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'允许上架自建商品\',
                `allow_sub_merchant` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'允许发展子商户\',
                `sort` INT NOT NULL DEFAULT 100,
                `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_name` (`name`),
                KEY `idx_enabled` (`is_enabled`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户等级\'',
            $prefix . 'merchant_level'
        ));
        $hasLevel = Database::fetchOne(sprintf('SELECT `id` FROM `%s` LIMIT 1', $prefix . 'merchant_level'));
        if ($hasLevel === null) {
            Database::insert('merchant_level', [
                'name' => '普通商户',
                'price' => 0,
                'self_goods_fee_rate' => 500,
                'withdraw_fee_rate' => 0,
                'sub_merchant_rebate_rate' => 0,
                'allow_url_dir' => 1,
                'allow_subdomain' => 0,
                'allow_custom_domain' => 0,
                'allow_self_goods' => 1,
                'allow_sub_merchant' => 0,
                'sort' => 100,
                'is_enabled' => 1,
            ]);
        }

        // 商户主表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'商户主 user_id\',
                `parent_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'上级商户 id（一层）\',
                `level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'商户等级 id\',
                `name` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'店铺名\',
                `logo` VARCHAR(500) NOT NULL DEFAULT \'\',
                `slogan` VARCHAR(255) NOT NULL DEFAULT \'\',
                `description` TEXT,
                `announcement` LONGTEXT COMMENT \'商户独立店铺公告（富文本 HTML）\',
                `announcement_positions` VARCHAR(64) NOT NULL DEFAULT \'home\' COMMENT \'公告显示位置（逗号分隔：home/goods_list）\',
                `icp` VARCHAR(100) NOT NULL DEFAULT \'\',
                `subdomain` VARCHAR(64) DEFAULT NULL COMMENT \'二级域名前缀\',
                `custom_domain` VARCHAR(200) DEFAULT NULL COMMENT \'自定义域名\',
                `domain_verified` TINYINT(1) NOT NULL DEFAULT 0,
                `theme` VARCHAR(64) NOT NULL DEFAULT \'\' COMMENT \'模板名（v1 不生效）\',
                `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT \'1=正常 0=禁用\',
                `opened_at` DATETIME DEFAULT NULL,
                `opened_via` VARCHAR(16) NOT NULL DEFAULT \'admin\' COMMENT \'admin=后台手动 self=自助\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME DEFAULT NULL,
                `default_markup_rate` INT NOT NULL DEFAULT 1000 COMMENT \'默认加价率（万分位；1000=10%%）\',
                `enabled_plugins` TEXT NULL DEFAULT NULL COMMENT \'商户启用插件 slug（逗号分隔；空/NULL 表示未配置）\',
                `active_template_pc` VARCHAR(64) NOT NULL DEFAULT \'\',
                `active_template_mobile` VARCHAR(64) NOT NULL DEFAULT \'\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_user_id` (`user_id`),
                UNIQUE KEY `uk_subdomain` (`subdomain`),
                UNIQUE KEY `uk_custom_domain` (`custom_domain`(191)),
                KEY `idx_parent` (`parent_id`),
                KEY `idx_level` (`level_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户主数据\'',
            $prefix . 'merchant'
        ));

        // 商户商品引用表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL,
                `goods_id` INT UNSIGNED NOT NULL COMMENT \'主站商品 id\',
                `markup_rate` INT NOT NULL DEFAULT 0 COMMENT \'加价百分比（万分位；0=不加价）\',
                `merchant_category_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'商户自定义分类 id；0=沿用主站分类\',
                `is_on_sale` TINYINT(1) NOT NULL DEFAULT 1,
                `is_recommended` TINYINT(1) NULL DEFAULT NULL COMMENT \'推荐覆盖：NULL=跟随主站，1=推荐，0=不推荐\',
                `sort` INT NOT NULL DEFAULT 100,
                `pushed_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_merchant_goods` (`merchant_id`, `goods_id`),
                KEY `idx_goods` (`goods_id`),
                KEY `idx_merchant_sale` (`merchant_id`, `is_on_sale`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户引用主站商品\'',
            $prefix . 'goods_merchant_ref'
        ));

        // 商户分类表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL,
                `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
                `name` VARCHAR(100) NOT NULL,
                `icon` VARCHAR(255) NOT NULL DEFAULT \'\',
                `sort` INT NOT NULL DEFAULT 100,
                `status` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_merchant_parent` (`merchant_id`, `parent_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户自定义分类\'',
            $prefix . 'merchant_category'
        ));

        // 商户分类映射表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL,
                `master_category_id` INT UNSIGNED NOT NULL COMMENT \'主站 goods_category.id\',
                `alias_name` VARCHAR(100) NOT NULL DEFAULT \'\' COMMENT \'商户侧显示名（空=跟随主站名）\',
                `is_hidden` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1=在本店隐藏该主站分类\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_merchant_master` (`merchant_id`, `master_category_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户分类重命名/隐藏映射\'',
            $prefix . 'merchant_category_map'
        ));

        // 商户余额流水表
        //
        // uk_order_type 是结算幂等的兜底：同一订单同一类型只允许一条流水，
        // 防并发重复分账 / 重复退款（代码侧的「先查后写」不是原子的）。
        // withdraw / withdraw_fee 两类流水的 order_id 为 NULL，而 MySQL 唯一索引
        // 视 NULL 互不相同 —— 所以它们不受该约束，这正是该索引可行的前提，勿改成 NOT NULL。
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL,
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'商户主 user_id（冗余便查）\',
                `type` VARCHAR(20) NOT NULL COMMENT \'increase/decrease/refund/withdraw/withdraw_fee/sub_rebate/adjust\',
                `amount` BIGINT NOT NULL COMMENT \'变动金额 ×1000000 正数\',
                `before_balance` BIGINT NOT NULL,
                `after_balance` BIGINT NOT NULL,
                `order_id` BIGINT UNSIGNED DEFAULT NULL,
                `withdraw_id` BIGINT UNSIGNED DEFAULT NULL,
                `source_merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'sub_rebate 类型下的来源子商户\',
                `remark` VARCHAR(255) NOT NULL DEFAULT \'\',
                `operator_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=系统自动\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_merchant_time` (`merchant_id`, `created_at`),
                KEY `idx_order` (`order_id`),
                UNIQUE KEY `uk_order_type` (`order_id`, `type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户店铺余额流水\'',
            $prefix . 'merchant_balance_log'
        ));

        // 商户提现记录表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL,
                `user_id` BIGINT UNSIGNED NOT NULL,
                `amount` BIGINT NOT NULL COMMENT \'提现毛额 ×1000000\',
                `fee_amount` BIGINT NOT NULL DEFAULT 0 COMMENT \'手续费 ×1000000\',
                `net_amount` BIGINT NOT NULL COMMENT \'实到账 = amount - fee_amount\',
                `before_balance` BIGINT NOT NULL,
                `after_balance` BIGINT NOT NULL,
                `target` VARCHAR(16) NOT NULL DEFAULT \'money\' COMMENT \'money=提现到消费余额\',
                `status` VARCHAR(16) NOT NULL DEFAULT \'done\' COMMENT \'pending/done/rejected\',
                `audit_remark` VARCHAR(255) NOT NULL DEFAULT \'\',
                `audited_at` DATETIME DEFAULT NULL,
                `audited_by` BIGINT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_merchant_status` (`merchant_id`, `status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'商户提现记录\'',
            $prefix . 'merchant_withdraw'
        ));

        // 页面表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `merchant_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'0=主站；>0=商户\',
                `title` VARCHAR(200) NOT NULL COMMENT \'页面标题\',
                `slug` VARCHAR(100) NOT NULL COMMENT \'URL 别名，访问 /p/{slug}\',
                `content` LONGTEXT COMMENT \'页面正文 HTML（富文本）\',
                `status` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'0=草稿, 1=已发布\',
                `is_homepage` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1=该 scope 的站点首页（同 merchant_id 最多一条）\',
                `template_name` VARCHAR(50) NOT NULL DEFAULT \'\' COMMENT \'指定主题模板文件名（不含 page- 前缀和扩展名），空=用通用 page.php\',
                `seo_title` VARCHAR(200) NOT NULL DEFAULT \'\',
                `seo_keywords` VARCHAR(500) NOT NULL DEFAULT \'\',
                `seo_description` VARCHAR(500) NOT NULL DEFAULT \'\',
                `sort` INT NOT NULL DEFAULT 100 COMMENT \'数值越小越靠前\',
                `views_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `deleted_at` DATETIME NULL DEFAULT NULL COMMENT \'软删除时间\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_merchant_slug` (`merchant_id`, `slug`),
                KEY `idx_status` (`status`),
                KEY `idx_deleted` (`deleted_at`),
                KEY `idx_merchant_status` (`merchant_id`, `status`, `deleted_at`),
                KEY `idx_merchant_homepage` (`merchant_id`, `is_homepage`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'自定义页面（WordPress 式 Pages）\'',
            $prefix . 'page'
        ));

        // 商户隐藏导航表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `merchant_id` INT UNSIGNED NOT NULL COMMENT \'商户 ID\',
                `navi_id` BIGINT UNSIGNED NOT NULL COMMENT \'导航 ID（必须 is_system=1）\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`merchant_id`, `navi_id`),
                KEY `idx_navi` (`navi_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT=\'商户隐藏的系统导航\'',
            $prefix . 'merchant_navi_hidden'
        ));

        // 货币表（与线上一致）
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `code` VARCHAR(3) NOT NULL COMMENT \'货币代码（3位大写字母，如 CNY）\',
                `name` VARCHAR(30) NOT NULL COMMENT \'货币名称\',
                `symbol` VARCHAR(10) NOT NULL DEFAULT \'\' COMMENT \'货币符号\',
                `rate` BIGINT NOT NULL DEFAULT 0 COMMENT \'兑主货币汇率\',
                `is_primary` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'是否为主货币（1=是，0=否）\',
                `is_frontend_default` TINYINT(1) NOT NULL DEFAULT 0 COMMENT \'1=前台默认；全站唯一\',
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0 COMMENT \'排序权重（越小越靠前）\',
                `created_at` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'创建时间戳\',
                `updated_at` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'更新时间戳\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_code` (`code`),
                KEY `idx_is_primary` (`is_primary`),
                KEY `idx_sort_order` (`sort_order`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'货币配置表\'',
            $prefix . 'currency'
        ));
        $hasCurrency = Database::fetchOne(sprintf('SELECT `id` FROM `%s` LIMIT 1', $prefix . 'currency'));
        if ($hasCurrency === null) {
            $now = time();
            Database::insert('currency', [
                'code' => 'CNY',
                'name' => '人民币',
                'symbol' => '¥',
                'rate' => 1000000,
                'is_primary' => 1,
                'is_frontend_default' => 1,
                'enabled' => 1,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 用户地址表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL COMMENT \'em_user.id\',
                `recipient` VARCHAR(50) NOT NULL DEFAULT \'\' COMMENT \'收件人姓名\',
                `mobile` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'收件人手机\',
                `province` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'省/直辖市快照\',
                `city` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'市快照\',
                `district` VARCHAR(32) NOT NULL DEFAULT \'\' COMMENT \'区/县快照\',
                `detail` VARCHAR(255) NOT NULL DEFAULT \'\' COMMENT \'详细地址（街道门牌）\',
                `is_default` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT \'1=默认地址\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user` (`user_id`),
                KEY `idx_user_default` (`user_id`, `is_default`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'用户收货地址（核心通用数据）\'',
            $prefix . 'user_address'
        ));

        // 密码重置令牌表
        Database::statement(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT \'自增主键\',
                `user_id` BIGINT UNSIGNED NOT NULL COMMENT \'用户ID\',
                `email` VARCHAR(120) NOT NULL COMMENT \'申请重置时的邮箱\',
                `token_hash` CHAR(64) NOT NULL COMMENT \'令牌 SHA256 哈希\',
                `expires_at` DATETIME NOT NULL COMMENT \'过期时间\',
                `used_at` DATETIME DEFAULT NULL COMMENT \'使用时间\',
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT \'创建时间\',
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq_token_hash` (`token_hash`),
                KEY `idx_user_id` (`user_id`),
                KEY `idx_email_created` (`email`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'密码重置令牌\'',
            $prefix . 'password_reset'
        ));

        // 随包迁移已在建表 SQL 中体现，标记为已执行，避免首次在线升级重复 ALTER
        UpdateService::markBundledMigrationsApplied();
    }
}
