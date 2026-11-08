<?php
defined('EM_ROOT') || exit('access denied!');
/**
 * 自定义页面（CMS）· PageController::_detail()
 *
 * 页头 / 页尾由 View::render() 自动包裹（header.php / footer.php）。
 * 此文件只渲染中间的正文区。
 *
 * 可用变量：
 *   $page = [
 *     'id', 'title', 'slug', 'content', 'template_name',
 *     'seo_title', 'seo_keywords', 'seo_description',
 *     'views', 'created_at', 'updated_at',
 *   ]
 */
$page = $page ?? [];
$title   = (string) ($page['title']   ?? '');
$content = (string) ($page['content'] ?? '');
?>
<div class="page-body">

    <!-- 面包屑 -->
    <div class="breadcrumb">
        <a href="<?= url_home() ?>" data-pjax>首页</a>
        <span class="sep">/</span>
        <?= htmlspecialchars($title) ?>
    </div>

    <!-- 正文 -->
    <article class="article-detail">
        <div class="detail-title"><?= htmlspecialchars($title) ?></div>
        <?php if (!empty($page['updated_at'])): ?>
        <div class="detail-meta">
            <span>最近更新：<?= htmlspecialchars(substr((string) $page['updated_at'], 0, 10)) ?></span>
            <?php if (!empty($page['views'])): ?>
            <span>阅读：<?= (int) $page['views'] ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <div class="detail-body"><?= sanitize_rich_text((string) $content) ?></div>
    </article>

</div>
