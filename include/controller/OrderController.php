<?php

declare(strict_types=1);

/**
 * 订单控制器。
 *
 * 方法说明：
 * - create()  AJAX 创建订单（商品详情页直接购买）
 *
 * 前台不再提供「订单详情」页面：订单详情统一走页面脚本 /user/order_detail.php
 * （登录用户）与 /user/find_order.php（游客查单），核心 JS、支付插件、支付回调都指向它们。
 * 原先 ?c=order 下的订单结果页（order_result 模板）全站没有任何入口，已连同动作一起删除。
 */
class OrderController extends BaseController
{
    /**
     * 获取当前用户身份。
     */
    private function getIdentity(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $frontUser = $_SESSION['em_front_user'] ?? null;
        return [
            'user_id'     => !empty($frontUser['id']) ? (int) $frontUser['id'] : 0,
            'guest_token' => GuestToken::get(),
        ];
    }

    /**
     * AJAX：从商品详情页直接下单。
     * 所有必填/合法性校验都在此完成，前端不做二次校验。
     *
     * POST 参数：goods_id, spec_id, quantity, payment_code,
     *           extra_*（附加选项，可选）、
     *           guest_find_contact_query / guest_find_contact_type / guest_find_password_query（游客）
     */
    public function create(): void
    {
        if (!Request::isPost()) {
            Response::error('无效请求');
        }

        // 跨站/跨子域提交必须带 token；旧主题没有 token 时仅允许同源请求。
        if (!Csrf::validateOrSameOrigin((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $goodsId     = (int) Input::post('goods_id', 0);
        $specId      = (int) Input::post('spec_id', 0);
        $quantity    = (int) Input::post('quantity', 1);
        $paymentCode = trim((string) Input::post('payment_code', ''));

        // —— 基础参数校验
        if ($goodsId <= 0) {
            Response::error('请选择商品');
        }
        if ($quantity <= 0) {
            $quantity = 1;
        }
        if ($paymentCode === '') {
            Response::error('请选择支付方式');
        }

        // —— 身份信息（提前到余额支付校验使用）
        $identity = $this->getIdentity();

        // —— 余额支付仅登录用户可用
        if ($paymentCode === 'balance' && $identity['user_id'] <= 0) {
            Response::error('未登录用户无法使用余额支付');
        }

        // —— 读取商品，用于附加选项/规格校验
        $goodsRow = GoodsModel::getById($goodsId);
        if (!$goodsRow
            || (int) $goodsRow['status'] !== 1
            || (int) $goodsRow['is_on_sale'] !== 1
            || $goodsRow['deleted_at'] !== null
        ) {
            Response::error('商品不存在或已下架');
        }
        // 作用域校验：主站前台只能下主站商品；商户前台只能下本店自建 + 主站引用
        if (!MerchantContext::isGoodsVisibleToCurrentScope($goodsRow)) {
            Response::error('商品不存在或已下架');
        }

        // —— 规格校验：多规格商品必须显式选中一个
        $allSpecs = GoodsModel::getSpecsByGoodsId($goodsId);
        if (count($allSpecs) > 1 && $specId <= 0) {
            Response::error('请选择规格');
        }

        // —— 附加选项必填校验（商品 configs.extra_fields 里 required=1 的项）
        $goodsConfigs = json_decode($goodsRow['configs'] ?? '{}', true) ?: [];
        
        $extraFields = [];
        foreach (($goodsConfigs['extra_fields'] ?? []) as $ef) {
            $name = (string) ($ef['name'] ?? '');
            if ($name === '') continue;
            $val = trim((string) Input::post('extra_' . $name, ''));
            if (!empty($ef['required']) && $val === '') {
                Response::error('请填写 ' . ($ef['title'] ?? $name));
            }
            $extraFields[$name] = $val;
        }

        // —— 游客查单字段必填校验（仅未登录用户；登录用户 DOM 里本就没这些字段）
        $isGuest = $identity['user_id'] === 0;

        $contactQuery = trim((string) Input::post('guest_find_contact_query', ''));
        $orderPassword = trim((string) Input::post('guest_find_password_query', ''));

        if ($isGuest) {
            if (GuestFindModel::isContactEnabled()) {
                $contactError = GuestFindModel::validateContactQuery($contactQuery);
                if ($contactError !== null) {
                    Response::error($contactError);
                }
            }
            if (GuestFindModel::isPasswordEnabled() && $orderPassword === '') {
                Response::error('请设置订单密码');
            }
        }

        // —— 查找支付方式信息
        $payment = $this->findPaymentMethod($paymentCode);

        // 商品级支付方式强校验（插件扩展点）：插件返回非空字符串则中断下单。
        // 前端只跳过 .is-disabled 的按钮，构造请求可绕过，所以必须在这里兜底。
        $payMethodError = applyFilter(
            'order_payment_method_validate',
            '',
            $goodsRow,
            $paymentCode,
            $payment,
            $identity['user_id']
        );
        if (is_string($payMethodError) && $payMethodError !== '') {
            Response::error($payMethodError);
        }

        try {
            // 商户上下文：在商户店铺下单 → 订单归属该商户
            $merchantId = MerchantContext::currentId();
            $merchantOwnerId = MerchantContext::currentOwnerId();

            $createData = [
                'user_id'             => $identity['user_id'],
                'guest_token'         => $identity['guest_token'],
                'merchant_id'         => $merchantId,
                'owner_id'            => $merchantOwnerId,
                'payment_code'        => $payment['code'] ?? '',
                'payment_name'        => $payment['name'] ?? '',
                'payment_plugin'      => $payment['plugin'] ?? '',
                'payment_plugin_name' => $payment['plugin_name'] ?? '',
                'payment_channel'     => $payment['channel'] ?? '',
                // 收货地址：OrderModel 按 goods_type_register.needs_address 判断是否必填
                //   - 登录用户：address_id 指向地址簿记录
                //   - 游客：guest_address 是下单页手填的 6 字段数组
                'address_id'          => (int) Input::post('address_id', 0),
                'guest_address'       => Input::post('guest_address', null),
            ];

            // 附加选项 → contact_info（含 title 快照）；游客联系方式 → guest_contact
            if (!empty($extraFields)) {
                $createData['contact_info'] = OrderModel::packExtraContactInfo(
                    $goodsConfigs['extra_fields'] ?? [],
                    $extraFields
                );
            }
            if ($contactQuery !== '') {
                $createData['guest_contact'] = $contactQuery;
            }

            // 游客查单密码（明文存储）
            if ($orderPassword !== '') {
                $createData['order_password'] = $orderPassword;
            }

            // —— 优惠券（可选）：前端已通过 coupon/check 校验过折扣；此处再次后端校验后一起入创建事务
            $couponCode = trim((string) Input::post('coupon_code', ''));
            if ($couponCode !== '' && !shop_coupon_enabled()) {
                Response::error('优惠券功能未启用');
            }
            if ($couponCode !== '') {
                $couponService = new CouponService();
                try {
                    // 商品详情页下单金额 = 单商品价 × 数量
                    //   price_raw 是 GoodsModel::getSpecsByGoodsId() 动态计算字段（商户站会乘 factor），
                    //   不能直接 Database::find 查 raw 表（那样只有 price 字段，拿不到带重写的价格）。
                    //   走 GoodsModel 保证和详情页展示价 / OrderModel::create 实扣价三端一致。
                    $priceRaw = 0;
                    foreach (GoodsModel::getSpecsByGoodsId($goodsId) as $_s) {
                        if ((int) $_s['id'] === $specId) {
                            $priceRaw = (int) ($_s['price_raw'] ?? $_s['price']);
                            break;
                        }
                    }
                    $goodsAmountRaw = $priceRaw * $quantity;
                    $goodsType = $goodsRow['goods_type'] ?? '';
                    $categoryId = (int) ($goodsRow['category_id'] ?? 0);

                    $checkRes = $couponService->check($couponCode, [
                        'goods_amount_raw' => $goodsAmountRaw,
                        'goods_items'      => [[
                            'goods_id'    => $goodsId,
                            'category_id' => $categoryId,
                            'goods_type'  => $goodsType,
                        ]],
                        'user_id'          => $identity['user_id'],
                    ]);
                    $createData['coupon'] = $checkRes['coupon'];
                    $createData['coupon_discount'] = $checkRes['discount_raw'];
                } catch (RuntimeException $e) {
                    Response::error($e->getMessage());
                }
            }

            // 返佣归因快照（2 级：直推 / 间推）
            [$in1, $in2] = RebateService::resolveOrderInviters($identity['user_id']);
            if ($in1) $createData['inviter_l1'] = $in1;
            if ($in2) $createData['inviter_l2'] = $in2;

            // create() 抛出的 RuntimeException 由外层 catch 统一响应
            $result = OrderModel::create($createData, [
                ['goods_id' => $goodsId, 'spec_id' => $specId, 'quantity' => $quantity],
            ]);

            // 0 元订单：直接标记已支付，不走任何支付插件
            $paidNow = false;
            if ((int) $result['pay_amount'] <= 0) {
                OrderModel::payFreeOrder((int) $result['order_id']);
                $paidNow = true;
            } elseif ($paymentCode === 'balance' && $identity['user_id'] > 0) {
                OrderModel::payWithBalance($result['order_id'], $identity['user_id']);
                $paidNow = true;
            }

            // 非余额支付：触发 payment_create 过滤器，让对应插件生成 pay_url
            // 走 PaymentService::createPayment 而不是直接 applyFilter —— 它会临时把 scope
            // 切到 'main' 让插件 Storage::getInstance 读到主站凭证（商户 scope 没存这些凭证）
            $payPayload = ['pay_url' => '', 'qrcode' => ''];
            if (!$paidNow) {
                $orderRow = OrderModel::getById((int) $result['order_id']);
                $payPayload = PaymentService::createPaymentPayload($orderRow, $payment);
            }

            Response::success('下单成功', [
                'order_id'   => $result['order_id'],
                'order_no'   => $result['order_no'],
                'pay_amount' => bcdiv((string) $result['pay_amount'], '1000000', 2),
                'paid'       => $paidNow,
                'pay_url'    => (string) ($payPayload['pay_url'] ?? ''),
                'qrcode'     => (string) ($payPayload['qrcode'] ?? ''),
            ]);

        } catch (RuntimeException $e) {
            Response::error($e->getMessage());
        }
    }

    /**
     * 查找支付方式。
     */
    private function findPaymentMethod(string $code): array
    {
        if ($code === '') {
            return [];
        }

        $methods = PaymentService::getMethods();
        foreach ($methods as $m) {
            if ($m['code'] === $code) {
                return $m;
            }
        }

        return [];
    }
}
