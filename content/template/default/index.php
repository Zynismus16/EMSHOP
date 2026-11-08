<?php
defined('EM_ROOT') || exit('access denied!');

/**
 * 首页模板。
 *
 * 结构（两个板块，参考发卡站常见形态）：
 *   1. 精选商品 —— 后台标了「推荐」的商品，最多 FEATURED_MAX 个；右上角「查看全部商品」进列表页
 *   2. 最新动态 —— 最新文章（站点关掉博客导航时不输出）
 * 上面还可能有 hero 轮播与店铺公告，两者都由配置驱动，站长没配就不输出。
 *
 * 为什么限量：首页是展示位不是目录。商品上万时铺全部会产出几 MB HTML + 上万个图片请求，
 * 也没人会滚到底；全量浏览交给列表页（它带分页、分类、排序、标签筛选，首页没有这些）。
 * 卡片图片：首行不加 lazy（在首屏，加了反而推迟 LCP），其余 loading="lazy"。
 *
 * 数据由 module.php 的 template_home_data() 取好（本模板只有渲染）；控制器只做路由和渲染。
 */
// 数据由 module.php 的 template_home_data() 取好（含主题配置里的精选商品数量），
// 本模板只有渲染。
$_home = template_home_data();

$featured        = $_home['featured'];
$recent_articles = $_home['recent_articles'];
$announcement    = $_home['announcement'];

// 商品卡片上的展示开关
$_shopDispStock = (string) Config::get('shop_display_stock', '1') !== '0';
$_shopDispSales = (string) Config::get('shop_display_sales', '1') !== '0';
$_blockSoldOut = shop_block_sold_out_access();
?>

<?php
// 店铺公告 —— 当前 scope 已设公告且勾选了"商城首页"展示位置时输出
$_announce = $announcement ?? null;
if (is_array($_announce) && !empty($_announce['html']) && in_array('home', $_announce['positions'] ?? [], true)):
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

    <!-- ① 精选商品 -->
    <section class="section home-sec">
        <div class="section-header">
            <div class="section-title">精选商品</div>
            <a href="<?= url_goods_list() ?>" data-pjax class="home-all-btn">
                查看全部商品 <i class="fa fa-angle-right"></i>
            </a>
        </div>
        <?php if (!empty($featured)): ?>
        <div class="goods-grid home-featured">
            <?php $_cardIdx = 0; foreach ($featured as $g): $_cardIdx++; ?>
            <?php $isSoldOut = ((int) ($g['stock'] ?? 0)) === 0; ?>
            <a <?= goods_card_href_attrs($g) ?> class="card goods-card<?= $isSoldOut ? ' stock-empty-box' : '' ?><?= ($isSoldOut && $_blockSoldOut) ? ' is-blocked' : '' ?>">
                <div class="card-img">
                    <?php if (trim((string) ($g['image'] ?? '')) !== ''): ?>
                    <img src="<?= htmlspecialchars($g['image']) ?>" alt="<?= htmlspecialchars($g['name']) ?>" decoding="async"<?= $_cardIdx > 4 ? ' loading="lazy"' : '' ?>>
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
        <?php else: ?>
        <div class="card empty-state empty-state--rich">
            <div class="empty-decor">
                <span class="empty-decor__dot empty-decor__dot--1"></span>
                <span class="empty-decor__dot empty-decor__dot--2"></span>
                <span class="empty-decor__dot empty-decor__dot--3"></span>
                <span class="empty-decor__ring"></span>
            </div>
            <div class="empty-icon empty-icon--glow"><i class="fa fa-shopping-bag"></i></div>
            <h3>商品正在精心挑选中</h3>
            <p>店主正在为你筛选最值得入手的好物，稍后再来看看吧～</p>
        </div>
        <?php endif; ?>
    </section>

    <!-- ② 最新动态（站点关掉博客导航时整块不输出；没文章时显示空态，便于站长知道该发内容） -->
    <?php if (!empty($nav_blog_enabled)): ?>
    <section class="section home-sec">
        <div class="section-header">
            <div class="section-title">最新动态</div>
        </div>
        <?php if (!empty($recent_articles)): ?>
        <div class="article-grid">
            <?php foreach ($recent_articles as $a): ?>
            <a href="<?= url_blog((int) $a['id']) ?>" class="card article-grid-card">
                <?php if (!empty($a['image'])): ?>
                <div class="article-grid-img"><img src="<?= htmlspecialchars($a['image']) ?>" alt="<?= htmlspecialchars($a['title']) ?>" decoding="async" loading="lazy"></div>
                <?php endif; ?>
                <div class="article-grid-body">
                    <div class="card-title"><?= htmlspecialchars($a['title']) ?></div>
                    <div class="card-excerpt"><?= htmlspecialchars(truncate($a['excerpt'], 60)) ?></div>
                    <div class="card-meta">
                        <span><?= htmlspecialchars($a['date']) ?></span>
                        <span>&middot;</span>
                        <span><?= (int) $a['views'] ?> 阅读</span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="card empty-state empty-state--rich">
            <div class="empty-decor">
                <span class="empty-decor__dot empty-decor__dot--1"></span>
                <span class="empty-decor__dot empty-decor__dot--2"></span>
                <span class="empty-decor__dot empty-decor__dot--3"></span>
                <span class="empty-decor__ring"></span>
            </div>
            <div class="empty-icon empty-icon--glow"><i class="fa fa-pencil-square-o"></i></div>
            <h3>博客频道建设中</h3>
            <p>站长正在打磨第一批精选内容，敬请期待。</p>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

</div>
