<?php
/**
 * 商品模型
 *
 * 金额字段统一使用 BIGINT 存储（实际金额 × MONEY_SCALE），读取时还原
 *
 * @package EM\Core\Model
 */

if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

class GoodsModel
{
    /** 金额放大倍数：1,000,000 */
    const MONEY_SCALE = 1000000;

    /**
     * 将前端金额转为数据库存储值（× MONEY_SCALE）
     *
     * @param mixed $amount 前端金额（如 0.01）
     * @return int 数据库存储值（如 10000）
     */
    public static function moneyToDb($amount): int
    {
        return (int)round((float)$amount * self::MONEY_SCALE);
    }

    /**
     * 将数据库存储值还原为前端金额（÷ MONEY_SCALE）
     *
     * @param mixed $dbValue 数据库存储值
     * @return string 保留两位小数的金额字符串
     */
    public static function moneyFromDb($dbValue): ?string
    {
        if ($dbValue === null) return null;
        return number_format((int)$dbValue / self::MONEY_SCALE, 2, '.', '');
    }

    /**
     * 批量转换数组中的金额字段（DB → 前端）
     *
     * @param array &$row 数据行
     * @param array $fields 需要转换的字段名列表
     */
    private static function convertMoneyFields(array &$row, array $fields): void
    {
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $row[$field] = self::moneyFromDb($row[$field]);
            }
        }
    }

    /**
     * 生成商品唯一标识 code
     * 格式：前缀 + 日期 + 6位序号，如 G202604090001
     */
    public static function generateCode($prefix = 'G')
    {
        $date = date('Ymd');
        $like = $prefix . $date . '%';

        $last = Database::query(
            "SELECT code FROM " . Database::prefix() . "goods WHERE code LIKE ? ORDER BY id DESC LIMIT 1",
            [$like]
        );

        if (empty($last)) {
            $seq = 1;
        } else {
            $lastCode = $last[0]['code'];
            $seq = (int)substr($lastCode, -6) + 1;
        }

        return $prefix . $date . str_pad($seq, 6, '0', STR_PAD_LEFT);
    }

    /**
     * 获取商品列表（金额字段自动转换为前端展示值）
     */
    public static function getList($where = [], $page = 1, $limit = 20, $orderBy = 'id DESC')
    {
        $conditions = [];
        $params = [];

        if (isset($where['owner_id']) && $where['owner_id'] !== '') {
            $conditions[] = 'g.owner_id = ?';
            $params[] = $where['owner_id'];
        }

        if (isset($where['status']) && $where['status'] !== '') {
            $conditions[] = 'g.status = ?';
            $params[] = $where['status'];
        }

        if (isset($where['is_on_sale']) && $where['is_on_sale'] !== '') {
            $conditions[] = 'g.is_on_sale = ?';
            $params[] = $where['is_on_sale'];
        }

        if (!empty($where['category_id'])) {
            $conditions[] = 'g.category_id = ?';
            $params[] = $where['category_id'];
        }

        if (!empty($where['keyword'])) {
            $conditions[] = '(g.title LIKE ? OR g.code LIKE ? OR g.intro LIKE ?)';
            $keyword = '%' . $where['keyword'] . '%';
            $params[] = $keyword;
            $params[] = $keyword;
            $params[] = $keyword;
        }

        if (!empty($where['goods_type'])) {
            $conditions[] = 'g.goods_type = ?';
            $params[] = $where['goods_type'];
        }

        if (isset($where['is_recommended']) && $where['is_recommended'] !== '') {
            $conditions[] = 'g.is_recommended = ?';
            $params[] = (int) $where['is_recommended'];
        }

        // 默认排除已软删除的商品
        $conditions[] = 'g.deleted_at IS NULL';

        $whereSql = 'WHERE ' . implode(' AND ', $conditions);

        $total = Database::query(
            "SELECT COUNT(*) as count FROM " . Database::prefix() . "goods g
            LEFT JOIN " . Database::prefix() . "goods_category c ON g.category_id = c.id
            $whereSql",
            $params
        );
        $totalCount = (int)($total[0]['count'] ?? 0);

        $offset = ($page - 1) * $limit;
        $sql = "SELECT g.*,
            c.name as category_name,
            (SELECT COALESCE(SUM(sold_count), 0) FROM " . Database::prefix() . "goods_spec WHERE goods_id = g.id) as total_sales
            FROM " . Database::prefix() . "goods g
            LEFT JOIN " . Database::prefix() . "goods_category c ON g.category_id = c.id
            $whereSql ORDER BY $orderBy LIMIT $offset, $limit";
        $list = Database::query($sql, $params);

        // 转换金额字段（BIGINT → 前端展示值）
        foreach ($list as &$row) {
            self::convertMoneyFields($row, ['min_price', 'max_price']);
        }
        unset($row);

        return [
            'total' => $totalCount,
            'page' => $page,
            'limit' => $limit,
            'list' => $list,
        ];
    }

    /**
     * 获取商品详情（金额字段自动转换）。
     *
     * 商户上下文下（MerchantContext 激活）额外做两件事：
     *   1. 可见性过滤：不属于本店（既非自建、也非引用上架）→ 返回 null
     *   2. 价格重写：引用商品对客价 = 主站售价 × (1+markup)；站长拿货价仅用于 _owner_cost_raw / 分账
     *
     * 主站上下文或 admin 调用路径（MerchantContext::currentId()=0）行为完全不变。
     *
     * @param int $id 商品 ID
     * @param bool $applyFactor 是否应用价格 factor（买家折扣 / 商户加价率）。
     *                          后台编辑表单读取场景应传 false，让价格按 DB 原值返回，
     *                          避免编辑面板上的成交价配置被买家折扣污染。
     */
    public static function getById($id, bool $applyFactor = true)
    {
        $result = Database::query(
            "SELECT * FROM " . Database::prefix() . "goods WHERE id = ? LIMIT 1",
            [$id]
        );
        $row = $result[0] ?? null;
        if (!$row) return $row;

        // 价格 factor 计算：
        //   - 主站作用域：factor = buyer_discount（仅买家会员价生效）
        //   - 商户作用域 · 主站引用商品：对客 factor = (1+markup)；会员折扣已并入「主站售价」再加价
        //   - 商户作用域 · 自建商品：factor = buyer_discount（无 markup，按规格原价 × 折扣）
        //   - 商户作用域 · 跨商户的自建商品：不可见
        //
        // user_levels.discount 现在仅作"买家会员折扣"用 —— 商户拿货成本由主站按原价收，与折扣无关。
        // applyFactor=false 时直接置 1，让 DB 原值穿透返回（后台编辑面板需要这个）。
        $buyerDiscount = $applyFactor ? self::resolveBuyerDiscountRate() : 1.0;
        $markup = 0;
        $factor = $buyerDiscount;

        $merchantId = class_exists('MerchantContext') ? MerchantContext::currentId() : 0;
        if ($merchantId > 0) {
            $ownerUserId = MerchantContext::currentOwnerId();
            $goodsOwner = (int) ($row['owner_id'] ?? 0);

            if ($ownerUserId > 0 && $goodsOwner === $ownerUserId) {
                // 自建商品 → markup=0，仅买家折扣
                // 加 ownerUserId > 0 防御：避免商户 user_id=0 异常时，把 owner_id=0 的主站商品
                // 当成"自建"漏出，绕过下方 ref.is_on_sale 的下架过滤
                $factor = $buyerDiscount;
            } elseif ($goodsOwner === 0) {
                // 主站货：默认全部可见；商户覆盖行存在时才按覆盖来
                $ref = Database::fetchOne(
                    'SELECT `markup_rate`, `is_on_sale` FROM `' . Database::prefix() . 'goods_merchant_ref`
                      WHERE `merchant_id` = ? AND `goods_id` = ? LIMIT 1',
                    [$merchantId, (int) $row['id']]
                );
                $isOnSale = $ref ? (int) $ref['is_on_sale'] : 1;
                if ($isOnSale !== 1) {
                    return null; // 商户显式下架 → 本店不可见
                }
                // 加价率：覆盖行 > 商户默认值（em_merchant.default_markup_rate）> 0
                $markup = $ref ? (int) $ref['markup_rate'] : self::resolveMerchantDefaultMarkup($merchantId);
                // applyFactor=false 时不参与加价 / 不打折，让后台 ref 编辑表单看到主站原值
                $factor = $applyFactor ? (1 + $markup / 10000) : 1.0;
            } else {
                // 其他商户的自建商品 → 不可见
                return null;
            }
        }

        $row['_shop_markup_rate'] = $markup;
        $row['_shop_price_factor'] = $factor;
        // 分站主站引用商品：列表/详情价以各规格对客成交价（主站售价×加价）为准
        if ($applyFactor && $merchantId > 0 && (int) ($row['owner_id'] ?? 0) === 0) {
            $specs = self::getSpecsByGoodsId((int) $row['id'], true);
            $priceRaws = [];
            foreach ($specs as $s) {
                $pr = (int) ($s['price_raw'] ?? 0);
                if ($pr > 0) {
                    $priceRaws[] = $pr;
                }
            }
            if ($priceRaws !== []) {
                $row['min_price'] = min($priceRaws);
                $row['max_price'] = max($priceRaws);
            }
        } elseif ($factor !== 1.0) {
            if (isset($row['min_price'])) {
                $row['min_price'] = (int) round(((int) $row['min_price']) * $factor);
            }
            if (isset($row['max_price'])) {
                $row['max_price'] = (int) round(((int) $row['max_price']) * $factor);
            }
        }

        self::convertMoneyFields($row, ['min_price', 'max_price']);
        return $row;
    }

    /**
     * 商户默认加价率（万分位）。没配 / 读不到 → 1000（10%，InstallService 的默认值）。
     * 同一请求内缓存。
     */
    private static function resolveMerchantDefaultMarkup(int $merchantId): int
    {
        static $cache = [];
        if (isset($cache[$merchantId])) return $cache[$merchantId];
        $row = Database::fetchOne(
            'SELECT `default_markup_rate` FROM `' . Database::prefix() . 'merchant` WHERE `id` = ? LIMIT 1',
            [$merchantId]
        );
        return $cache[$merchantId] = (int) ($row['default_markup_rate'] ?? 1000);
    }

    /**
     * 当前登录买家的等级折扣率（9.9 折 → 0.99）。
     *
     * 语义：user_levels.discount 是**买家会员价**折扣，仅在买家登录后看自己的价格时生效；
     * 未登录访客 / 未配等级 / 等级被禁用一律返回 1.0（公开标价不打折）。
     * 商户拿货成本与本折扣无关 —— 主站对商户始终按原价收。
     *
     * 整个请求级缓存：同一次请求里多次取价格只查一次 DB。
     */
    public static function resolveBuyerDiscountRate(): float
    {
        [, $buyerLevelId] = self::resolveBuyerIdentity();
        static $cachedByLevelId = [];
        if (isset($cachedByLevelId[$buyerLevelId])) {
            return $cachedByLevelId[$buyerLevelId];
        }
        if ($buyerLevelId <= 0) {
            return $cachedByLevelId[$buyerLevelId] = 1.0;
        }
        $row = Database::fetchOne(
            'SELECT `discount` AS d
               FROM `' . Database::prefix() . 'user_levels`
              WHERE `id` = ? AND `enabled` = \'y\'
              LIMIT 1',
            [$buyerLevelId]
        );
        $raw = (int) ($row['d'] ?? 0);
        if ($raw <= 0) {
            return $cachedByLevelId[$buyerLevelId] = 1.0;
        }
        $rate = ($raw / 1000000) / 10;        // 9.5 → 0.95
        if ($rate <= 0 || $rate > 1) $rate = 1.0;
        return $cachedByLevelId[$buyerLevelId] = $rate;
    }

    /**
     * 当前登录买家身份（user_id + level_id）。
     *
     * @return array{0:int,1:int}
     */
    public static function resolveBuyerIdentity(): array
    {
        $forced = $GLOBALS['em_force_buyer_identity'] ?? null;
        if (is_array($forced)) {
            $forcedBuyerId = (int) ($forced[0] ?? 0);
            if ($forcedBuyerId > 0) {
                return [
                    $forcedBuyerId,
                    (int) ($forced[1] ?? 0),
                ];
            }
        }

        if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
            @session_start();
        }
        $buyerId = (int) ($_SESSION['em_front_user']['id'] ?? 0);
        static $cachedByBuyerId = [];
        if (isset($cachedByBuyerId[$buyerId])) {
            return $cachedByBuyerId[$buyerId];
        }
        if ($buyerId <= 0) {
            return $cachedByBuyerId[$buyerId] = [0, 0];
        }

        $row = Database::fetchOne(
            'SELECT `level_id` FROM `' . Database::prefix() . 'user` WHERE `id` = ? LIMIT 1',
            [$buyerId]
        );
        $levelId = (int) ($row['level_id'] ?? 0);
        return $cachedByBuyerId[$buyerId] = [$buyerId, $levelId];
    }

    /**
     * 主站侧某规格「售价」（×1000000）：买家专属/等级专属优先（视作最终价），否则原价×会员折扣。
     */
    public static function resolveMainSiteSellUnitPrice(
        int $basePriceRaw,
        bool $hasBuyerExclusive,
        int $exclusivePriceRaw,
        float $buyerDiscountRate
    ): int {
        if ($hasBuyerExclusive) {
            return $exclusivePriceRaw;
        }
        return (int) round($basePriceRaw * $buyerDiscountRate);
    }

    /**
     * 某规格对指定用户的有效拿货底价（×1000000）：
     * 用户专属价（em_goods_price_user）> 等级专属价（em_goods_price_level）> 规格原价 × 折扣率。
     *
     * 仅用于分站站长成本/利润，不参与对客售价。
     */
    public static function resolveSpecWholesaleUnitPrice(
        int $basePriceRaw,
        int $specId,
        int $userId,
        int $levelId,
        float $discountRate = 1.0
    ): int {
        $prefix = Database::prefix();
        if ($specId > 0 && $userId > 0) {
            $row = Database::fetchOne(
                "SELECT `price` FROM `{$prefix}goods_price_user` WHERE `user_id` = ? AND `spec_id` = ? LIMIT 1",
                [$userId, $specId]
            );
            if ($row !== null && isset($row['price'])) {
                return (int) $row['price'];
            }
        }
        if ($specId > 0 && $levelId > 0) {
            $row = Database::fetchOne(
                "SELECT `price` FROM `{$prefix}goods_price_level` WHERE `level_id` = ? AND `spec_id` = ? LIMIT 1",
                [$levelId, $specId]
            );
            if ($row !== null && isset($row['price'])) {
                return (int) $row['price'];
            }
        }
        return (int) round($basePriceRaw * $discountRate);
    }

    /**
     * 商品在售规格的有效拿货价区间（用于商户后台主站商品列表等）。
     *
     * @return array{min:int,max:int}
     */
    public static function resolveGoodsWholesalePriceRange(int $goodsId, int $userId, float $discountRate = 1.0): array
    {
        if ($goodsId <= 0) {
            return ['min' => 0, 'max' => 0];
        }
        $levelId = 0;
        if ($userId > 0) {
            $row = Database::fetchOne(
                'SELECT `level_id` FROM `' . Database::prefix() . 'user` WHERE `id` = ? LIMIT 1',
                [$userId]
            );
            $levelId = (int) ($row['level_id'] ?? 0);
        }
        $specs = Database::query(
            'SELECT `id`, `price` FROM `' . Database::prefix() . 'goods_spec`
              WHERE `goods_id` = ? AND `status` = 1 ORDER BY `sort` ASC, `id` ASC',
            [$goodsId]
        );
        if ($specs === []) {
            return ['min' => 0, 'max' => 0];
        }
        $min = PHP_INT_MAX;
        $max = 0;
        foreach ($specs as $spec) {
            $unit = self::resolveSpecWholesaleUnitPrice(
                (int) ($spec['price'] ?? 0),
                (int) ($spec['id'] ?? 0),
                $userId,
                $levelId,
                $discountRate
            );
            $min = min($min, $unit);
            $max = max($max, $unit);
        }
        if ($min === PHP_INT_MAX) {
            $min = 0;
        }
        return ['min' => $min, 'max' => $max];
    }

    /**
     * 商品是否存在针对该用户（或其所处等级）的规格专属拿货价配置。
     */
    public static function goodsHasWholesaleExclusivePrice(int $goodsId, int $userId): bool
    {
        if ($goodsId <= 0) {
            return false;
        }
        $prefix = Database::prefix();
        $specRows = Database::query(
            'SELECT `id` FROM `' . $prefix . 'goods_spec` WHERE `goods_id` = ? AND `status` = 1',
            [$goodsId]
        );
        if ($specRows === []) {
            return false;
        }
        $specIds = array_values(array_filter(array_map(static fn($r) => (int) ($r['id'] ?? 0), $specRows)));
        if ($specIds === []) {
            return false;
        }
        $placeholders = implode(',', array_fill(0, count($specIds), '?'));
        if ($userId > 0) {
            $hit = Database::fetchOne(
                "SELECT 1 FROM `{$prefix}goods_price_user` WHERE `user_id` = ? AND `spec_id` IN ({$placeholders}) LIMIT 1",
                array_merge([$userId], $specIds)
            );
            if ($hit !== null) {
                return true;
            }
        }
        $levelId = 0;
        if ($userId > 0) {
            $u = Database::fetchOne(
                'SELECT `level_id` FROM `' . $prefix . 'user` WHERE `id` = ? LIMIT 1',
                [$userId]
            );
            $levelId = (int) ($u['level_id'] ?? 0);
        }
        if ($levelId > 0) {
            $hit = Database::fetchOne(
                "SELECT 1 FROM `{$prefix}goods_price_level` WHERE `level_id` = ? AND `spec_id` IN ({$placeholders}) LIMIT 1",
                array_merge([$levelId], $specIds)
            );
            if ($hit !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * 根据商品编码获取商品
     */
    public static function getByCode($code)
    {
        $result = Database::query(
            "SELECT * FROM " . Database::prefix() . "goods WHERE code = ? LIMIT 1",
            [$code]
        );
        $row = $result[0] ?? null;
        if ($row) {
            self::convertMoneyFields($row, ['min_price', 'max_price']);
        }
        return $row;
    }

    /**
     * 后台/商户保存商品时合并 configs：通用表单只提交 extra_fields、返佣、SEO 等，
     * 若直接整体覆盖 DB 中的 configs，会抹掉对接插件写入的命名空间（如 ycy_shared 的 delivery_way），
     * 导致误判发货方式、下单校验异常等。
     *
     * @param array<string, mixed> $configs 本次由 POST 拼装出的 configs 片段
     * @return array<string, mixed>
     */
    public static function mergeConfigsOnSave(int $goodsId, array $configs): array
    {
        if ($goodsId <= 0) {
            return $configs;
        }
        $existing = self::getById($goodsId, false);
        if ($existing === null) {
            return $configs;
        }
        $old = json_decode((string) ($existing['configs'] ?? '{}'), true);
        if (!is_array($old) || $old === []) {
            return $configs;
        }
        /** @var list<string> $nsList */
        $nsList = applyFilter('goods_save_preserve_config_namespaces', ['ycy_shared', 'emshop_import']);
        if (!is_array($nsList)) {
            $nsList = ['ycy_shared', 'emshop_import'];
        }
        foreach ($nsList as $ns) {
            $ns = trim((string) $ns);
            if ($ns === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $ns)) {
                continue;
            }
            if (empty($old[$ns]) || !is_array($old[$ns])) {
                continue;
            }
            if (empty($configs[$ns]) || !is_array($configs[$ns])) {
                $configs[$ns] = $old[$ns];
            } else {
                $configs[$ns] = array_merge($old[$ns], $configs[$ns]);
            }
        }

        return $configs;
    }

    /**
     * 创建商品
     */
    public static function create($data)
    {
        if (empty($data['code'])) {
            $data['code'] = self::generateCode();
        }

        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');

        $id = Database::insert('goods', $data);
        if ($id) {
            doAction('goods_after_create', $id, $data);
        }
        return $id;
    }

    /**
     * 更新商品
     */
    public static function update($id, $data)
    {
        $id = (int) $id;
        $data['updated_at'] = date('Y-m-d H:i:s');

        $old = self::getById($id);
        $result = Database::update('goods', $data, $id);

        if ($result) {
            doAction('goods_after_update', $id, $old, $data);
            self::logChange($id, $old, $data);
        }

        return $result;
    }

    /**
     * 删除商品（逻辑删除）
     */
    public static function delete($id)
    {
        $id = (int) $id;
        $result = Database::update('goods', [
            'status' => 0,
            'deleted_at' => date('Y-m-d H:i:s'),
        ], $id);
        if ($result) {
            doAction('goods_after_delete', $id);
        }
        return $result;
    }

    /**
     * 物理删除商品
     */
    public static function forceDelete($id)
    {
        $id = (int) $id;
        $prefix = Database::prefix();

        // 先通知插件清理各自的关联数据（如卡密库存、物流信息等），
        // 此时规格数据尚存，插件可据此做清理
        doAction('goods_before_force_delete', $id);

        Database::execute(
            "DELETE FROM {$prefix}goods_price_level WHERE spec_id IN (SELECT id FROM {$prefix}goods_spec WHERE goods_id = ?)",
            [$id]
        );
        Database::execute(
            "DELETE FROM {$prefix}goods_price_user WHERE spec_id IN (SELECT id FROM {$prefix}goods_spec WHERE goods_id = ?)",
            [$id]
        );

        Database::execute("DELETE FROM {$prefix}goods_spec_combo WHERE goods_id = ?", [$id]);
        Database::execute("DELETE FROM {$prefix}goods_spec_value WHERE goods_id = ?", [$id]);
        Database::execute("DELETE FROM {$prefix}goods_spec_dim WHERE goods_id = ?", [$id]);
        Database::execute("DELETE FROM {$prefix}goods_spec WHERE goods_id = ?", [$id]);

        return Database::execute("DELETE FROM {$prefix}goods WHERE id = ?", [$id]);
    }

    /**
     * 设置/取消分类置顶
     */
    public static function setTopCategory($id, $value)
    {
        $id = (int) $id;
        return (bool)Database::update('goods', ['is_top_category' => $value ? 1 : 0], $id);
    }

    /**
     * 设置/取消推荐商品
     */
    public static function setRecommended($id, $value)
    {
        $id = (int) $id;
        return (bool)Database::update('goods', ['is_recommended' => $value ? 1 : 0], $id);
    }

    /**
     * 克隆商品
     */
    public static function clone($id)
    {
        $old = self::getById($id);
        if (!$old) {
            return false;
        }

        $prefix = Database::prefix();

        $newData = [];
        $skipFields = ['id', 'code', 'views_count', 'created_at', 'updated_at'];
        // min_price / max_price 已被 getById 转为前端值，克隆时需还原为 DB 值
        $moneyFields = ['min_price', 'max_price'];
        foreach ($old as $key => $value) {
            // 过滤运行期注入的虚拟字段（如 _shop_markup_rate），避免写入不存在的表列
            if (strpos((string) $key, '_') === 0) {
                continue;
            }
            if (!in_array($key, $skipFields, true)) { 
                if (in_array($key, $moneyFields)) {
                    $newData[$key] = self::moneyToDb($value);
                } else {
                    $newData[$key] = $value;
                }
            }
        }
        $newData['title'] = $old['title'] . '（克隆）';
        $newData['code'] = self::generateCode();
        $newData['views_count'] = 0;

        $newId = self::create($newData);
        if (!$newId) {
            return false;
        }

        // 复制规格（从数据库直接读取原始 BIGINT 值，不经过转换）
        $specIdMap = [];
        $rawSpecs = Database::query(
            "SELECT * FROM {$prefix}goods_spec WHERE goods_id = ? AND status = 1 ORDER BY sort ASC, id ASC",
            [$id]
        );
        foreach ($rawSpecs as $spec) {
            $oldSpecId = $spec['id'];
            $newSpec = [];
            $skipSpecFields = ['id', 'goods_id', 'sold_count', 'created_at', 'updated_at'];
            foreach ($spec as $key => $value) {
                if (!in_array($key, $skipSpecFields)) {
                    $newSpec[$key] = $value;
                }
            }
            $newSpec['goods_id'] = $newId;
            $newSpec['sold_count'] = 0;
            $newSpecId = Database::insert('goods_spec', $newSpec);
            $specIdMap[$oldSpecId] = $newSpecId;
        }

        // 复制多维规格数据
        $oldDims = Database::query(
            "SELECT * FROM {$prefix}goods_spec_dim WHERE goods_id = ? ORDER BY sort ASC",
            [$id]
        );
        $dimIdMap = [];
        $valueIdMap = [];

        foreach ($oldDims as $dim) {
            $newDimId = Database::insert('goods_spec_dim', [
                'goods_id' => $newId,
                'name' => $dim['name'],
                'sort' => $dim['sort'],
            ]);
            $dimIdMap[$dim['id']] = $newDimId;

            $oldValues = Database::query(
                "SELECT * FROM {$prefix}goods_spec_value WHERE dim_id = ? ORDER BY sort ASC",
                [$dim['id']]
            );
            foreach ($oldValues as $val) {
                $newValId = Database::insert('goods_spec_value', [
                    'dim_id' => $newDimId,
                    'goods_id' => $newId,
                    'name' => $val['name'],
                    'cover_image' => $val['cover_image'],
                    'sort' => $val['sort'],
                ]);
                $valueIdMap[$val['id']] = $newValId;
            }
        }

        // 复制 SKU 组合映射
        $oldCombos = Database::query(
            "SELECT * FROM {$prefix}goods_spec_combo WHERE goods_id = ? ORDER BY sort ASC",
            [$id]
        );
        foreach ($oldCombos as $combo) {
            $newSpecId = $specIdMap[$combo['spec_id']] ?? null;
            if (!$newSpecId) continue;

            $oldValueIds = json_decode($combo['value_ids'], true) ?: [];
            $newValueIds = [];
            foreach ($oldValueIds as $oldVid) {
                $newValueIds[] = $valueIdMap[$oldVid] ?? $oldVid;
            }

            $newComboHash = md5(implode('|', $newValueIds));

            Database::insert('goods_spec_combo', [
                'goods_id' => $newId,
                'spec_id' => $newSpecId,
                'combo_hash' => $newComboHash,
                'combo_text' => $combo['combo_text'],
                'value_ids' => json_encode($newValueIds),
                'sort' => $combo['sort'],
            ]);
        }

        doAction('goods_after_clone', $newId, $id);
        return $newId;
    }

    /**
     * 获取商品的所有规格（金额字段自动转换）。
     *
     * 价格 factor 同 getById 的规则：
     *   - 主站：factor = buyer_discount
     *   - 商户站 主站引用商品：对客价 = 主站售价 × (1+markup)；_owner_cost_raw = 站长拿货价
     *   - 商户站 自建商品：factor = buyer_discount
     *
     * 每条 spec 挂三个内部字段供 OrderModel 用：
     *   _base_price_raw    —— 主站规格原价（未乘 factor）
     *   _owner_cost_raw    —— 站长拿货单价（引用主站货时写入，分账用）
     *   price_raw          —— 对客成交单价（分站引用货 = 主站售价×(1+markup)）
     *   _shop_markup_rate  —— 加价率（万分位），主要给前端展示用
     *
     * @param int $goodsId 商品 ID
     * @param bool $applyFactor 是否应用价格 factor。后台编辑/库存场景应传 false，
     *                          让 spec 价格按 DB 原值返回，避免编辑面板被买家折扣污染。
     */
    public static function getSpecsByGoodsId($goodsId, bool $applyFactor = true)
    {
        $specs = Database::query(
            "SELECT * FROM " . Database::prefix() . "goods_spec WHERE goods_id = ? AND status = 1 ORDER BY sort ASC, id ASC",
            [$goodsId]
        );
        if ($specs === []) return $specs;

        [$buyerId, $buyerLevelId] = $applyFactor ? self::resolveBuyerIdentity() : [0, 0];
        $specUserPriceMap = [];
        $specLevelPriceMap = [];
        if ($applyFactor && $buyerId > 0) {
            $specIds = array_values(array_filter(array_map(static fn($s) => (int) ($s['id'] ?? 0), $specs), static fn($v) => $v > 0));
            if ($specIds !== []) {
                $placeholders = implode(',', array_fill(0, count($specIds), '?'));
                $userRows = Database::query(
                    "SELECT `spec_id`, `price` FROM " . Database::prefix() . "goods_price_user
                      WHERE `user_id` = ? AND `spec_id` IN ({$placeholders})",
                    array_merge([$buyerId], $specIds)
                );
                foreach ($userRows as $r) {
                    $specUserPriceMap[(int) $r['spec_id']] = (int) $r['price'];
                }

                if ($buyerLevelId > 0) {
                    $levelRows = Database::query(
                        "SELECT `spec_id`, `price` FROM " . Database::prefix() . "goods_price_level
                          WHERE `level_id` = ? AND `spec_id` IN ({$placeholders})",
                        array_merge([$buyerLevelId], $specIds)
                    );
                    foreach ($levelRows as $r) {
                        $specLevelPriceMap[(int) $r['spec_id']] = (int) $r['price'];
                    }
                }
            }
        }

        // 解析价格 factor（详细规则同 getById）
        // applyFactor=false 时直接置 1.0，让 DB 原值穿透返回（后台编辑面板需要这个）。
        $buyerDiscount = $applyFactor ? self::resolveBuyerDiscountRate() : 1.0;
        $factor = $buyerDiscount;
        $markup = 0;
        $isMerchantRefGoods = false;

        $merchantId = class_exists('MerchantContext') ? MerchantContext::currentId() : 0;
        if ($merchantId > 0) {
            $goods = Database::fetchOne(
                'SELECT `owner_id` FROM `' . Database::prefix() . 'goods` WHERE `id` = ? LIMIT 1',
                [(int) $goodsId]
            );
            $goodsOwner = $goods !== null ? (int) $goods['owner_id'] : 0;
            $ownerUserId = MerchantContext::currentOwnerId();

            if ($goodsOwner === 0) {
                $isMerchantRefGoods = true;
                // 主站引用商品：先 markup 再买家折扣
                $ref = Database::fetchOne(
                    'SELECT `markup_rate` FROM `' . Database::prefix() . 'goods_merchant_ref`
                      WHERE `merchant_id` = ? AND `goods_id` = ? LIMIT 1',
                    [$merchantId, (int) $goodsId]
                );
                $markup = $ref !== null ? (int) $ref['markup_rate'] : self::resolveMerchantDefaultMarkup($merchantId);
                $factor = $applyFactor ? (1 + $markup / 10000) : 1.0;
            } elseif ($ownerUserId > 0 && $goodsOwner === $ownerUserId) {
                // 自建商品：仅买家折扣
                $factor = $buyerDiscount;
            }
            // 跨商户自建：getById 已返回 null，理论上 OrderModel 拿不到这里；做一次保护即可
        }

        $ownerLevelId = 0;
        $ownerDiscountRate = 1.0;
        $ownerUserIdForWholesale = 0;
        if ($isMerchantRefGoods) {
            $ownerUserIdForWholesale = MerchantContext::currentOwnerId();
            if ($ownerUserIdForWholesale > 0) {
                if (class_exists('MerchantLedgerService')) {
                    $ownerDiscountRate = MerchantLedgerService::resolveOwnerDiscountRate($ownerUserIdForWholesale);
                }
                $ownerRow = Database::fetchOne(
                    'SELECT `level_id` FROM `' . Database::prefix() . 'user` WHERE `id` = ? LIMIT 1',
                    [$ownerUserIdForWholesale]
                );
                $ownerLevelId = (int) ($ownerRow['level_id'] ?? 0);
            }
        }

        foreach ($specs as &$spec) {
            $specId = (int) ($spec['id'] ?? 0);
            $basePriceRaw = (int) $spec['price'];
            $resolvedPriceRaw = $basePriceRaw;
            $hasFixedPersonalPrice = false;
            if ($specId > 0 && isset($specUserPriceMap[$specId])) {
                $resolvedPriceRaw = (int) $specUserPriceMap[$specId];
                $hasFixedPersonalPrice = true;
            } elseif ($specId > 0 && isset($specLevelPriceMap[$specId])) {
                $resolvedPriceRaw = (int) $specLevelPriceMap[$specId];
                $hasFixedPersonalPrice = true;
            }

            $spec['_base_price_raw'] = $basePriceRaw;
            if ($isMerchantRefGoods) {
                $spec['_owner_cost_raw'] = self::resolveSpecWholesaleUnitPrice(
                    $basePriceRaw,
                    $specId,
                    $ownerUserIdForWholesale,
                    $ownerLevelId,
                    $ownerDiscountRate
                );
            }

            $mainSiteSell = self::resolveMainSiteSellUnitPrice(
                $basePriceRaw,
                $hasFixedPersonalPrice,
                $resolvedPriceRaw,
                $buyerDiscount
            );
            $exclusiveIsFinal = $hasFixedPersonalPrice && (!$isMerchantRefGoods || !$applyFactor);

            if ($isMerchantRefGoods && $applyFactor) {
                $spec['price_raw'] = (int) round($mainSiteSell * $factor);
                $spec['price'] = $spec['price_raw'];
                if (isset($spec['cost_price'])) {
                    $spec['cost_price'] = (int) round((int) $spec['cost_price'] * $factor);
                }
                if (isset($spec['market_price'])) {
                    $spec['market_price'] = (int) round((int) $spec['market_price'] * $factor);
                }
            } elseif ($exclusiveIsFinal) {
                $spec['price_raw'] = $resolvedPriceRaw;
                $spec['price'] = $resolvedPriceRaw;
            } else {
                $unitBaseRaw = $hasFixedPersonalPrice ? $resolvedPriceRaw : $basePriceRaw;
                $spec['price_raw'] = (int) round($unitBaseRaw * $factor);
                if ($factor !== 1.0) {
                    $spec['price'] = (int) round($unitBaseRaw * $factor);
                    if (isset($spec['cost_price'])) {
                        $spec['cost_price'] = (int) round((int) $spec['cost_price'] * $factor);
                    }
                    if (isset($spec['market_price'])) {
                        $spec['market_price'] = (int) round((int) $spec['market_price'] * $factor);
                    }
                }
            }
            $spec['_shop_markup_rate'] = $markup;
            self::convertMoneyFields($spec, ['price', 'cost_price', 'market_price']);
        }
        unset($spec);
        return $specs;
    }

    /**
     * 获取商品的最小/最大价格和总库存（返回 DB 原始值，供内部缓存用）。
     *
     * 总库存：各在售规格库存相加；任一格为负数（如 -1 表示无限）时，整商品 total_stock 记为 -1，
     * 避免 MySQL SUM(-1,10) 等把「无限」当成数字参与求和导致多规格总库存错误。
     */
    public static function getPriceAndStockRange($goodsId)
    {
        $prefix = Database::prefix();

        $result = Database::query(
            "SELECT MIN(price) as min_price, MAX(price) as max_price
             FROM {$prefix}goods_spec WHERE goods_id = ? AND status = 1",
            [$goodsId]
        );

        $row = $result[0] ?? [];

        $stockRows = Database::query(
            "SELECT `stock` FROM `{$prefix}goods_spec` WHERE `goods_id` = ? AND `status` = 1",
            [$goodsId]
        );
        $sumFinite = 0;
        $hasUnlimited = false;
        foreach ($stockRows as $sr) {
            $s = (int) ($sr['stock'] ?? 0);
            if ($s < 0) {
                $hasUnlimited = true;
            } else {
                $sumFinite += $s;
            }
        }
        $totalStock = $hasUnlimited ? -1 : max(0, $sumFinite);

        return [
            'min_price' => (int) ($row['min_price'] ?? 0),
            'max_price' => (int) ($row['max_price'] ?? 0),
            'total_stock' => $totalStock,
        ];
    }

    /**
     * 更新商品的价格和库存缓存字段（写入 DB 原始值）
     */
    public static function updatePriceStockCache($goodsId)
    {
        self::ensureSingleSpecDefault((int) $goodsId);

        $range = self::getPriceAndStockRange($goodsId);
        return Database::update('goods', [
            'min_price' => $range['min_price'],
            'max_price' => $range['max_price'],
            'total_stock' => $range['total_stock'],
        ], $goodsId);
    }

    /**
     * 仅一个在售规格且未标默认时，自动设为默认（列表划线价等依赖参考规格）。
     */
    private static function ensureSingleSpecDefault(int $goodsId): void
    {
        if ($goodsId <= 0) {
            return;
        }
        $prefix = Database::prefix();
        $rows = Database::query(
            "SELECT id, is_default FROM {$prefix}goods_spec
             WHERE goods_id = ? AND status = 1
             ORDER BY sort ASC, id ASC",
            [$goodsId]
        );
        if (count($rows) !== 1 || (int) ($rows[0]['is_default'] ?? 0) === 1) {
            return;
        }
        Database::execute(
            "UPDATE {$prefix}goods_spec SET is_default = 1 WHERE id = ?",
            [(int) $rows[0]['id']]
        );
    }

    /**
     * 递增规格已售数量
     *
     * @param int $specId 规格ID
     * @param int $quantity 售出数量（默认1）
     * @return bool
     */
    public static function incrementSoldCount(int $specId, int $quantity = 1): bool
    {
        $prefix = Database::prefix();
        return (bool)Database::execute(
            "UPDATE {$prefix}goods_spec SET sold_count = sold_count + ? WHERE id = ?",
            [$quantity, $specId]
        );
    }

    /**
     * 记录商品变更日志
     *
     * 注意：$old 来自 getById()，金额字段已转换为展示值；
     * $new 是写入数据库的原始值。比较前需将金额字段统一。
     */
    private static function logChange($goodsId, $old, $new)
    {
        // 金额字段列表：$old 中为展示值，$new 中为 DB 原始值
        $moneyFields = ['min_price', 'max_price'];

        $changed = [];
        foreach ($new as $key => $value) {
            if (!isset($old[$key])) continue;
            $oldVal = $old[$key];

            // 金额字段：将 $old 的展示值转回 DB 值再比较
            if (in_array($key, $moneyFields, true)) {
                $oldVal = self::moneyToDb($oldVal);
            }

            if ($oldVal != $value) {
                $changed[] = [
                    'field' => $key,
                    'old' => $old[$key],
                    'new' => in_array($key, $moneyFields, true) ? self::moneyFromDb($value) : $value,
                ];
            }
        }

        if (empty($changed)) {
            return;
        }

        // 写入系统日志（字段匹配 em_system_log 表结构）
        $admin = $_SESSION['em_admin_auth'] ?? [];
        Database::insert('system_log', [
            'level' => 'info',
            'type' => 'admin_operation',
            'action' => 'goods_update',
            'message' => '商品#' . $goodsId . ' 字段变更',
            'detail' => json_encode($changed, JSON_UNESCAPED_UNICODE),
            'user_id' => $admin['id'] ?? 0,
            'username' => $admin['username'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
