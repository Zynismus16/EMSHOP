<?php
defined('EM_ROOT') || exit('access denied!');

/**
 * 商品列表页。
 *
 * 布局：左侧商品分类（可折叠，带商品计数）+ 右侧面包屑/排序/商品网格/分页。
 * 请求参数由 module.php 解析（`$list_q`——页面标题必须在 body 渲染前确定，所以参数解析
 * 放在那儿），数据由 module.php 的 template_goods_list_data() 取好。本模板只有渲染。
 *
 * 控制器 GoodsController::display() 只做「调用页面」，不参与取数。
 * 分类折叠用的是 footer.php 里已有的全局委托（.sidebar-cat-arrow），本页无需额外 JS。
 */
$_d = template_goods_list_data(is_array($list_q ?? null) ? $list_q : []);

// 与改造前同名的变量，下面正文一个字都不用改
$goods_list             = $_d['goods_list'];
$pagination             = $_d['pagination'];
$goods_categories       = $_d['goods_categories'];
$current_category       = $_d['category_id'];
$current_category_source = $_d['category_source'];
$current_tag            = $_d['tag_id'];
$goods_sort             = $_d['sort'];
$announcement           = $_d['announcement'];
$_goods_sort_extra      = $_d['sort_params'];
$goods_list_base_params = $_d['base_params'];
$filter_label           = $_d['filter_label'];

// 商品卡片上的展示开关
$_shopDispStock = (string) Config::get('shop_display_stock', '1') !== '0';
$_shopDispSales = (string) Config::get('shop_display_sales', '1') !== '0';
$_blockSoldOut  = shop_block_sold_out_access();

// 当前分类属于哪个父级（用于高亮父级 + 默认展开它的子级）
$activeParentId = 0;
foreach ($goods_categories as $_cat) {
    if ((int) $_cat['id'] === $current_category) { $activeParentId = (int) $_cat['id']; break; }
    foreach ($_cat['children'] ?? [] as $_child) {
        if ((int) $_child['id'] === $current_category) { $activeParentId = (int) $_cat['id']; break 2; }
    }
}

// 全部商品数（顶级分类自身 + 子分类之和）
$allGoodsCount = 0;
foreach ($goods_categories as $_c) {
    $allGoodsCount += (int) $_c['goods_count'];
    foreach ($_c['children'] ?? [] as $_ch) {
        $allGoodsCount += (int) $_ch['goods_count'];
    }
}
?>
<!-- 商品列表（GoodsController::display） -->

<?php
// 店铺公告 —— 当前 scope 已设公告且勾选了"商品列表页"展示位置时输出
$_announce = $announcement ?? null;
if (is_array($_announce) && !empty($_announce['html']) && in_array('goods_list', $_announce['positions'] ?? [], true)):
?>
<div class="wrapper">
    <div class="site-announcement">
        <div class="site-announcement__head">
            <span class="site-announcement__icon"><i class="fa fa-bullhorn"></i></span>
            <span class="site-announcement__title">店铺公告</span>
            <span class="site-announcement__title-sep"></span>
        </div>
        <div class="site-announcement__body"><?= sanitize_rich_text((string) $_announce['html']) ?></div>
    </div>
</div>
<?php endif; ?>

<div class="page-body">
    <div class="shop-layout">

        <!-- ① 左侧：商品分类（移动端变成左侧抽屉，由「筛选分类」按钮唤出） -->
        <aside class="shop-side" id="shopSide">
            <div class="shop-side-card">
                <div class="shop-side-title">
                    商品分类
                    <span class="shop-side-close" id="shopSideClose" role="button" aria-label="关闭"><i class="fa fa-times"></i></span>
                </div>
                <div class="sidebar-cat-list">

                    <!-- 全部商品 -->
                    <div class="sidebar-cat-group">
                        <div class="sidebar-cat-parent-row">
                            <a href="<?= url_goods_list($_goods_sort_extra) ?>"
                               class="sidebar-cat-parent<?= $current_category === 0 && $current_tag === 0 ? ' is-active' : '' ?>"
                               data-pjax>
                                <i class="fa fa-th-large sidebar-cat-ico"></i>
                                <span class="sidebar-cat-name">全部商品</span>
                                <span class="sidebar-cat-count"><?= $allGoodsCount ?></span>
                            </a>
                        </div>
                    </div>

                    <?php foreach ($goods_categories as $cat): ?>
                    <?php
                    // 当前分类在这个父级分支里 → 高亮父级、默认展开子级
                    $isActiveBranch = ((int) $cat['id'] === $activeParentId) || ((int) $cat['id'] === $current_category);
                    ?>
                    <div class="sidebar-cat-group">
                        <div class="sidebar-cat-parent-row">
                            <a href="<?= url_goods_category($cat, $_goods_sort_extra) ?>"
                               class="sidebar-cat-parent<?= (int) $cat['id'] === $current_category ? ' is-active' : '' ?>"
                               data-pjax>
                                <?php if (!empty($cat['icon'])): ?>
                                <img class="sidebar-cat-icon" src="<?= htmlspecialchars($cat['icon']) ?>" alt="">
                                <?php else: ?>
                                <i class="fa fa-folder-o sidebar-cat-ico"></i>
                                <?php endif; ?>
                                <span class="sidebar-cat-name"><?= htmlspecialchars($cat['name']) ?></span>
                                <?php if (empty($cat['children'])): ?>
                                <span class="sidebar-cat-count"><?= (int) $cat['goods_count'] ?></span>
                                <?php endif; ?>
                            </a>
                            <?php if (!empty($cat['children'])): ?>
                            <span class="sidebar-cat-arrow<?= $isActiveBranch ? ' is-open' : '' ?>"><i class="fa fa-chevron-down"></i></span>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($cat['children'])): ?>
                        <div class="sidebar-cat-children" style="display:<?= $isActiveBranch ? 'block' : 'none' ?>;">
                            <?php foreach ($cat['children'] as $child): ?>
                            <a href="<?= url_goods_category($child, $_goods_sort_extra) ?>"
                               class="sidebar-cat-child<?= (int) $child['id'] === $current_category ? ' is-active' : '' ?>"
                               data-pjax>
                                <span class="sidebar-cat-name"><?= htmlspecialchars($child['name']) ?></span>
                                <span class="sidebar-cat-count"><?= (int) $child['goods_count'] ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                </div>
            </div>
        </aside>

        <!-- ② 右侧：排序 + 商品 + 分页 -->
        <div class="shop-main">

<?php /* 移动端才显示：唤出左侧分类抽屉（桌面端 CSS 隐藏）。控制行顶到列 0，避免缩进被输出 */ ?>
            <button type="button" class="shop-filter-btn" id="shopFilterBtn">
                <span class="shop-filter-btn__ico" aria-hidden="true"><i class="fa fa-filter"></i></span>
                <span class="shop-filter-btn__text">
                    <span class="shop-filter-btn__label">筛选分类</span>
                    <span class="shop-filter-btn__cur"><?= htmlspecialchars($filter_label) ?></span>
                </span>
                <i class="fa fa-angle-right shop-filter-btn__arrow" aria-hidden="true"></i>
            </button>

<?php /* 排序栏：可在主题设置里关掉（「商品列表排序功能」开关，默认开启）。
         控制行必须顶到列 0：PHP 会吞掉 ?> 后的换行，带缩进会让下一行缩进叠加，
         破坏与改造前逐字节一致的比对。 */ ?>
<?php if (!empty($_d['sort_enabled'])): ?>
            <?php
            $_goods_sort_opts = [
                'default'    => '默认排序',
                'hot'        => '热度优先',
                'sold'       => '销量优先',
                'price_asc'  => '价格升序',
                'price_desc' => '价格降序',
            ];
            ?>
            <div class="goods-sort-bar" role="toolbar" aria-label="商品排序">
                <?php foreach ($_goods_sort_opts as $_sk => $_sl): ?>
                <?php
                $_hrefParams = $goods_list_base_params;
                if ($_sk !== 'default') {
                    $_hrefParams['sort'] = $_sk;
                }
                ?>
                <a href="<?= htmlspecialchars(url_goods_list($_hrefParams), ENT_QUOTES, 'UTF-8') ?>"
                   class="goods-sort-link<?= $goods_sort === $_sk ? ' active' : '' ?>"
                   data-pjax><?= htmlspecialchars($_sl) ?></a>
                <?php endforeach; ?>
            </div>
<?php endif; ?>

            <!-- 商品网格 -->
            <?php if (!empty($goods_list)): ?>
            <div class="goods-grid">
                <?php foreach ($goods_list as $g): ?>
                <?php $isSoldOut = ((int) ($g['stock'] ?? 0)) === 0; ?>
                <a <?= goods_card_href_attrs($g) ?> class="card goods-card<?= $isSoldOut ? ' stock-empty-box' : '' ?><?= ($isSoldOut && $_blockSoldOut) ? ' is-blocked' : '' ?>">
                    <div class="card-img">
                        <?php if (trim((string) ($g['image'] ?? '')) !== ''): ?>
                        <img src="<?= htmlspecialchars($g['image']) ?>" alt="<?= htmlspecialchars($g['name']) ?>">
                        <?php else: ?>
                        <div class="goods-no-image" aria-hidden="true"></div>
                        <?php endif; ?>
                        <?php if ($isSoldOut): ?>
                        <span class="goods-soldout-stamp" aria-hidden="true"><span class="goods-soldout-stamp__text">已售罄</span></span>
                        <?php endif; ?>
                        <?php if (($g['delivery_type'] ?? '') === 'auto'): ?>
                        <span class="goods-badge goods-badge--auto">自动发货</span>
                        <?php elseif (($g['delivery_type'] ?? '') === 'manual'): ?>
                        <span class="goods-badge goods-badge--manual">人工发货</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <div class="card-title"><?= htmlspecialchars($g['name']) ?></div>
                        <?php if ($_shopDispStock || $_shopDispSales): ?>
                        <div class="card-stats">
                            <?php if ($_shopDispStock): ?>
                            <span>库存 <?= htmlspecialchars((string) ($g['stock_text'] ?? '0')) ?></span>
                            <?php endif; ?>
                            <?php if ($_shopDispSales): ?>
                            <span>销量 <?= (int) ($g['sold'] ?? 0) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="card-bottom">
                            <span class="price"><?= Currency::displayMain((float) $g['price']) ?></span>
                            <?php if (!empty($g['original_price'])): ?>
                            <span class="price-original"><?= Currency::displayMain((float) $g['original_price']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <!-- 分页 -->
            <?php if (!empty($pagination) && $pagination['total_pages'] > 1): ?>
            <?php
            $pg = $pagination;
            $pgParams = $goods_list_base_params;
            if ($goods_sort !== 'default') {
                $pgParams['sort'] = $goods_sort;
            }
            ?>
            <div class="pagination">
                <?php if ($pg['page'] > 1): ?>
                <a href="<?= url_goods_list(array_merge($pgParams, ['page' => $pg['page'] - 1])) ?>" class="pagination-btn" data-pjax><i class="fa fa-chevron-left"></i></a>
                <?php else: ?>
                <span class="pagination-btn disabled"><i class="fa fa-chevron-left"></i></span>
                <?php endif; ?>

                <?php
                // 页码范围：最多显示 5 个页码
                $start = max(1, $pg['page'] - 2);
                $end = min($pg['total_pages'], $start + 4);
                $start = max(1, $end - 4);
                ?>
                <?php if ($start > 1): ?>
                <a href="<?= url_goods_list(array_merge($pgParams, ['page' => 1])) ?>" class="pagination-num" data-pjax>1</a>
                <?php if ($start > 2): ?><span class="pagination-dots">...</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($i = $start; $i <= $end; $i++): ?>
                <?php if ($i === $pg['page']): ?>
                <span class="pagination-num active"><?= $i ?></span>
                <?php else: ?>
                <a href="<?= url_goods_list(array_merge($pgParams, ['page' => $i])) ?>" class="pagination-num" data-pjax><?= $i ?></a>
                <?php endif; ?>
                <?php endfor; ?>

                <?php if ($end < $pg['total_pages']): ?>
                <?php if ($end < $pg['total_pages'] - 1): ?><span class="pagination-dots">...</span><?php endif; ?>
                <a href="<?= url_goods_list(array_merge($pgParams, ['page' => $pg['total_pages']])) ?>" class="pagination-num" data-pjax><?= $pg['total_pages'] ?></a>
                <?php endif; ?>

                <?php if ($pg['page'] < $pg['total_pages']): ?>
                <a href="<?= url_goods_list(array_merge($pgParams, ['page' => $pg['page'] + 1])) ?>" class="pagination-btn" data-pjax><i class="fa fa-chevron-right"></i></a>
                <?php else: ?>
                <span class="pagination-btn disabled"><i class="fa fa-chevron-right"></i></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="card empty-state">
                <div class="empty-icon">&#128722;</div>
                <h3>暂无商品</h3>
                <p>还没有上架任何商品</p>
            </div>
            <?php endif; ?>

        </div>
    </div>

<?php /* 移动端分类抽屉的遮罩。放在 PJAX 替换范围内：点分类跳转后它随内容一起被替换，
         抽屉的 is-open 也随之消失，不用额外 JS 收尾 */ ?>
    <div class="shop-mask" id="shopMask"></div>
</div>
