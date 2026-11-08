<?php

declare(strict_types=1);

/**
 * 外部下单 API 控制器。
 *
 * 路由：/?c=api&act=simulate_order (GET/POST，模拟下单校验)
 *       /?c=api&act=real_order     (POST，真实下单)
 *       /?c=api&act=create_order   (POST，兼容旧调用，等价 real_order)
 *       /?c=api&act=query_order    (GET/POST)
 *       /?c=api&act=delivery_callback（GET/POST，上游发货回调下游；JSON 可含 spec_remaining_stock、goods_total_stock 等与上游对齐库存）
 *       /?c=api&act=base_info      (GET/POST)
 *       /?c=api&act=goods_category (GET/POST)
 *       /?c=api&act=goods_list      (GET/POST，不分页；可选 goods_id / goods_ids / category_id / category_ids)
 *
 * 鉴权参数（所有 act 通用）：
 *   appid      = user.id
 *   timestamp  = UNIX 时间戳（秒）
 *   sign       = md5(按参数名排序后的 key=value&... + SECRET)，排除 sign/sign_type 和空值；
 *                act / c / a 同样参与签名。写操作（real_order / create_order）不接受不含 act 的旧版签名。
 */
class ApiController extends BaseController
{
    /** 签名有效期（秒） */
    private const SIGN_TTL = 600;

    public function _index(): void
    {
        $act = trim((string) (Input::post('act', '') ?: Input::get('act', '')));
        if ($act === '') {
            Response::error('缺少 act 参数');
        }

        switch ($act) {
            case 'simulate_order':
            case 'preview_order':
                $this->simulateOrder();
                return;
            case 'real_order':
            case 'create_real_order':
            case 'create_order':
                $this->createOrder();
                return;
            case 'query_order':
                $this->queryOrder();
                return;
            case 'delivery_callback':
            case 'order_delivery_callback':
                $this->deliveryCallback();
                return;
            case 'base_info':
            case 'get_base_info':
                $this->baseInfo();
                return;
            case 'goods_category':
            case 'goods_categories':
            case 'get_goods_category':
                $this->goodsCategory();
                return;
            case 'goods_list':
            case 'get_goods_list':
                $this->goodsList();
                return;
            default:
                Response::error('未知 act');
        }
    }

    /**
     * 创建订单（外部下单）。
     *
     * 必填：
     *   appid, timestamp, sign, goods_id, quantity
     *
     * 可选：
     *   spec_id
     *   coupon_code
     *   guest_find_contact_query / contact（可选；非游客身份，仅作终端客户备注/查单辅助）
     *   guest_find_password_query / order_password（可选）
     *   attach
     *   extra_json（JSON 对象，附加字段键值）
     *   extra_{name}（附加字段，和 extra_json 二选一或混用）
     *   address_json（JSON：recipient/mobile/province/city/district/detail）
     *   delivery_callback_url（可选；上游发货后回调地址，同系统对接使用）
     *
     * 身份：鉴权通过后订单 user_id = appid 对应用户，后台显示为会员而非游客。
     */
    private function createOrder(): void
    {
        if (!Request::isPost()) {
            Response::error('create_order 仅支持 POST 请求');
        }

        $params = $this->requestParams();
        $apiUser = $this->authUser($params);
        $scope = $this->resolveGoodsApiHostScope();

        $goodsId = (int) ($params['goods_id'] ?? 0);
        $specId = (int) ($params['spec_id'] ?? 0);
        $quantity = max(1, (int) ($params['quantity'] ?? 1));
        $couponCode = trim((string) ($params['coupon_code'] ?? ''));

        if ($goodsId <= 0) {
            Response::error('goods_id 参数错误');
        }

        $orderResult = [];
        try {
            $orderResult = $this->runWithApiBuyerContext($apiUser, function () use (
                $scope,
                $apiUser,
                $params,
                $goodsId,
                $specId,
                $quantity,
                $couponCode
            ) {
                return $this->runWithMerchantScope($scope['merchant_row'], function () use (
                    $scope,
                    $apiUser,
                    $params,
                    $goodsId,
                    $specId,
                    $quantity,
                    $couponCode
                ) {
                    $prepared = $this->prepareApiOrderDraft(
                        $scope,
                        $apiUser,
                        $params,
                        $goodsId,
                        $specId,
                        $quantity,
                        $couponCode
                    );

                    $result = OrderModel::create($prepared['create_data'], [[
                        'goods_id'  => (int) $prepared['goods_id'],
                        'spec_id'   => (int) $prepared['spec_id'],
                        'quantity'  => $quantity,
                    ]]);

                    // 下单后立即扣 API 对接人的余额并支付，不返回支付方式链接
                    if ((int) ($result['pay_amount'] ?? 0) <= 0) {
                        OrderModel::payFreeOrder((int) $result['order_id']);
                    } else {
                        try {
                            OrderModel::payWithBalance((int) $result['order_id'], (int) $apiUser['id']);
                        } catch (Throwable $payErr) {
                            // 支付失败的 API 单标记 failed，避免残留 pending 单
                            try {
                                OrderModel::changeStatus((int) $result['order_id'], 'failed');
                            } catch (Throwable $_) {
                            }
                            throw $payErr;
                        }
                    }

                    return [
                        'order_id'   => (int) $result['order_id'],
                        'order_no'   => (string) $result['order_no'],
                        'pay_amount' => bcdiv((string) $result['pay_amount'], '1000000', 2),
                        'paid'       => true,
                        'pay_url'    => '',
                        'qrcode'     => '',
                    ];
                });
            });
        } catch (RuntimeException $e) {
            Response::error($e->getMessage());
        } catch (Throwable $e) {
            Response::error('下单失败，请稍后重试');
        }

        Response::success('下单成功', $orderResult);
    }

    /**
     * 模拟下单：仅做可下单校验，不创建订单。
     *
     * 必填：appid, timestamp, sign, goods_id, quantity
     * 可选参数与 create_order 一致。
     */
    private function simulateOrder(): void
    {
        $params = $this->requestParams();
        $apiUser = $this->authUser($params);
        $scope = $this->resolveGoodsApiHostScope();

        $goodsId = (int) ($params['goods_id'] ?? 0);
        $specId = (int) ($params['spec_id'] ?? 0);
        $quantity = max(1, (int) ($params['quantity'] ?? 1));
        $couponCode = trim((string) ($params['coupon_code'] ?? ''));
        if ($goodsId <= 0) {
            Response::error('goods_id 参数错误');
        }

        $preview = [];
        try {
            $preview = $this->runWithApiBuyerContext($apiUser, function () use (
                $scope,
                $apiUser,
                $params,
                $goodsId,
                $specId,
                $quantity,
                $couponCode
            ) {
                return $this->runWithMerchantScope($scope['merchant_row'], function () use (
                    $scope,
                    $apiUser,
                    $params,
                    $goodsId,
                    $specId,
                    $quantity,
                    $couponCode
                ) {
                    $prepared = $this->prepareApiOrderDraft(
                        $scope,
                        $apiUser,
                        $params,
                        $goodsId,
                        $specId,
                        $quantity,
                        $couponCode
                    );
                    $needRaw = (int) ($prepared['estimated_pay_amount_raw'] ?? 0);
                    $balanceRaw = (int) ($apiUser['money'] ?? 0);
                    if ($needRaw > 0 && $balanceRaw < $needRaw) {
                        throw new RuntimeException('对接人余额不足');
                    }
                    return [
                        'can_order'          => true,
                        'goods_id'           => (int) $prepared['goods_id'],
                        'spec_id'            => (int) $prepared['spec_id'],
                        'quantity'           => $quantity,
                        'estimated_pay'      => bcdiv((string) $needRaw, '1000000', 2),
                        'balance'            => bcdiv((string) $balanceRaw, '1000000', 2),
                        'estimated_pay_raw'  => $needRaw,
                        'balance_raw'        => $balanceRaw,
                    ];
                });
            });
        } catch (RuntimeException $e) {
            Response::success($e->getMessage(), [
                'can_order' => false,
                'reason'    => $e->getMessage(),
            ]);
        } catch (Throwable $e) {
            Response::success('模拟下单校验失败', [
                'can_order' => false,
                'reason'    => '模拟下单校验失败',
            ]);
        }

        Response::success('校验通过', $preview);
    }

    /**
     * 查询订单（外部查单）。
     *
     * 必填：appid, timestamp, sign, order_no
     */
    private function queryOrder(): void
    {
        $params = $this->requestParams();
        $apiUser = $this->authUser($params);
        $scope = $this->resolveGoodsApiHostScope();

        $orderNo = trim((string) ($params['order_no'] ?? ''));
        if ($orderNo === '') {
            Response::error('order_no 不能为空');
        }

        $order = OrderModel::getByOrderNo($orderNo);
        if ($order === null) {
            Response::error('订单不存在');
        }

        // 只能是本 appid 创建的订单；只按 Host scope 校验会允许任何 API 账号查别人的单
        if ((int) ($order['user_id'] ?? 0) !== (int) ($apiUser['id'] ?? 0)) {
            Response::error('无权查询该订单');
        }

        // 订单归属校验：防止跨商户/跨主站查询
        $merchantId = (int) ($order['merchant_id'] ?? 0);
        $ownerId = (int) ($order['owner_id'] ?? 0);
        if ($merchantId !== (int) $scope['merchant_id'] || $ownerId !== (int) $scope['owner_id']) {
            Response::error('无权查询该订单');
        }

        Response::success('', [
            'order_no'          => (string) $order['order_no'],
            'status'            => (string) $order['status'],
            'status_name'       => OrderModel::statusName((string) $order['status']),
            'pay_amount'        => bcdiv((string) ($order['pay_amount'] ?? 0), '1000000', 2),
            'payment_code'      => (string) ($order['payment_code'] ?? ''),
            'payment_name'      => (string) ($order['payment_name'] ?? ''),
            'created_at'        => (string) ($order['created_at'] ?? ''),
            'pay_time'          => (string) ($order['pay_time'] ?? ''),
            'delivery_time'     => (string) ($order['delivery_time'] ?? ''),
            'complete_time'     => (string) ($order['complete_time'] ?? ''),
        ]);
    }

    /**
     * 获取基础信息。
     *
     * 必填：appid, timestamp, sign
     */
    private function baseInfo(): void
    {
        $params = $this->requestParams();
        $apiUser = $this->authUser($params);

        $siteName = trim((string) Config::get('sitename', 'EMSHOP'));
        if ($siteName === '') {
            $siteName = 'EMSHOP';
        }

        Response::success('', [
            'site_name' => $siteName,
            'account'   => (string) ($apiUser['username'] ?? ''),
            'email'     => (string) ($apiUser['email'] ?? ''),
            'mobile'    => (string) ($apiUser['mobile'] ?? ''),
            'balance'   => bcdiv((string) ($apiUser['money'] ?? 0), '1000000', 2),
        ]);
    }

    /**
     * 获取商品分类。
     *
     * 必填：appid, timestamp, sign
     */
    private function goodsCategory(): void
    {
        $params = $this->requestParams();
        $this->authUser($params);
        $scope = $this->resolveGoodsApiHostScope();

        $prefix = Database::prefix();
        $merchantId = (int) ($scope['merchant_id'] ?? 0);

        if ($merchantId > 0) {
            $rows = Database::query(
                "SELECT c.`id`, c.`parent_id`,
                        CASE
                            WHEN mcm.`alias_name` IS NULL OR mcm.`alias_name` = '' THEN c.`name`
                            ELSE mcm.`alias_name`
                        END AS `name`,
                        c.`cover_image`
                 FROM `{$prefix}goods_category` c
                 LEFT JOIN `{$prefix}merchant_category_map` mcm
                        ON mcm.`master_category_id` = c.`id` AND mcm.`merchant_id` = ?
                 WHERE c.`status` = 1
                   AND (mcm.`is_hidden` IS NULL OR mcm.`is_hidden` = 0)
                 ORDER BY c.`parent_id` ASC, c.`sort` ASC, c.`id` ASC",
                [$merchantId]
            );
        } else {
            $rows = Database::query(
                "SELECT `id`, `parent_id`, `name`, `cover_image`
                 FROM `{$prefix}goods_category`
                 WHERE `status` = 1
                 ORDER BY `parent_id` ASC, `sort` ASC, `id` ASC"
            );
        }

        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'category_id'    => (int) ($row['id'] ?? 0),
                'parent_id'      => (int) ($row['parent_id'] ?? 0),
                'category_name'  => (string) ($row['name'] ?? ''),
                'category_image' => (string) ($row['cover_image'] ?? ''),
            ];
        }

        Response::success('', [
            'list' => $list,
        ]);
    }

    /**
     * 可对接下单的商品列表（不分页；与前台可见性、商户加价一致；仅含已开启 API 的商品）。
     *
     * 必填：appid, timestamp, sign
     *
     * 可选（可组合，均为 AND）：
     *   goods_id        单个商品 id
     *   goods_ids       多个 id：逗号分隔字符串 "1,2,3"，或 JSON 数组 [1,2,3]（与 goods_id 二选一时可混用，合并去重）
     *   category_id     单个分类 id（需配合 category_source；与 category_ids 二选一优先 category_ids）
     *   category_ids    多个分类 id：逗号分隔或 JSON 数组（用于一级分类下含多个二级时的并集筛选）
     *   category_source = main|merchant（有 category_id 或 category_ids 时生效，默认 main）
     *   keyword         标题/简介模糊
     *
     * 未传 category_id / category_ids 时：不按 category_source 做「伪全部分类」过滤；可见范围与 GoodsController::display 未选分类一致（当前域名对应的 MerchantContext + applyMerchantScope，与会员身份无关），并叠加 goods_ids / keyword 等请求条件；另 require_api_enabled 仅保留可对接下单的商品。
     *
     * 传 goods_id(s) 拉取明细时，每条在基础字段外附带：intro、content、cover_images（数组）、configs（完整 JSON 对象，与后台保存一致）、tag_names、min_buy、max_buy（与规格表一致，可 null）、specs（完整规格列表）、
     * spec_dim_name（维度名，格式同后台 goods_edit 提交值），upstream_goods_type（溯源）；另保留 extra_fields 便于旧客户端。
     */
    private function goodsList(): void
    {
        $params = $this->requestParams();
        $apiUser = $this->authUser($params);
        $scope = $this->resolveGoodsApiHostScope();

        $where = [
            'require_api_enabled' => true,
            'no_limit'            => true,
        ];

        $goodsIds = $this->parseRequestGoodsIds($params);
        if ($goodsIds !== []) {
            $where['goods_ids'] = $goodsIds;
        }

        $categoryIds = $this->parseRequestCategoryIds($params);
        if ($categoryIds !== []) {
            $where['category_ids'] = $categoryIds;
            $catSource = strtolower(trim((string) ($params['category_source'] ?? 'main')));
            $where['category_source'] = $catSource === 'merchant' ? 'merchant' : 'main';
        } else {
            $categoryId = (int) ($params['category_id'] ?? 0);
            if ($categoryId > 0) {
                $where['category_id'] = $categoryId;
                $catSource = strtolower(trim((string) ($params['category_source'] ?? 'main')));
                $where['category_source'] = $catSource === 'merchant' ? 'merchant' : 'main';
            }
        }
        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $where['keyword'] = $keyword;
        }

        $rows = $this->runWithApiBuyerContext($apiUser, function () use ($scope, $where) {
            return $this->runWithMerchantScope($scope['merchant_row'], function () use ($where) {
                return $this->queryGoodsList($where, 1, 'g.sort ASC, g.id DESC');
            });
        });

        $importExtras = $goodsIds !== []
            ? $this->runWithApiBuyerContext($apiUser, function () use ($goodsIds) {
                return $this->fetchGoodsListImportExtras($goodsIds);
            })
            : [];

        $outList = [];
        foreach ($rows as $row) {
            $gid = (int) ($row['id'] ?? 0);
            $item = [
                'goods_id'        => $gid,
                'title'           => (string) ($row['name'] ?? ''),
                'cover_image'     => (string) ($row['image'] ?? ''),
                'min_price'       => number_format((float) ($row['price'] ?? 0), 2, '.', ''),
                'original_price'  => isset($row['original_price']) && $row['original_price'] !== null
                    ? number_format((float) $row['original_price'], 2, '.', '')
                    : '',
                'stock'           => (int) ($row['stock'] ?? 0),
                'stock_text'      => (string) ($row['stock_text'] ?? ''),
                'sold'            => (int) ($row['sold'] ?? 0),
                'goods_type'      => (string) ($row['goods_type'] ?? ''),
                'delivery_type'   => (string) ($row['delivery_type'] ?? ''),
                'jump_url'        => (string) ($row['jump_url'] ?? ''),
                'category_id'     => (int) ($row['category_id'] ?? 0),
            ];
            if ($gid > 0 && isset($importExtras[$gid])) {
                $item = array_merge($item, $importExtras[$gid]);
            }
            $outList[] = $item;
        }

        Response::success('', [
            'list'  => $outList,
            'total' => count($outList),
        ]);
    }

    /**
     * goods_list 在按 goods_ids 拉取时附带的导入用字段（需在主库再查一次完整商品行）。
     *
     * @param list<int> $goodsIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchGoodsListImportExtras(array $goodsIds): array
    {
        $goodsIds = array_values(array_unique(array_filter(array_map('intval', $goodsIds))));
        if ($goodsIds === []) {
            return [];
        }
        $prefix = Database::prefix();
        $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));
        // 不按「必有默认规格」内连接：否则无默认行/规格停用时整商品明细丢失，导入侧简介/多图/configs 全空
        $rows = Database::query(
            "SELECT g.`id`, g.`intro`, g.`content`, g.`cover_images`, g.`configs`, g.`goods_type` AS upstream_goods_type,
                    (SELECT gs.`min_buy` FROM `{$prefix}goods_spec` gs
                     WHERE gs.`goods_id` = g.`id` AND gs.`status` = 1
                     ORDER BY gs.`is_default` DESC, gs.`sort` ASC, gs.`id` ASC LIMIT 1) AS `min_buy`,
                    (SELECT gs.`max_buy` FROM `{$prefix}goods_spec` gs
                     WHERE gs.`goods_id` = g.`id` AND gs.`status` = 1
                     ORDER BY gs.`is_default` DESC, gs.`sort` ASC, gs.`id` ASC LIMIT 1) AS `max_buy`
             FROM `{$prefix}goods` g
             WHERE g.`id` IN ({$placeholders})",
            $goodsIds
        );
        $tagMap = class_exists('GoodsTagModel') ? GoodsTagModel::getTagsByGoodsIds($goodsIds) : [];
        $specMap = $this->fetchGoodsSpecsForImport($goodsIds);
        $out = [];
        foreach ($rows as $r) {
            $gid = (int) ($r['id'] ?? 0);
            if ($gid <= 0) {
                continue;
            }
            $content = (string) ($r['content'] ?? '');
            if (function_exists('mb_strlen') && mb_strlen($content, 'UTF-8') > 500000) {
                $content = mb_substr($content, 0, 500000, 'UTF-8');
            } elseif (strlen($content) > 500000) {
                $content = substr($content, 0, 500000);
            }
            $covers = json_decode((string) ($r['cover_images'] ?? '[]'), true);
            if (!is_array($covers)) {
                $covers = [];
            }
            $covers = array_values(array_filter($covers, static function ($u) {
                return trim((string) $u) !== '';
            }));
            $cfg = json_decode((string) ($r['configs'] ?? '{}'), true);
            if (!is_array($cfg)) {
                $cfg = [];
            }
            $extra = $cfg['extra_fields'] ?? [];
            if (!is_array($extra)) {
                $extra = [];
            }
            $names = [];
            foreach ($tagMap[$gid] ?? [] as $t) {
                $n = trim((string) ($t['name'] ?? ''));
                if ($n !== '') {
                    $names[] = $n;
                }
            }
            $minBuy = $r['min_buy'] ?? null;
            $maxBuy = $r['max_buy'] ?? null;
            $out[$gid] = [
                'intro'                => (string) ($r['intro'] ?? ''),
                'content'              => $content,
                'cover_images'         => $covers,
                'configs'              => $cfg,
                'extra_fields'         => $extra,
                'tag_names'            => $names,
                'min_buy'              => $minBuy !== null && $minBuy !== '' ? (int) $minBuy : null,
                'max_buy'              => $maxBuy !== null && $maxBuy !== '' ? (int) $maxBuy : null,
                'specs'                => $specMap[$gid]['specs'] ?? [],
                'spec_dim_name'        => $specMap[$gid]['spec_dim_name'] ?? '',
                'upstream_goods_type'  => (string) ($r['upstream_goods_type'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param list<int> $goodsIds
     * @return array<int, array{specs:list<array<string,mixed>>,spec_dim_name:string}>
     */
    private function fetchGoodsSpecsForImport(array $goodsIds): array
    {
        if ($goodsIds === []) {
            return [];
        }
        $prefix = Database::prefix();
        $placeholders = implode(',', array_fill(0, count($goodsIds), '?'));

        $specRows = Database::query(
            "SELECT `id`, `goods_id`, `name`, `spec_no`, `price`, `cost_price`, `market_price`, `stock`,
                    `tags`, `configs`, `min_buy`, `max_buy`, `sort`, `is_default`, `status`
             FROM `{$prefix}goods_spec`
             WHERE `goods_id` IN ({$placeholders}) AND `status` = 1
             ORDER BY `goods_id` ASC, `sort` ASC, `id` ASC",
            $goodsIds
        );
        $resolvedPriceMap = [];
        foreach ($goodsIds as $gid) {
            $gid = (int) $gid;
            if ($gid <= 0) {
                continue;
            }
            $resolvedSpecs = GoodsModel::getSpecsByGoodsId($gid, true);
            foreach ($resolvedSpecs as $sp) {
                $sid = (int) ($sp['id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $resolvedPriceMap[$sid] = number_format((float) ($sp['price'] ?? 0), 2, '.', '');
            }
        }
        $dimRows = Database::query(
            "SELECT `goods_id`, `name`
             FROM `{$prefix}goods_spec_dim`
             WHERE `goods_id` IN ({$placeholders})
             ORDER BY `goods_id` ASC, `sort` ASC, `id` ASC",
            $goodsIds
        );

        $dimsByGoods = [];
        foreach ($dimRows as $d) {
            $gid = (int) ($d['goods_id'] ?? 0);
            if ($gid <= 0) {
                continue;
            }
            $nm = trim((string) ($d['name'] ?? ''));
            if ($nm !== '') {
                $dimsByGoods[$gid][] = $nm;
            }
        }

        $out = [];
        foreach ($specRows as $s) {
            $gid = (int) ($s['goods_id'] ?? 0);
            if ($gid <= 0) {
                continue;
            }
            $tags = json_decode((string) ($s['tags'] ?? '[]'), true);
            if (!is_array($tags)) {
                $tags = [];
            }
            $tags = array_values(array_filter(array_map('strval', $tags), 'strlen'));

            $cfg = json_decode((string) ($s['configs'] ?? '{}'), true);
            if (!is_array($cfg)) {
                $cfg = [];
            }
            $out[$gid]['specs'][] = [
                'upstream_spec_id' => (int) ($s['id'] ?? 0),
                'name'         => (string) ($s['name'] ?? ''),
                'spec_no'      => (string) ($s['spec_no'] ?? ''),
                'price'        => (string) ($resolvedPriceMap[(int) ($s['id'] ?? 0)]
                    ?? number_format((float) GoodsModel::moneyFromDb($s['price'] ?? 0), 2, '.', '')),
                'cost_price'   => $s['cost_price'] !== null && $s['cost_price'] !== ''
                    ? number_format((float) GoodsModel::moneyFromDb($s['cost_price']), 2, '.', '')
                    : '',
                'market_price' => $s['market_price'] !== null && $s['market_price'] !== ''
                    ? number_format((float) GoodsModel::moneyFromDb($s['market_price']), 2, '.', '')
                    : '',
                'stock'        => max(0, (int) ($s['stock'] ?? 0)),
                'tags'         => $tags,
                'configs'      => $cfg,
                'min_buy'      => $s['min_buy'] !== null && $s['min_buy'] !== '' ? (int) $s['min_buy'] : null,
                'max_buy'      => $s['max_buy'] !== null && $s['max_buy'] !== '' ? (int) $s['max_buy'] : null,
                'sort'         => (int) ($s['sort'] ?? 0),
                'is_default'   => (int) ($s['is_default'] ?? 0) === 1 ? 1 : 0,
                'status'       => 1,
            ];
            if (!isset($out[$gid]['spec_dim_name'])) {
                $out[$gid]['spec_dim_name'] = '';
            }
        }

        foreach ($out as $gid => $v) {
            $dimNames = $dimsByGoods[$gid] ?? [];
            $out[$gid]['spec_dim_name'] = $dimNames !== [] ? implode('/', $dimNames) : '';
        }
        return $out;
    }

    /**
     * 解析请求中的分类 id 列表（与 goods_ids 传法一致，去重，>0）。
     *
     * @param array<string, mixed> $params
     * @return list<int>
     */
    private function parseRequestCategoryIds(array $params): array
    {
        $acc = [];
        $rawIds = $params['category_ids'] ?? null;
        if (is_array($rawIds)) {
            foreach ($rawIds as $v) {
                $id = (int) $v;
                if ($id > 0) {
                    $acc[$id] = true;
                }
            }
        } elseif (is_string($rawIds) && trim($rawIds) !== '') {
            foreach (preg_split('/\s*,\s*/', $rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $id = (int) $part;
                if ($id > 0) {
                    $acc[$id] = true;
                }
            }
        }
        return array_map('intval', array_keys($acc));
    }

    /**
     * 解析请求中的商品 id 列表（goods_id + goods_ids 合并，去重，>0）。
     *
     * @param array<string, mixed> $params
     * @return list<int>
     */
    private function parseRequestGoodsIds(array $params): array
    {
        $acc = [];
        $single = (int) ($params['goods_id'] ?? 0);
        if ($single > 0) {
            $acc[$single] = true;
        }
        $rawIds = $params['goods_ids'] ?? null;
        if (is_array($rawIds)) {
            foreach ($rawIds as $v) {
                $id = (int) $v;
                if ($id > 0) {
                    $acc[$id] = true;
                }
            }
        } elseif (is_string($rawIds) && trim($rawIds) !== '') {
            foreach (preg_split('/\s*,\s*/', $rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
                $id = (int) $part;
                if ($id > 0) {
                    $acc[$id] = true;
                }
            }
        }
        return array_map('intval', array_keys($acc));
    }

    /**
     * 请求参数（支持 JSON body + 表单参数）。
     *
     * @return array<string, mixed>
     */
    private function requestParams(): array
    {
        $params = array_merge(Input::allGet(), Input::allPost());

        $ct = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($ct, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && trim($raw) !== '') {
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $params = array_merge($params, $json);
                }
            }
        }

        return $params;
    }

    /**
     * 鉴权并返回 API 用户。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function authUser(array $params): array
    {
        $appId = (int) ($params['appid'] ?? 0);
        $timestamp = (int) ($params['timestamp'] ?? 0);
        $sign = strtolower(trim((string) ($params['sign'] ?? '')));
        $signType = strtoupper(trim((string) ($params['sign_type'] ?? 'MD5')));

        if ($appId <= 0) {
            Response::error('appid 参数错误');
        }
        if ($timestamp <= 0) {
            Response::error('timestamp 参数错误');
        }
        if ($sign === '') {
            Response::error('sign 不能为空');
        }
        if ($signType !== 'MD5') {
            Response::error('仅支持 MD5 签名');
        }
        if (abs(time() - $timestamp) > self::SIGN_TTL) {
            Response::error('请求已过期');
        }

        $user = (new UserListModel())->findById($appId);
        if ($user === null || (int) ($user['status'] ?? 0) !== 1) {
            Response::error('API 用户不存在或已禁用');
        }

        $secret = trim((string) ($user['secret'] ?? ''));
        if ($secret === '') {
            Response::error('该账号未生成 API SECRET');
        }

        // 新版签名覆盖 act；写操作必须用新版签名，避免把 simulate_order 改成 real_order 后重放。
        $act = strtolower(trim((string) ($params['act'] ?? '')));
        $stateChanging = in_array($act, ['real_order', 'create_real_order', 'create_order'], true);

        if (hash_equals($this->sign($params, $secret), $sign)) {
            return $user;
        }

        // 旧版签名不含 act / c / a：只读 act 继续兼容；写操作默认拒绝，
        // 极端情况下可用 Config 的 api_allow_legacy_sign=1 临时放行（不推荐）。
        if (hash_equals($this->signLegacy($params, $secret), $sign)) {
            if (!$stateChanging || (string) Config::get('api_allow_legacy_sign', '0') === '1') {
                return $user;
            }
            Response::error('签名校验失败：写操作需要把 act（以及请求里的 c / a）一起参与签名，旧版签名已不再接受');
        }

        Response::error('签名错误');
    }

    /**
     * @param array<string, mixed> $params
     */
    private function sign(array $params, string $secret): string
    {
        // 新版签名覆盖 act / c / a：只改 act 不再能复用原签名。
        unset($params['sign'], $params['sign_type']);

        return $this->buildSign($params, $secret);
    }

    /**
     * 旧版签名：不覆盖 act / c / a，仅用于只读 act 的兼容。
     */
    private function signLegacy(array $params, string $secret): string
    {
        unset($params['sign'], $params['sign_type'], $params['c'], $params['a'], $params['act']);

        return $this->buildSign($params, $secret);
    }

    private function buildSign(array $params, string $secret): string
    {
        ksort($params, SORT_STRING);

        $parts = [];
        foreach ($params as $k => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($v)) {
                $v = $v ? '1' : '0';
            }
            $parts[] = $k . '=' . (string) $v;
        }

        return strtolower(md5(implode('&', $parts) . $secret));
    }

    /**
     * 解析 API 用户对应的下单作用域。
     *
     * @param array<string, mixed> $apiUser
     * @return array{merchant_id:int,owner_id:int,merchant_row:?array}
     */
    private function resolveScope(array $apiUser): array
    {
        $merchantId = (int) ($apiUser['merchant_id'] ?? 0);
        if ($merchantId <= 0) {
            return [
                'merchant_id'  => 0,
                'owner_id'     => 0,
                'merchant_row' => null,
            ];
        }

        $merchant = (new MerchantModel())->findById($merchantId);
        if ($merchant === null || (int) ($merchant['status'] ?? 0) !== 1) {
            Response::error('商户不存在或已停用');
        }
        if ((int) ($merchant['user_id'] ?? 0) !== (int) ($apiUser['id'] ?? 0)) {
            Response::error('API 账号与商户归属不匹配');
        }

        return [
            'merchant_id'  => $merchantId,
            'owner_id'     => (int) ($apiUser['id'] ?? 0),
            'merchant_row' => $merchant,
        ];
    }

    /**
     * goods_category / goods_list 的可见域：仅由当前请求域名解析决定（同 init 里 MerchantContext::resolve），
     * 与会员账号是否「绑定店铺」无关；会员仅作鉴权（appid + SECRET）。
     * 主站域名 → 主站橱窗；店铺域名 → 该店橱窗（与游客访问该域名所见一致）。
     *
     * @return array{merchant_id:int,owner_id:int,merchant_row:?array}
     */
    private function resolveGoodsApiHostScope(): array
    {
        if (!class_exists('MerchantContext')) {
            return [
                'merchant_id'  => 0,
                'owner_id'     => 0,
                'merchant_row' => null,
            ];
        }

        $hostMerchantId = MerchantContext::currentId();
        if ($hostMerchantId <= 0) {
            return [
                'merchant_id'  => 0,
                'owner_id'     => 0,
                'merchant_row' => null,
            ];
        }

        $hostRow = MerchantContext::current();
        if ($hostRow === null || (int) ($hostRow['id'] ?? 0) !== $hostMerchantId) {
            return [
                'merchant_id'  => 0,
                'owner_id'     => 0,
                'merchant_row' => null,
            ];
        }

        return [
            'merchant_id'  => $hostMerchantId,
            'owner_id'     => (int) ($hostRow['user_id'] ?? 0),
            'merchant_row' => $hostRow,
        ];
    }

    /**
     * 在指定商户上下文中执行闭包，执行后恢复原上下文。
     *
     * @template T
     * @param array<string, mixed>|null $merchant
     * @param callable():T $fn
     * @return T
     */
    private function runWithMerchantScope(?array $merchant, callable $fn)
    {
        $saved = MerchantContext::current();
        MerchantContext::setCurrent($merchant);
        try {
            return $fn();
        } finally {
            MerchantContext::setCurrent($saved);
        }
    }

    /**
     * 在指定 API 账号买家上下文中执行闭包（影响 GoodsModel 的会员价/专属价解析），执行后恢复原会话。
     *
     * @template T
     * @param array<string,mixed> $apiUser
     * @param callable():T $fn
     * @return T
     */
    private function runWithApiBuyerContext(array $apiUser, callable $fn)
    {
        $buyerId = (int) ($apiUser['id'] ?? 0);
        if ($buyerId <= 0) {
            return $fn();
        }
        $buyerLevelId = (int) ($apiUser['level_id'] ?? 0);
        $hasForced = array_key_exists('em_force_buyer_identity', $GLOBALS);
        $savedForced = $hasForced ? $GLOBALS['em_force_buyer_identity'] : null;
        $GLOBALS['em_force_buyer_identity'] = [$buyerId, $buyerLevelId];
        try {
            return $fn();
        } finally {
            if ($hasForced) {
                $GLOBALS['em_force_buyer_identity'] = $savedForced;
            } else {
                unset($GLOBALS['em_force_buyer_identity']);
            }
        }
    }

    /**
     * 组装 API 下单草稿（校验商品/规格/附加参数，返回 OrderModel::create 所需数据）。
     *
     * @param array{merchant_id:int,owner_id:int,merchant_row:?array} $scope
     * @param array<string, mixed> $apiUser
     * @param array<string, mixed> $params
     * @return array{create_data:array<string,mixed>,goods_id:int,spec_id:int,estimated_pay_amount_raw:int}
     */
    private function prepareApiOrderDraft(
        array $scope,
        array $apiUser,
        array $params,
        int $goodsId,
        int $specId,
        int $quantity,
        string $couponCode
    ): array {
        $goods = GoodsModel::getById($goodsId);
        if (!$goods
            || (int) ($goods['status'] ?? 0) !== 1
            || (int) ($goods['is_on_sale'] ?? 0) !== 1
            || ($goods['deleted_at'] ?? null) !== null
        ) {
            throw new RuntimeException('商品不存在或已下架');
        }

        // 主站 API 只能下主站商品；商户 API 只能下本店可见商品（GoodsModel::getById 在商户 scope 已过滤）
        if ((int) $scope['merchant_id'] === 0 && (int) ($goods['owner_id'] ?? 0) !== 0) {
            throw new RuntimeException('该商品不支持当前 API 账号下单');
        }

        // API 开关：字段不存在则兼容为开启；存在时必须=1
        if (array_key_exists('api_enabled', $goods) && (int) ($goods['api_enabled'] ?? 0) !== 1) {
            throw new RuntimeException('该商品未开启 API 对接下单');
        }

        $allSpecs = GoodsModel::getSpecsByGoodsId((int) $goods['id']);
        if ($allSpecs === []) {
            throw new RuntimeException('商品规格不存在');
        }
        if (count($allSpecs) > 1 && $specId <= 0) {
            throw new RuntimeException('该商品为多规格，请传 spec_id');
        }
        $pickedSpec = null;
        foreach ($allSpecs as $s) {
            if ((int) ($s['id'] ?? 0) === $specId) {
                $pickedSpec = $s;
                break;
            }
        }
        if ($pickedSpec === null) {
            $pickedSpec = $allSpecs[0];
            $specId = (int) ($pickedSpec['id'] ?? 0);
        }

        $extraFields = $this->collectExtraFields($goods, $params);
        // API 买家已鉴权，不是游客：忽略站点「游客查单」开关与手机号/密码强制校验。
        // contact / order_password 仅作可选备注落库，不参与 GuestFindModel 校验。
        $contactQuery = trim((string) ($params['guest_find_contact_query'] ?? $params['contact'] ?? ''));
        $orderPassword = trim((string) ($params['guest_find_password_query'] ?? $params['order_password'] ?? ''));
        $guestAddress = $this->parseGuestAddress($params);

        $apiUid = (int) ($apiUser['id'] ?? 0);
        if ($apiUid <= 0) {
            throw new RuntimeException('API 用户无效');
        }
        $createData = [
            // 订单 user_id 记 API 付款人（appid）；后台按 user_id>0 识别为会员，非游客
            'user_id'             => $apiUid,
            'guest_token'         => 'api_' . substr(md5((string) $apiUser['id'] . '|' . microtime(true) . '|' . random_int(1000, 9999)), 0, 32),
            'merchant_id'         => (int) $scope['merchant_id'],
            'owner_id'            => (int) $scope['owner_id'],
            'payment_code'        => 'balance',
            'payment_name'        => '余额支付',
            'payment_plugin'      => 'built-in',
            'payment_plugin_name' => '内置',
            'payment_channel'     => 'balance',
            'source'              => 'api',
            'guest_address'       => $guestAddress,
        ];
        $callbackUrl = trim((string) ($params['delivery_callback_url'] ?? ''));
        if ($callbackUrl !== '') {
            if (!preg_match('#^https?://#i', $callbackUrl)) {
                throw new RuntimeException('delivery_callback_url 必须是 http(s) 地址');
            }
            // 拒绝内网/保留地址：发货完成后系统会主动 POST 这个地址，
            // 不做限制就等于给对接方一个探测内网的通道（盲 SSRF）
            if (!DownloadUrlGuard::isPublicHttpUrl($callbackUrl)) {
                throw new RuntimeException('delivery_callback_url 不能指向内网或保留地址');
            }
            if (function_exists('mb_strlen')) {
                if (mb_strlen($callbackUrl, 'UTF-8') > 500) {
                    throw new RuntimeException('delivery_callback_url 过长');
                }
            } elseif (strlen($callbackUrl) > 500) {
                throw new RuntimeException('delivery_callback_url 过长');
            }
            $createData['delivery_callback_url'] = $callbackUrl;
        }
        if ($extraFields !== []) {
            $goodsConfigs = json_decode((string) ($goods['configs'] ?? '{}'), true) ?: [];
            $packedContact = OrderModel::packExtraContactInfo(
                $goodsConfigs['extra_fields'] ?? [],
                $extraFields
            );
            $attach = trim((string) ($params['attach'] ?? ''));
            if ($attach !== '') {
                $packedContact['api_attach'] = $attach;
            }
            $createData['contact_info'] = $packedContact;
        }
        if ($contactQuery !== '') {
            $createData['guest_contact'] = $contactQuery;
        }
        if ($orderPassword !== '') {
            $createData['order_password'] = $orderPassword;
        }

        $estimatedPayRaw = ((int) ($pickedSpec['price_raw'] ?? 0)) * $quantity;
        if ($couponCode !== '' && !shop_coupon_enabled()) {
            throw new RuntimeException('优惠券功能未启用');
        }
        if ($couponCode !== '') {
            $couponService = new CouponService();
            $checkRes = $couponService->check($couponCode, [
                'goods_amount_raw' => $estimatedPayRaw,
                'goods_items'      => [[
                    'goods_id'    => (int) $goods['id'],
                    'category_id' => (int) ($goods['category_id'] ?? 0),
                    'goods_type'  => (string) ($goods['goods_type'] ?? ''),
                ]],
                'user_id'          => $apiUid,
            ]);
            $createData['coupon'] = $checkRes['coupon'];
            $createData['coupon_discount'] = (int) $checkRes['discount_raw'];
            $estimatedPayRaw = max(0, $estimatedPayRaw - (int) $checkRes['discount_raw']);
        }

        return [
            'create_data'               => $createData,
            'goods_id'                  => (int) $goods['id'],
            'spec_id'                   => $specId,
            'estimated_pay_amount_raw'  => $estimatedPayRaw,
        ];
    }

    /**
     * 上游发货异步回调接收（同系统对接）。
     * 通过 callback_token + local_order_goods_id 做鉴权。
     *
     * 可选参数 spec_remaining_stock（整数，>= -1）：上游该规格当前剩余库存；-1 表示无限。
     * 存在且合法时，将本单对应本地规格的 stock 直接更新为该值（与上游 notifyDeliveryCallback 推送的 JSON 字段一致）。
     * 可选参数 goods_total_stock（整数，>= -1）：上游按规则汇总后的商品总库存（goods.total_stock），便于下游对接商品直接对齐。
     */
    private function deliveryCallback(): void
    {
        $params = $this->requestParams();
        $localOrderGoodsId = (int) ($params['local_order_goods_id'] ?? $params['order_goods_id'] ?? 0);
        $callbackToken = trim((string) ($params['callback_token'] ?? ''));
        $deliveryContent = trim((string) ($params['delivery_content'] ?? ''));
        if ($localOrderGoodsId <= 0 || $callbackToken === '' || $deliveryContent === '') {
            $this->writeSystemLog('warning', '发货回调参数缺失', '收到的发货回调参数不完整', [
                'local_order_goods_id' => $localOrderGoodsId,
            ]);
            Response::error('回调参数缺失');
        }

        $prefix = Database::prefix();
        $og = Database::fetchOne(
            "SELECT `id`, `order_id`, `goods_id`, `spec_id`, `quantity`, `delivery_content`, `plugin_data`
             FROM `{$prefix}order_goods` WHERE `id` = ? LIMIT 1",
            [$localOrderGoodsId]
        );
        if (!$og) {
            $this->writeSystemLog('warning', '发货回调目标不存在', '收到发货回调但本地订单商品不存在', [
                'local_order_goods_id' => $localOrderGoodsId,
            ]);
            Response::error('订单商品不存在');
        }

        $pd = json_decode((string) ($og['plugin_data'] ?? '{}'), true);
        if (!is_array($pd)) {
            $pd = [];
        }
        $em = $pd['emshop_remote'] ?? [];
        if (!is_array($em)) {
            $em = [];
        }
        $savedToken = (string) ($em['callback_token'] ?? '');
        if ($savedToken === '' || !hash_equals($savedToken, $callbackToken)) {
            $this->writeSystemLog('warning', '发货回调鉴权失败', '收到发货回调但鉴权不通过', [
                'local_order_goods_id' => $localOrderGoodsId,
            ]);
            Response::error('回调鉴权失败');
        }

        // 幂等：已写过发货内容直接返回成功
        if (OrderModel::hasDeliveryContent($localOrderGoodsId, (string) ($og['delivery_content'] ?? ''))) {
            $this->writeSystemLog('info', '发货回调重复通知', '发货回调重复到达，按幂等返回成功', [
                'local_order_goods_id' => $localOrderGoodsId,
                'order_id' => (int) ($og['order_id'] ?? 0),
            ]);
            Response::success('ok', ['handled' => true, 'duplicate' => true]);
        }

        $em['upstream_status'] = trim((string) ($params['upstream_status'] ?? ''));
        $em['upstream_order_no'] = trim((string) ($params['order_no'] ?? ($em['upstream_order_no'] ?? '')));
        $pd['emshop_remote'] = $em;

        $stored = OrderModel::persistDeliveryContent($localOrderGoodsId, $deliveryContent);
        if (!$stored) {
            $this->writeSystemLog('error', '发货回调写入失败', '发货内容写入明细表失败', [
                'local_order_goods_id' => $localOrderGoodsId,
            ]);
            Response::error('发货内容写入失败，请先执行升级迁移');
        }
        Database::execute(
            "UPDATE `{$prefix}order_goods`
             SET `delivery_at` = NOW(), `plugin_data` = ?
             WHERE `id` = ?",
            [
                json_encode($pd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $localOrderGoodsId,
            ]
        );

        $specId = (int) ($og['spec_id'] ?? 0);
        $qty = (int) ($og['quantity'] ?? 0);
        if ($specId > 0 && $qty > 0) {
            GoodsModel::incrementSoldCount($specId, $qty);
        }

        $remainStock = $this->parseSpecRemainingStockFromCallback($params);
        $gid = (int) ($og['goods_id'] ?? 0);
        if ($remainStock !== null && $specId > 0) {
            Database::execute(
                "UPDATE `{$prefix}goods_spec` SET `stock` = ? WHERE `id` = ?",
                [$remainStock, $specId]
            );
            if ($gid > 0) {
                GoodsModel::updatePriceStockCache($gid);
            }
        }

        $goodsTotalStock = $this->parseGoodsTotalStockFromCallback($params);
        if ($goodsTotalStock !== null && $gid > 0) {
            Database::execute(
                "UPDATE `{$prefix}goods` SET `total_stock` = ? WHERE `id` = ?",
                [$goodsTotalStock, $gid]
            );
        }

        OrderModel::checkDeliveryComplete((int) ($og['order_id'] ?? 0));
        $this->writeSystemLog('info', '发货回调处理成功', '已处理上游发货回调并写入发货内容', [
            'local_order_goods_id'      => $localOrderGoodsId,
            'order_id'                  => (int) ($og['order_id'] ?? 0),
            'upstream_order_no'         => (string) ($em['upstream_order_no'] ?? ''),
            'upstream_request_method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'upstream_query_string'   => (string) ($_SERVER['QUERY_STRING'] ?? ''),
            'upstream_request_params' => $this->requestParamsForSystemLog($params),
        ]);
        Response::success('ok', ['handled' => true]);
    }

    /**
     * 解析回调里的 spec_remaining_stock；未传或非法时返回 null（不改本地库存）。
     */
    private function parseSpecRemainingStockFromCallback(array $params): ?int
    {
        if (!array_key_exists('spec_remaining_stock', $params)) {
            return null;
        }
        $v = $params['spec_remaining_stock'];
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $i = (int) $v;

        return $i >= -1 ? $i : null;
    }

    /**
     * 解析回调里的 goods_total_stock；未传或非法时返回 null（不改 em_goods.total_stock）。
     */
    private function parseGoodsTotalStockFromCallback(array $params): ?int
    {
        if (!array_key_exists('goods_total_stock', $params)) {
            return null;
        }
        $v = $params['goods_total_stock'];
        if ($v === null || $v === '') {
            return null;
        }
        if (is_bool($v)) {
            return null;
        }
        if (!is_numeric($v)) {
            return null;
        }
        $i = (int) $v;

        return $i >= -1 ? $i : null;
    }

    /**
     * 发货回调写入系统日志用：合并后的请求参数，单字段字符串过长时截断，避免 em_system_log.detail 过大。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function requestParamsForSystemLog(array $params, int $maxStr = 12000): array
    {
        $out = [];
        foreach ($params as $k => $v) {
            $key = (string) $k;
            if (is_string($v) && strlen($v) > $maxStr) {
                $out[$key] = [
                    '_truncated' => true,
                    '_length'    => strlen($v),
                    '_preview'   => mb_substr($v, 0, $maxStr),
                ];
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $detail
     */
    private function writeSystemLog(string $level, string $action, string $message, array $detail = []): void
    {
        try {
            require_once EM_ROOT . '/include/model/SystemLogModel.php';
            if (!class_exists('SystemLogModel')) {
                return;
            }
            $m = new SystemLogModel();
            if ($level === 'error') {
                $m->error('system', $action, $message, $detail);
            } elseif ($level === 'warning' || $level === 'warn') {
                $m->warning('system', $action, $message, $detail);
            } else {
                $m->info('system', $action, $message, $detail);
            }
        } catch (Throwable $e) {
            // 日志失败不影响 API 主流程
        }
    }

    /**
     * 收集并校验商品附加字段。
     *
     * @param array<string, mixed> $goods
     * @param array<string, mixed> $params
     * @return array<string, string>
     */
    private function collectExtraFields(array $goods, array $params): array
    {
        $extraJsonRaw = (string) ($params['extra_json'] ?? '');
        $extraJson = [];
        if ($extraJsonRaw !== '') {
            $decoded = json_decode($extraJsonRaw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('extra_json 不是有效 JSON');
            }
            $extraJson = $decoded;
        }

        $configs = json_decode((string) ($goods['configs'] ?? '{}'), true) ?: [];
        $extraFields = [];
        foreach (($configs['extra_fields'] ?? []) as $ef) {
            $name = (string) ($ef['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $val = '';
            if (array_key_exists('extra_' . $name, $params)) {
                $val = trim((string) $params['extra_' . $name]);
            } elseif (array_key_exists($name, $extraJson)) {
                $val = trim((string) $extraJson[$name]);
            }

            if (!empty($ef['required']) && $val === '') {
                throw new RuntimeException('请填写 ' . (string) ($ef['title'] ?? $name));
            }
            $extraFields[$name] = $val;
        }

        return $extraFields;
    }

    /**
     * 解析游客收货地址。
     *
     * @param array<string, mixed> $params
     * @return array<string, string>|null
     */
    private function parseGuestAddress(array $params): ?array
    {
        if (isset($params['guest_address']) && is_array($params['guest_address'])) {
            return [
                'recipient' => trim((string) ($params['guest_address']['recipient'] ?? '')),
                'mobile'    => trim((string) ($params['guest_address']['mobile'] ?? '')),
                'province'  => trim((string) ($params['guest_address']['province'] ?? '')),
                'city'      => trim((string) ($params['guest_address']['city'] ?? '')),
                'district'  => trim((string) ($params['guest_address']['district'] ?? '')),
                'detail'    => trim((string) ($params['guest_address']['detail'] ?? '')),
            ];
        }

        $jsonRaw = trim((string) ($params['address_json'] ?? ''));
        if ($jsonRaw !== '') {
            $decoded = json_decode($jsonRaw, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('address_json 不是有效 JSON');
            }
            return [
                'recipient' => trim((string) ($decoded['recipient'] ?? '')),
                'mobile'    => trim((string) ($decoded['mobile'] ?? '')),
                'province'  => trim((string) ($decoded['province'] ?? '')),
                'city'      => trim((string) ($decoded['city'] ?? '')),
                'district'  => trim((string) ($decoded['district'] ?? '')),
                'detail'    => trim((string) ($decoded['detail'] ?? '')),
            ];
        }

        $recipient = trim((string) ($params['recipient'] ?? ''));
        $mobile = trim((string) ($params['mobile'] ?? ''));
        $province = trim((string) ($params['province'] ?? ''));
        $city = trim((string) ($params['city'] ?? ''));
        $district = trim((string) ($params['district'] ?? ''));
        $detail = trim((string) ($params['detail'] ?? ''));

        if ($recipient === '' && $mobile === '' && $province === '' && $city === '' && $district === '' && $detail === '') {
            return null;
        }

        return [
            'recipient' => $recipient,
            'mobile'    => $mobile,
            'province'  => $province,
            'city'      => $city,
            'district'  => $district,
            'detail'    => $detail,
        ];
    }
}
