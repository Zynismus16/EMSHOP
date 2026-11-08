<?php

declare(strict_types=1);

/**
 * 商户账务服务。
 *
 * 职责：订单完成 / 退款时的店铺余额分账。
 * 所有算法**依赖 em_order_goods 的快照字段**（goods_owner_id / cost_amount / fee_amount），
 * 不重算，保证下单时的费率即使事后变更也不会影响历史订单。
 *
 * 方案参考：a 系统文档/分站功能方案.md §6。
 *
 * 入参假设：
 *   - 进入 settleOrder 时，订单已是 completed 状态、merchant_id > 0、快照已落表
 *   - 调用方不开事务；本服务自己开事务保证 log 与 balance 原子写入
 */
final class MerchantLedgerService
{
    /**
     * 计算自建商品的主站手续费（×1000000）。
     * 公式：fee = price × qty × self_goods_fee_rate / 10000
     *
     * @param int $merchantId
     * @param int $priceRaw
     * @param int $quantity
     * @return int
     */
    public static function computeSelfFee(int $merchantId, int $priceRaw, int $quantity): int
    {
        $level = self::resolveMerchantLevel($merchantId);
        $feeRate = (int) ($level['self_goods_fee_rate'] ?? 0);
        if ($feeRate <= 0) return 0;
        return (int) floor($priceRaw * $quantity * $feeRate / 10000);
    }

    /**
     * 商户主（站长）在主站的用户等级折扣率（9.9 折 → 0.99）。
     * 未设等级 / 等级禁用 → 1.0。
     */
    public static function resolveOwnerDiscountRate(int $ownerUserId): float
    {
        static $cache = [];
        if ($ownerUserId <= 0) {
            return 1.0;
        }
        if (isset($cache[$ownerUserId])) {
            return $cache[$ownerUserId];
        }

        $userTable = Database::prefix() . 'user';
        $levelTable = Database::prefix() . 'user_levels';
        $row = Database::fetchOne(
            'SELECT ul.`discount`
               FROM `' . $userTable . '` u
          LEFT JOIN `' . $levelTable . '` ul ON ul.`id` = u.`level_id` AND ul.`enabled` = \'y\'
              WHERE u.`id` = ? LIMIT 1',
            [$ownerUserId]
        );

        $raw = (int) ($row['discount'] ?? 0);
        if ($raw <= 0) {
            return $cache[$ownerUserId] = 1.0;
        }
        $rate = ($raw / 1000000) / 10;
        if ($rate <= 0 || $rate > 1) {
            $rate = 1.0;
        }
        return $cache[$ownerUserId] = $rate;
    }

    /**
     * 主站引用商品：本行拿货成本合计（×1000000）= 主站原价单价 × 站长等级折扣 × 数量。
     */
    public static function computeRefGoodsLineCost(int $basePricePerUnit, int $quantity, int $ownerUserId): int
    {
        $qty = max(1, $quantity);
        $unitCost = (int) round($basePricePerUnit * self::resolveOwnerDiscountRate($ownerUserId));
        return $unitCost * $qty;
    }

    /**
     * 计算商户实得（×1000000）。
     *
     * 正常订单 = Σ(每行 price × qty − cost − fee)，优惠券（discount_amount）由主站承担
     * —— 主站做营销活动是它自己的策略，不把代价摊给商户。
     *
     * 例外：买家就是商户主本人（用自己账号购买自己店铺的商品）时，主站再按原价分账
     * 等于把优惠金额补贴给商户（用券自购 → 套现）。这类订单按优惠后的商品金额结算。
     *
     * @param array<string, mixed> $order
     * @param array<int, array<string, mixed>> $items
     */
    public static function computeIncome(array $order, array $items, int $ownerUserId): int
    {
        $totalLine = 0;
        $totalCost = 0;
        $totalFee  = 0;

        foreach ($items as $it) {
            $totalLine += ((int) ($it['price'] ?? 0)) * ((int) ($it['quantity'] ?? 0));
            $totalCost += (int) ($it['cost_amount'] ?? 0);
            $totalFee  += (int) ($it['fee_amount'] ?? 0);
        }

        $buyerUserId = (int) ($order['user_id'] ?? 0);
        if ($buyerUserId > 0 && $buyerUserId === $ownerUserId) {
            $goodsRevenue = (int) ($order['goods_amount'] ?? 0) - (int) ($order['discount_amount'] ?? 0);
            if ($goodsRevenue < 0) {
                $goodsRevenue = 0;
            }
            $income = $goodsRevenue - $totalCost - $totalFee;
        } else {
            $income = $totalLine - $totalCost - $totalFee;
        }

        return $income > 0 ? $income : 0;
    }

    /**
     * 订单完成时结算：把商户实得入账到 em_user.shop_balance。
     *
     * 幂等：若该订单已有 increase 记录，直接跳过（防重入）。
     */
    public static function settleOrder(int $orderId): void
    {
        $order = Database::find('order', $orderId);
        if ($order === null) return;
        $merchantId = (int) ($order['merchant_id'] ?? 0);
        if ($merchantId <= 0) return;
        if (($order['status'] ?? '') !== 'completed') return;

        $logTable = Database::prefix() . 'merchant_balance_log';

        // 注意：幂等检查已移入下方事务内（且持订单行锁之后）。
        // 事务外做 check-then-write 是竞态 —— 两个并发请求（网关重投、后台与发货
        // 队列同时触发完成）都会读到「尚未结算」，然后各写一条 increase 流水。
        $merchant = Database::find('merchant', $merchantId);
        if ($merchant === null || (int) $merchant['status'] !== 1) return;

        $ownerUserId = (int) $merchant['user_id'];

        // 汇总商户实得：Σ(line_price − cost − fee)
        $items = Database::query(
            'SELECT `id`, `price`, `quantity`, `cost_amount`, `fee_amount`, `goods_owner_id`
               FROM `' . Database::prefix() . 'order_goods`
              WHERE `order_id` = ?',
            [$orderId]
        );

        $income = self::computeIncome($order, $items, $ownerUserId);

        $orderTable = Database::prefix() . 'order';

        Database::begin();
        try {
            // 先锁订单行：并发结算在这里串行化。后到的请求会阻塞到前一个提交，
            // 从而在下面的幂等检查里读到已写入的 increase 流水。
            // （SELECT ... FOR UPDATE 是本仓库既有的惯用法，见 CommissionLogModel::splitWithdraw）
            Database::fetchOne(
                'SELECT `id` FROM `' . $orderTable . '` WHERE `id` = ? FOR UPDATE',
                [$orderId]
            );

            // 幂等：已结算过则跳（此刻在事务内且持有订单行锁，判断可靠）
            $existing = Database::fetchOne(
                'SELECT `id` FROM `' . $logTable . '` WHERE `order_id` = ? AND `type` = ? LIMIT 1',
                [$orderId, 'increase']
            );
            if ($existing !== null) {
                Database::commit();
                return;
            }

            // 锁用户行
            $userTable = Database::prefix() . 'user';
            $userRow = Database::fetchOne(
                'SELECT `shop_balance` FROM `' . $userTable . '` WHERE `id` = ? FOR UPDATE',
                [$ownerUserId]
            );
            if ($userRow === null) {
                throw new RuntimeException('商户主不存在');
            }
            $before = (int) $userRow['shop_balance'];
            $after = $before + $income;

            Database::execute(
                'UPDATE `' . $userTable . '` SET `shop_balance` = ? WHERE `id` = ?',
                [$after, $ownerUserId]
            );

            try {
                Database::insert('merchant_balance_log', [
                    'merchant_id' => $merchantId,
                    'user_id' => $ownerUserId,
                    'type' => 'increase',
                    'amount' => $income,
                    'before_balance' => $before,
                    'after_balance' => $after,
                    'order_id' => $orderId,
                    'remark' => '订单完成入账 #' . ($order['order_no'] ?? $orderId),
                    'operator_id' => 0,
                ]);
            } catch (Throwable $e) {
                if (Database::isDuplicateKeyError($e)) {
                    // 唯一索引 uk_order_type 挡住 = 已被并发结算过。
                    // 上面已经给余额加过一次款，所以必须**回滚本次的加款**
                    // （不能 commit），让先到的那笔生效。
                    Database::rollBack();
                    return;
                }
                throw $e;
            }

            // 子商户返佣已移除（规避传销风险）

            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * 订单退款时倒扣店铺余额入账。
     *
     * 幂等：每次 refund 根据已有 increase - refund 净额判断是否需要退回。
     * 若之前根本没 settle 过（如订单没到 completed 就退款），直接跳过。
     */
    public static function refundOrder(int $orderId): void
    {
        $order = Database::find('order', $orderId);
        if ($order === null) return;
        $merchantId = (int) ($order['merchant_id'] ?? 0);
        if ($merchantId <= 0) return;

        $logTable = Database::prefix() . 'merchant_balance_log';

        $merchant = Database::find('merchant', $merchantId);
        if ($merchant === null) return;
        $ownerUserId = (int) $merchant['user_id'];

        $orderTable = Database::prefix() . 'order';

        // 幂等检查同样移入事务内（持订单行锁之后）—— 事务外检查是竞态：
        // 并发退款会写出两条 refund 流水，把商户余额扣两次。
        Database::begin();
        try {
            Database::fetchOne(
                'SELECT `id` FROM `' . $orderTable . '` WHERE `id` = ? FOR UPDATE',
                [$orderId]
            );

            // 幂等：之前有过 refund → 跳过
            $refundExist = Database::fetchOne(
                'SELECT `id` FROM `' . $logTable . '` WHERE `order_id` = ? AND `type` = ? LIMIT 1',
                [$orderId, 'refund']
            );
            if ($refundExist !== null) {
                Database::commit();
                return;
            }

            // 取原入账记录
            $settle = Database::fetchOne(
                'SELECT * FROM `' . $logTable . '` WHERE `order_id` = ? AND `type` = ? LIMIT 1',
                [$orderId, 'increase']
            );
            if ($settle === null) {
                // 订单没 settle 过（可能 paid 阶段直接 refund），无需倒扣
                Database::commit();
                return;
            }
            $refundAmount = (int) $settle['amount'];

            $userTable = Database::prefix() . 'user';
            $userRow = Database::fetchOne(
                'SELECT `shop_balance` FROM `' . $userTable . '` WHERE `id` = ? FOR UPDATE',
                [$ownerUserId]
            );
            if ($userRow === null) {
                throw new RuntimeException('商户主不存在');
            }
            $before = (int) $userRow['shop_balance'];
            $after = $before - $refundAmount; // 允许为负数（已提现走掉的情况）

            Database::execute(
                'UPDATE `' . $userTable . '` SET `shop_balance` = ? WHERE `id` = ?',
                [$after, $ownerUserId]
            );

            try {
                Database::insert('merchant_balance_log', [
                    'merchant_id' => $merchantId,
                    'user_id' => $ownerUserId,
                    'type' => 'refund',
                    'amount' => $refundAmount,
                    'before_balance' => $before,
                    'after_balance' => $after,
                    'order_id' => $orderId,
                    'remark' => '订单退款倒扣 #' . ($order['order_no'] ?? $orderId),
                    'operator_id' => 0,
                ]);
            } catch (Throwable $e) {
                if (Database::isDuplicateKeyError($e)) {
                    // 唯一索引挡住 = 已被并发退款过，回滚本次的倒扣，让先到的那笔生效
                    Database::rollBack();
                    return;
                }
                throw $e;
            }

            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    // ------------------------------------------------------------
    // 私有
    // ------------------------------------------------------------

    /**
     * 解析商户的等级行。
     *
     * @return array<string, mixed>|null
     */
    private static function resolveMerchantLevel(int $merchantId): ?array
    {
        static $cache = [];
        if (array_key_exists($merchantId, $cache)) return $cache[$merchantId];

        $m = Database::find('merchant', $merchantId);
        if ($m === null) return $cache[$merchantId] = null;
        $l = Database::find('merchant_level', (int) $m['level_id']);
        return $cache[$merchantId] = $l;
    }
}
