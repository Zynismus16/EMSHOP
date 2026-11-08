<?php
defined('EM_ROOT') || exit('access denied!');

/**
 * 商品详情页。
 *
 * 数据由 module.php 的 template_goods_content_data() 取好——商品行由控制器为
 * 「这一页能不能打开」的判断（跳转 / 下架 / 售罄）查过一次，通过 $goods_row 传下来复用，
 * 不再重复查库。本模板只有渲染。
 */
$_d = template_goods_content_data(is_array($goods_row ?? null) ? $goods_row : null);

// 与改造前同名的变量，下面正文一个字都不用改
$goods              = $_d['goods'];
$specs              = $_d['specs'];
$spec_dims          = $_d['spec_dims'];
$specs_json         = $_d['specs_json'];
$payment_methods    = $_d['payment_methods'];
$form_sections      = $_d['form_sections'];
$needs_address      = $_d['needs_address'];
$user_addresses     = $_d['user_addresses'];
$default_address_id = $_d['default_address_id'];
// $unavailable_reason 由控制器（守卫判断）直接 setData 下来，不经过数据方法

// 商品卡片上的展示开关
$_shopDispStock = (string) Config::get('shop_display_stock', '1') !== '0';
$_shopDispSales = (string) Config::get('shop_display_sales', '1') !== '0';
?>
<!-- 商品详情（GoodsController::displayContent） -->
<div class="page-body">

    <!-- 面包屑 -->
    <div class="breadcrumb">
        <a href="<?= url_home() ?>" data-pjax>首页</a>
        <span class="sep">/</span>
        <a href="<?= url_goods_list() ?>" data-pjax>商品列表</a>
        <span class="sep">/</span>
        商品详情
    </div>

    <?php if (!empty($goods)): ?>
    <!-- 商品信息 -->
    <div class="detail-card">
        <div class="detail-layout">
            <!-- 图片区 -->
            <div class="detail-gallery" id="goodsGallery">
                <?php
                $rawImages = !empty($goods['images']) ? (array) $goods['images'] : [(string) ($goods['image'] ?? '')];
                $images = array_values(array_filter($rawImages, static function ($u) {
                    if ($u === null) {
                        return false;
                    }
                    if (is_string($u)) {
                        return trim($u) !== '';
                    }
                    return !empty($u);
                }));
                ?>
                <div class="detail-img">
                    <?php if (!empty($images)): ?>
                    <img id="mainImage" src="<?= htmlspecialchars($images[0]) ?>" alt="<?= htmlspecialchars($goods['name']) ?>" style="cursor:zoom-in;">
                    <?php else: ?>
                    <div class="goods-no-image" role="img" aria-label="暂无商品图片"></div>
                    <?php endif; ?>
                </div>
                <?php if (count($images) > 1): ?>
                <div class="detail-thumbs">
                    <?php foreach ($images as $idx => $img): ?>
                    <div class="detail-thumb<?= $idx === 0 ? ' active' : '' ?>" data-index="<?= $idx ?>">
                        <img src="<?= htmlspecialchars($img) ?>" alt="">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <!-- viewer.js 隐藏容器 -->
                <div id="viewerImages" style="display:none;">
                    <?php foreach ($images as $img): ?>
                    <img src="<?= htmlspecialchars($img) ?>" alt="">
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- 信息区 -->
            <div class="detail-info">
                <div class="detail-name"><?= htmlspecialchars($goods['name']) ?></div>
                <?php if (($goods['delivery_type'] ?? '') === 'auto'): ?>
                <div style="margin-top:6px;"><span class="goods-badge goods-badge--auto">自动发货</span></div>
                <?php elseif (($goods['delivery_type'] ?? '') === 'manual'): ?>
                <div style="margin-top:6px;"><span class="goods-badge goods-badge--manual">人工发货</span></div>
                <?php endif; ?>

                <?php if (!empty($goods['description'])): ?>
                <div class="detail-intro"><?= nl2br(htmlspecialchars($goods['description'])) ?></div>
                <?php endif; ?>

                <!-- 销量 / 库存（展示用 stock_text，业务逻辑用 stock）；受后台「商城设置 → 库存与销量」开关控制 -->
                <?php if ($_shopDispStock || $_shopDispSales): ?>
                <div class="detail-stats-row">
                    <?php if ($_shopDispSales): ?>
                    <span class="detail-stat-item">销量 <strong id="soldInfo"><?= (int) ($goods['total_sold'] ?? 0) ?></strong></span>
                    <?php endif; ?>
                    <?php if ($_shopDispSales && $_shopDispStock): ?>
                    <span class="detail-stat-sep">|</span>
                    <?php endif; ?>
                    <?php if ($_shopDispStock): ?>
                    <span class="detail-stat-item">库存 <strong id="stockCount"><?= htmlspecialchars($goods['stock_text'] ?? '0') ?></strong></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="detail-price-box">
                    <!-- 首屏渲染用 displayMain 自动按访客币种换算；后续 JS renderCurrentPrice 会覆盖（单价 × 数量 - 插件优惠） -->
                    <span class="detail-price" id="specPrice"><?= Currency::displayMain((float) $goods['price']) ?></span>
                    <?php if (!empty($goods['original_price'])): ?>
                    <span class="detail-price-original" id="specMarketPrice"><?= Currency::displayMain((float) $goods['original_price']) ?></span>
                    <?php else: ?>
                    <span class="detail-price-original" id="specMarketPrice" style="display:none;"></span>
                    <?php endif; ?>
                    <!-- 价格区扩展点：插件渲染自己的让利提示（如「满 X 减 Y」）。
                         插件通过 GoodsDetail.addPriceAdjuster 参与价格计算，并监听
                         em:goodsPriceRendered 事件（ctx: {spec, qty, subtotal, discount, finalPrice}）刷新这里的展示。 -->
                    <?php doAction('goods_detail_price_extra', $goods, $specs); ?>
                </div>

                <?php if (!empty($specs) && count($specs) > 1): ?>
                <!-- 规格选择 -->
                <div class="spec-section">
                    <?php if (!empty($spec_dims)): ?>
                    <!-- 多维度：每个按钮上方有 .spec-item-tags 容器，由 JS refreshDimBtnTags()
                         按"假设选中此按钮形成的完整组合 spec.tags"动态填充。 -->
                    <?php foreach ($spec_dims as $dim): ?>
                    <div class="spec-group">
                        <div class="spec-group-label"><i class="fa fa-th-list"></i> <?= htmlspecialchars($dim['name']) ?></div>
                        <div class="spec-group-options">
                            <?php foreach ($dim['values'] as $val): ?>
                            <div class="spec-item">
                                <div class="spec-item-tags" style="display:none;"></div>
                                <button type="button" class="spec-btn spec-dim-btn"
                                        data-dim-id="<?= $dim['id'] ?>"
                                        data-value-id="<?= $val['id'] ?>"><?= htmlspecialchars($val['name']) ?></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <!-- 单维度：按钮和规格行一一对应，tags 直接静态贴在按钮上方 -->
                    <div class="spec-group">
                        <div class="spec-group-label"><i class="fa fa-th-list"></i> 规格</div>
                        <div class="spec-group-options">
                            <?php foreach ($specs as $s): ?>
                            <?php $outOfStock = ((int) ($s['stock'] ?? 0)) <= 0; ?>
                            <div class="spec-item">
                                <?php if (!empty($s['tags'])): ?>
                                <div class="spec-item-tags">
                                    <?php foreach ($s['tags'] as $t): ?>
                                    <span class="spec-tag"><?= htmlspecialchars((string) $t) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                <button type="button" class="spec-btn spec-single-btn<?= $s['is_default'] ? ' active' : '' ?><?= $outOfStock ? ' disabled' : '' ?>"
                                        data-spec-id="<?= $s['id'] ?>"
                                        <?= $outOfStock ? 'disabled' : '' ?>><?= htmlspecialchars($s['name']) ?></button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- 购买数量 -->
                <div class="detail-qty-row">
                    <span class="detail-qty-label"><i class="fa fa-cubes"></i> 数量</span>
                    <div class="detail-qty-wrap">
                        <div class="detail-qty-selector">
                            <button type="button" class="detail-qty-btn" id="qtyMinus">−</button>
                            <input type="number" class="detail-qty-input" id="qtyInput" value="<?= max(1, (int) ($goods['min_buy'] ?? 1)) ?>" min="1">
                            <button type="button" class="detail-qty-btn" id="qtyPlus">+</button>
                        </div>
                        <span class="detail-stock-info" id="stockInfo">
                            <?php
                            // 数量选择器右侧只显示限购（如商品设置了 max_buy > 0），库存信息已在上方展示
                            $isOutOfStock = ((int) ($goods['stock'] ?? 0)) <= 0;
                            ?>
                            <?php if ($isOutOfStock): ?>
                            <span style="color:#fa5252;">缺货</span>
                            <?php elseif ((int) ($goods['max_buy'] ?? 0) > 0): ?>
                            限购<?= (int) $goods['max_buy'] ?><?= htmlspecialchars($goods['unit']) ?>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php if ((int) ($goods['min_buy'] ?? 1) > 1): ?>
                    <div class="detail-min-buy" id="minBuyHint">最低购买<?= (int) $goods['min_buy'] ?><?= htmlspecialchars($goods['unit']) ?></div>
                    <?php else: ?>
                    <div class="detail-min-buy" id="minBuyHint" style="display:none;"></div>
                    <?php endif; ?>
                </div>

                <?php
                // 表单字段（附加选项 + 查单模式）由 module.php 的 template_goods_form_sections() 统一组装。
                // 顺序：附加选项 → 查单模式；查单模式仅未登录用户可见。
                //
                // 字段描述字段名：name / id / label / type / placeholder / required / maxlength / hidden
                // 统一类名：detail-form-field / detail-form-field-input / detail-form-field-label
                ?>
                <?php foreach (($form_sections ?? []) as $section): ?>
                    <?php $wrap = !empty($section['id']); ?>
                    <?php if ($wrap): ?>
                    <div class="detail-guest-find-section" id="<?= htmlspecialchars($section['id']) ?>">
                    <?php endif; ?>

                    <?php foreach ($section['fields'] as $f): ?>
                    <div class="detail-form-field">
                        <label class="detail-form-field-label">
                            <?= htmlspecialchars($f['label']) ?>
                            <?php if (!empty($f['required'])): ?><span style="color:#fa5252;margin-left:2px;">*</span><?php endif; ?>
                        </label>
                        <input type="<?= htmlspecialchars($f['type']) ?>"
                               class="detail-form-field-input"
                               <?php if (!empty($f['id'])): ?>id="<?= htmlspecialchars($f['id']) ?>"<?php endif; ?>
                               name="<?= htmlspecialchars($f['name']) ?>"
                               placeholder="<?= htmlspecialchars($f['placeholder'] ?? '') ?>"
                               maxlength="<?= (int) ($f['maxlength'] ?? 64) ?>"
                               style="max-width:330px;"
                               <?= !empty($f['required']) ? 'required' : '' ?>>
                        <?php if (!empty($f['hidden'])): ?>
                        <input type="hidden"
                               <?php if (!empty($f['hidden']['id'])): ?>id="<?= htmlspecialchars($f['hidden']['id']) ?>"<?php endif; ?>
                               <?php if (!empty($f['hidden']['name'])): ?>name="<?= htmlspecialchars($f['hidden']['name']) ?>"<?php endif; ?>
                               value="<?= htmlspecialchars($f['hidden']['value'] ?? '') ?>">
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($wrap): ?></div><?php endif; ?>
                <?php endforeach; ?>

                <!-- 优惠券输入（受后台「商城设置 → 启用优惠券」控制） -->
                <?php if (shop_coupon_enabled()): ?>
                <?php
                if (session_status() === PHP_SESSION_NONE) session_start();
                $isGuestDetail = empty($_SESSION['em_front_user']);
                ?>
                <div class="detail-coupon">
                    <div class="detail-coupon-title"><i class="fa fa-ticket"></i> 优惠券</div>
                    <div class="detail-coupon-input-row">
                        <input type="text" id="detailCouponInput" placeholder="输入优惠券码" maxlength="32" class="detail-coupon-input">
                        <button type="button" class="detail-coupon-btn detail-coupon-btn--primary" id="detailCouponApplyBtn">使用</button>
                    </div>
                    <?php if ($isGuestDetail): ?>
                    <div class="detail-coupon-guest-tip">
                        <i class="fa fa-info-circle"></i> 登录后可<a href="<?= url_coupon() ?>" data-pjax>领取并选择优惠券</a>
                    </div>
                    <?php else: ?>
                    <div class="detail-coupon-actions">
                        <button type="button" class="detail-coupon-choose" id="detailCouponChooseBtn">
                            <i class="fa fa-folder-open-o"></i> 从已领券中选择
                        </button>
                        <a href="<?= url_coupon() ?>" data-pjax class="detail-coupon-choose detail-coupon-goget">
                            <i class="fa fa-gift"></i> 去领优惠券
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="detail-coupon-applied" id="detailCouponApplied" style="display:none;">
                        <span class="detail-coupon-applied-name"></span>
                        <span class="detail-coupon-applied-saved"></span>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 支付方式 —— 控制器预先标好 disabled/selected，视图直接按标记渲染 -->
                <?php if (!empty($payment_methods)): ?>
                <div class="detail-payment-section">
                    <div class="detail-payment-title"><i class="fa fa-credit-card"></i> 支付方式</div>
                    <div class="detail-payment-list">
                        <?php foreach ($payment_methods as $pm): ?>
                        <?php
                        $classes = 'detail-payment-item';
                        if (!empty($pm['selected'])) $classes .= ' active';
                        if (!empty($pm['disabled'])) $classes .= ' is-disabled';
                        ?>
                        <button type="button" class="<?= $classes ?>"
                                data-code="<?= htmlspecialchars($pm['code']) ?>"
                                <?= !empty($pm['disabled']) ? 'disabled title="' . htmlspecialchars($pm['disabled_tip'] ?? '未登录用户无法使用余额支付') . '"' : '' ?>>
                            <img src="<?= htmlspecialchars($pm['image']) ?>" alt="<?= htmlspecialchars($pm['name']) ?>">
                            <span><?= htmlspecialchars($pm['display_name'] ?? $pm['name']) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 操作按钮 -->
                <div class="detail-actions">
                    <button class="btn btn-primary btn-lg" id="buyNowBtn" data-goods-id="<?= $goods['id'] ?>">立即购买</button>
                </div>

                <!-- 商品数据 / （插件提供的）附加选项卡 -->
                <?php
                // 附加选项卡扩展点：插件返回 [['id' => '元素id', 'label' => '导航文字', 'html' => '面板 HTML'], ...]
                // 有附加项时才显示 tab 导航，否则只展示「商品数据」的内容
                $extraMetaTabs = applyFilter('goods_detail_meta_tabs', [], $goods, $specs);
                $extraMetaTabs = is_array($extraMetaTabs) ? $extraMetaTabs : [];
                ?>
                <div class="detail-meta-tabs">
                    <?php if (!empty($extraMetaTabs)): ?>
                    <div class="detail-meta-tab-nav">
                        <button type="button" class="detail-meta-tab-btn active" data-tab="metaInfo">商品数据</button>
                        <?php foreach ($extraMetaTabs as $tab): ?>
                        <button type="button" class="detail-meta-tab-btn" data-tab="<?= htmlspecialchars((string) ($tab['id'] ?? '')) ?>"><?= htmlspecialchars((string) ($tab['label'] ?? '')) ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="detail-meta-tab-pane active" id="metaInfo">
                        <div class="detail-meta">
                            <?php if (!empty($goods['sku'])): ?>
                            <div class="detail-meta-row">
                                <span class="detail-meta-label">商品编号</span>
                                <span class="detail-meta-value"><?= htmlspecialchars($goods['sku']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($goods['category'])): ?>
                            <div class="detail-meta-row">
                                <span class="detail-meta-label">商品分类</span>
                                <span class="detail-meta-value"><?= htmlspecialchars($goods['category']) ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if (empty($specs) || count($specs) <= 1): ?>
                            <?php if ($_shopDispStock && isset($goods['stock_text'])): ?>
                            <div class="detail-meta-row">
                                <span class="detail-meta-label">库存</span>
                                <span class="detail-meta-value">
                                    <?= htmlspecialchars($goods['stock_text'] . ' ' . $goods['unit']) ?>
                                </span>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php foreach ($extraMetaTabs as $tab): ?>
                    <div class="detail-meta-tab-pane" id="<?= htmlspecialchars((string) ($tab['id'] ?? '')) ?>"><?= $tab['html'] ?? '' ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($goods['content'])): ?>
    <!-- 商品详情内容 -->
    <div class="article-detail" style="margin-top:16px;">
        <div class="detail-body"><?= sanitize_rich_text((string) $goods['content']) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($goods['tags'])): ?>
    <!-- 商品标签 -->
    <div class="detail-tags goods-detail-tags" style="margin-top:16px; background:#fff; border-radius:8px; border:1px solid #ebeef5; padding:20px 40px;">
        <?php foreach ($goods['tags'] as $tag): ?>
        <a href="<?= url_goods_tag((int) $tag['id']) ?>" class="article-tag" data-pjax><?= htmlspecialchars($tag['name']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 核心：游客查单组件 + 下单/券接口（所有主题共用，必须在本主题 main.js 之前加载） -->
    <script src="/content/static/js/guest_find.js"></script>
    <?php if (!empty($needs_address)): ?>
    <!-- 收货地址选择（立即购买时弹层用）：cascade 库 + 样式 -->
    <link rel="stylesheet" href="/content/static/lib/cityAreaSelect/dist/css/cityAreaSelect.css">
    <script src="/content/static/lib/cityAreaSelect/dist/js/cityAreaSelect.min.js"></script>
    <?php endif; ?>
    <script src="/content/static/js/goods_order.js"></script>
    <!-- 本主题的界面逻辑 -->
    <script src="<?= htmlspecialchars(theme_asset_url('main.js', 'default')) ?>"></script>
    <script>
    // PJAX 导航时，jQuery 对外部 <script src> 走异步加载，而内联 <script> 会立即执行；
    // 会出现 GoodsDetail 还未定义就调用 init 的情况。用轮询等依赖就绪再启动。
    (function bootGoodsDetail() {
        if (typeof GoodsDetail === 'undefined') {
            setTimeout(bootGoodsDetail, 20);
            return;
        }
        GoodsDetail.init({
            specs: <?= $specs_json ?? '[]' ?>,
            currencySymbol: <?= json_encode($currency_symbol) ?>,
            goodsUnit: <?= json_encode($goods['unit'] ?? '件') ?>,
            // 插件要下发给前台 JS 的数据，走 specs_json（goods_detail_specs 过滤器）或自己的接口
            // 下单地址相关：controller 已按 goods_type.needs_address + 登录态预取
            needsAddress: <?= !empty($needs_address) ? 'true' : 'false' ?>,
            isGuest: <?= empty($front_user) ? 'true' : 'false' ?>,
            userAddresses: <?= json_encode($user_addresses ?? [], JSON_UNESCAPED_UNICODE) ?>,
            defaultAddressId: <?= (int) ($default_address_id ?? 0) ?>,
            enableCoupon: <?= shop_coupon_enabled() ? 'true' : 'false' ?>
        });
    })();
    </script>

    <?php else: ?>
    <div class="card empty-state">
        <?php if (($unavailable_reason ?? '') === 'sold_out'): ?>
        <div class="empty-icon">&#128722;</div>
        <h3>商品已售罄</h3>
        <p>该商品暂时缺货，无法购买</p>
        <?php else: ?>
        <div class="empty-icon">&#128722;</div>
        <h3>商品不存在或已下架</h3>
        <p>该商品可能已被移除</p>
        <?php endif; ?>
        <a href="<?= url_goods_list() ?>" data-pjax class="btn btn-primary">浏览其他商品</a>
    </div>
    <?php endif; ?>

</div>
