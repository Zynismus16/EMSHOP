-- 虚拟商品（卡密）库存表
-- 插件：virtual_card

CREATE TABLE IF NOT EXISTS `em_goods_virtual_card` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `goods_id` INT UNSIGNED NOT NULL COMMENT '所属商品ID',
    `spec_id` INT UNSIGNED DEFAULT NULL COMMENT '所属规格ID（可选，关联 em_goods_spec.id）',
    `card_no` TEXT NOT NULL COMMENT '卡号/卡密内容',
    `card_pwd` TEXT DEFAULT NULL COMMENT '卡密密码（可选，部分卡密格式需要）',
    `price` DECIMAL(10,2) DEFAULT NULL COMMENT '采购价格（可选，用于成本核算）',
    `status` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '状态：1=可用，0=已售出，2=已作废',
    `order_id` INT UNSIGNED DEFAULT NULL COMMENT '关联订单ID',
    `order_goods_id` INT UNSIGNED DEFAULT NULL COMMENT '关联订单商品记录ID',
    `sold_at` DATETIME DEFAULT NULL COMMENT '售出时间',
    `remark` VARCHAR(255) DEFAULT NULL COMMENT '备注（如批次号、采购渠道等）',
    `sell_priority` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '销售优先级（0=默认，时间戳值越大优先级越高）',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_goods` (`goods_id`),
    KEY `idx_goods_status` (`goods_id`, `status`),
    KEY `idx_goods_spec_status` (`goods_id`, `spec_id`, `status`, `id`),
    KEY `idx_spec` (`spec_id`),
    KEY `idx_status` (`status`),
    KEY `idx_order` (`order_id`),
    -- 「已删订单」相关查询专用。列序是承重的，别调：
    --   goods_id 等值 → order_id 供 MIN(order_id) 迭代定位与 IN 范围扫
    --   → sold_at 紧跟等值前缀，让单订单 MIN/MAX(sold_at) 退化成索引定位
    --   → status 放最后，让 goods_id + status + order_id 组合也能走覆盖索引
    KEY `idx_goods_order_sold` (`goods_id`, `order_id`, `sold_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='虚拟商品（卡密）库存表';
