<?php
defined('EM_ROOT') || exit('access denied!');

/**
 * 文章详情页。
 *
 * 本模板只有渲染：文章行由控制器为「这一页能不能打开」的判断和页面标题查过一次，
 * 通过 $article_row 传下来复用（不再重复查库）；正文装配、上下篇、评论数、侧栏
 * 都由 module.php 的 template_blog_content_data() 取。
 */
$_d = template_blog_content_data(is_array($article_row ?? null) ? $article_row : null);

// 与改造前同名的变量，下面正文一个字都不用改
$article           = $_d['article'];
$prev_id           = $_d['prev_id'];
$prev_title        = $_d['prev_title'];
$next_id           = $_d['next_id'];
$next_title        = $_d['next_title'];
$comment_count     = $_d['comment_count'];
$recent_articles   = $_d['recent_articles'];
$blog_categories   = $_d['blog_categories'];
$popular_blog_tags = $_d['popular_blog_tags'];
?>
<!-- 文章详情（BlogController::displayContent） -->
<div class="page-body">

    <!-- 面包屑 -->
    <div class="breadcrumb">
        <a href="<?= url_home() ?>" data-pjax>首页</a>
        <span class="sep">/</span>
        <a href="<?= url_blog_list() ?>" data-pjax>文章列表</a>
        <span class="sep">/</span>
        文章详情
    </div>

    <div class="blog-layout">

        <!-- 主内容 -->
        <div class="blog-main">
            <?php if (!empty($article)): ?>
            <!-- 文章内容 -->
            <div class="article-detail">
                <div class="detail-title"><?= htmlspecialchars($article['title']) ?></div>
                <div class="detail-meta">
                    <span>发布于 <?= htmlspecialchars($article['date']) ?></span>
                    <span>作者：<?= htmlspecialchars($article['author'] ?? '管理员') ?></span>
                    <span>分类：<?= htmlspecialchars($article['category'] ?? '技术') ?></span>
                    <span>阅读：<?= (int) ($article['views'] ?? 0) ?></span>
                </div>
                <div class="detail-body"><?= sanitize_rich_text((string) $article['content']) ?></div>
                <?php if (!empty($article['tags'])): ?>
                <div class="detail-tags">
                    <?php foreach ($article['tags'] as $tag): ?>
                    <a href="<?= url_blog_tag((int) $tag['id']) ?>" class="article-tag" data-pjax><?= htmlspecialchars($tag['name']) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- 上下篇导航 -->
            <div class="article-nav">
                <?php if (!empty($prev_id)): ?>
                <a href="<?= url_blog((int) $prev_id) ?>" data-pjax>&laquo; 上一篇：<?= htmlspecialchars($prev_title ?? '') ?></a>
                <?php else: ?>
                <span style="color:#ccc;">&laquo; 没有了</span>
                <?php endif; ?>
                <?php if (!empty($next_id)): ?>
                <a href="<?= url_blog((int) $next_id) ?>" data-pjax>下一篇：<?= htmlspecialchars($next_title ?? '') ?> &raquo;</a>
                <?php else: ?>
                <span style="color:#ccc;">没有了 &raquo;</span>
                <?php endif; ?>
            </div>

            <!-- 评论区 -->
            <div class="comment-section" id="commentSection" data-blog-id="<?= (int) $article['id'] ?>">
                <div class="comment-header">
                    <div class="comment-header-left">
                        <span class="comment-title">评论</span>
                        <span class="comment-count" id="commentTotal"><?= (int) ($comment_count ?? 0) ?></span>
                    </div>
                    <div class="comment-sort">
                        <span class="comment-sort-btn active" data-sort="newest">最新</span>
                        <span class="comment-sort-btn" data-sort="oldest">最早</span>
                        <span class="comment-sort-btn" data-sort="hot">最热</span>
                    </div>
                </div>

                <!-- 发表评论 -->
                <div class="comment-form-box" id="commentFormBox">
                    <?php if (!empty($front_user)): ?>
                    <div class="comment-form-wrapper">
                        <div class="comment-form-avatar">
                            <?php if (!empty($front_user['avatar'])): ?>
                            <img src="<?= htmlspecialchars($front_user['avatar']) ?>" alt="">
                            <?php else: ?>
                            <i class="fa fa-user"></i>
                            <?php endif; ?>
                        </div>
                        <div class="comment-form-body">
                            <textarea class="comment-textarea" id="commentInput" placeholder="写下你的评论..." maxlength="1000"></textarea>
                            <div class="comment-form-footer">
                                <span class="comment-char-count"><span id="commentCharCount">0</span>/1000</span>
                                <button class="comment-submit-btn" id="commentSubmitBtn">发表评论</button>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="comment-login-tip">
                        <i class="fa fa-commenting-o"></i>
                        <?php if (!empty($user_login_enabled)): ?>
                        <span>登录后参与评论</span>
                        <a href="?c=login" class="comment-login-btn">登录</a>
                        <?php else: ?>
                        <span>评论功能暂未开放</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 评论列表 -->
                <div class="comment-list" id="commentList">
                    <div class="comment-loading" id="commentLoading"><i class="fa fa-spinner fa-spin"></i> 加载中...</div>
                </div>

                <!-- 评论分页 -->
                <div class="comment-pagination" id="commentPagination"></div>
            </div>

            <script>
            (function(){
                var $section = $('#commentSection');
                if (!$section.length) return;

                var blogId = $section.data('blog-id');
                var currentSort = 'newest';
                var currentPage = 1;
                var perPage = 10;
                var isLoggedIn = <?= !empty($front_user) ? 'true' : 'false' ?>;

                // ============================================================
                // 加载评论列表
                // ============================================================
                function loadComments(page, sort) {
                    currentPage = page || 1;
                    currentSort = sort || currentSort;
                    $('#commentLoading').show();
                    $('#commentList .comment-item, #commentList .comment-empty').remove();
                    $('#commentPagination').empty();

                    $.post('?c=blog_comment', {
                        _action: 'list',
                        blog_id: blogId,
                        page: currentPage,
                        limit: perPage,
                        sort: currentSort
                    }, function(res) {
                        $('#commentLoading').hide();
                        if (res.code !== 200 || !res.data) return;

                        var data = res.data;
                        $('#commentTotal').text(data.total);

                        if (!data.list || data.list.length === 0) {
                            $('#commentList').append('<div class="comment-empty">暂无评论，来抢沙发吧~</div>');
                            return;
                        }

                        $.each(data.list, function(i, c) {
                            $('#commentList').append(renderComment(c, false));
                        });

                        // 分页
                        if (data.total > perPage) {
                            renderPagination(data.total, currentPage, perPage, '#commentPagination', function(p) {
                                loadComments(p);
                            });
                        }
                    }, 'json');
                }

                // ============================================================
                // 渲染单条评论
                // ============================================================
                function renderComment(c, isReply) {
                    var displayName = c.nickname || c.username || '用户';
                    var avatarHtml = c.avatar
                        ? '<img src="' + escHtml(c.avatar) + '" alt="">'
                        : '<i class="fa fa-user"></i>';

                    var replyToHtml = '';
                    if (isReply && c.reply_user_id && c.reply_user_id != '0') {
                        var replyName = c.reply_nickname || c.reply_username || '用户';
                        replyToHtml = '<span class="comment-reply-to">回复 <b>' + escHtml(replyName) + '</b></span>';
                    }

                    var actionsHtml = '';
                    if (isLoggedIn) {
                        actionsHtml += '<a href="javascript:;" class="comment-action-btn comment-reply-btn" data-id="' + c.id + '" data-user-id="' + c.user_id + '" data-name="' + escAttr(displayName) + '" data-parent-id="' + (isReply ? c.parent_id : c.id) + '"><i class="fa fa-reply"></i> 回复</a>';
                    }

                    var deleteHtml = '';
                    if (isLoggedIn && c.user_id == <?= (int)($front_user['id'] ?? 0) ?>) {
                        deleteHtml = '<a href="javascript:;" class="comment-action-btn comment-delete-btn" data-id="' + c.id + '"><i class="fa fa-trash-o"></i> 删除</a>';
                    }

                    var repliesHtml = '';
                    if (!isReply && c.replies && c.replies.length > 0) {
                        repliesHtml = '<div class="comment-replies">';
                        $.each(c.replies, function(j, r) {
                            repliesHtml += renderComment(r, true);
                        });
                        if (c.reply_total > 3) {
                            repliesHtml += '<div class="comment-load-more-replies">'
                                + '<a href="javascript:;" class="comment-load-replies-btn" data-parent-id="' + c.id + '" data-page="2" data-total="' + c.reply_total + '">'
                                + '展开更多回复（共' + c.reply_total + '条）<i class="fa fa-chevron-down"></i></a></div>';
                        }
                        repliesHtml += '</div>';
                    } else if (!isReply && c.reply_count > 0 && (!c.replies || c.replies.length === 0)) {
                        repliesHtml = '<div class="comment-replies">'
                            + '<div class="comment-load-more-replies">'
                            + '<a href="javascript:;" class="comment-load-replies-btn" data-parent-id="' + c.id + '" data-page="1" data-total="' + c.reply_count + '">'
                            + '查看' + c.reply_count + '条回复 <i class="fa fa-chevron-down"></i></a></div>'
                            + '</div>';
                    }

                    var html = '<div class="comment-item' + (isReply ? ' comment-item--reply' : '') + '" data-id="' + c.id + '">'
                        + '<div class="comment-avatar">' + avatarHtml + '</div>'
                        + '<div class="comment-body">'
                        + '<div class="comment-meta">'
                        + '<span class="comment-author">' + escHtml(displayName) + '</span>'
                        + replyToHtml
                        + '<span class="comment-time">' + formatTime(c.created_at) + '</span>'
                        + '</div>'
                        + '<div class="comment-content">' + escHtml(c.content) + '</div>'
                        + '<div class="comment-actions">' + actionsHtml + deleteHtml + '</div>'
                        + repliesHtml
                        + '</div></div>';
                    return html;
                }

                // ============================================================
                // 加载更多回复
                // ============================================================
                $(document).on('click', '.comment-load-replies-btn', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    var parentId = $btn.data('parent-id');
                    var page = $btn.data('page');
                    var total = $btn.data('total');
                    var $repliesBox = $btn.closest('.comment-replies');

                    $btn.html('<i class="fa fa-spinner fa-spin"></i> 加载中...');

                    $.post('?c=blog_comment', {
                        _action: 'replies',
                        parent_id: parentId,
                        page: page,
                        limit: 5
                    }, function(res) {
                        if (res.code !== 200 || !res.data) return;
                        var data = res.data;

                        // 如果是第1页加载，清空之前预加载的回复
                        if (page == 1) {
                            $repliesBox.find('.comment-item--reply').remove();
                        }

                        // 在加载更多按钮之前插入回复
                        var $loadMore = $repliesBox.find('.comment-load-more-replies');
                        $.each(data.list, function(j, r) {
                            $(renderComment(r, true)).insertBefore($loadMore);
                        });

                        var totalPages = Math.ceil(data.total / data.limit);
                        if (page < totalPages) {
                            $btn.data('page', page + 1);
                            $btn.html('展开更多回复（共' + total + '条）<i class="fa fa-chevron-down"></i>');
                        } else {
                            $loadMore.remove();
                        }
                    }, 'json');
                });

                // ============================================================
                // 回复按钮
                // ============================================================
                $(document).on('click', '.comment-reply-btn', function(e) {
                    e.preventDefault();
                    // 移除之前的回复框
                    $('.comment-reply-form').remove();

                    var $btn = $(this);
                    var commentId = $btn.data('id');
                    var userId = $btn.data('user-id');
                    var name = $btn.data('name');
                    var parentId = $btn.data('parent-id');

                    var formHtml = '<div class="comment-reply-form">'
                        + '<textarea class="comment-textarea comment-reply-textarea" placeholder="回复 ' + escAttr(name) + '..." maxlength="1000"></textarea>'
                        + '<div class="comment-form-footer">'
                        + '<span class="comment-char-count"><span class="reply-char-count">0</span>/1000</span>'
                        + '<button class="comment-submit-btn comment-reply-submit" data-parent-id="' + parentId + '" data-reply-user-id="' + userId + '">回复</button>'
                        + '<button class="comment-cancel-btn comment-reply-cancel">取消</button>'
                        + '</div></div>';

                    $btn.closest('.comment-actions').after(formHtml);
                    $btn.closest('.comment-body').find('.comment-reply-textarea').focus();
                });

                // 取消回复
                $(document).on('click', '.comment-reply-cancel', function() {
                    $(this).closest('.comment-reply-form').remove();
                });

                // 回复字数统计
                $(document).on('input', '.comment-reply-textarea', function() {
                    $(this).closest('.comment-reply-form').find('.reply-char-count').text($(this).val().length);
                });

                // 提交回复
                $(document).on('click', '.comment-reply-submit', function() {
                    var $btn = $(this);
                    var $form = $btn.closest('.comment-reply-form');
                    var content = $.trim($form.find('.comment-reply-textarea').val());
                    if (!content) return;

                    $btn.prop('disabled', true).text('提交中...');
                    $.post('?c=blog_comment', {
                        _action: 'post',
                        blog_id: blogId,
                        parent_id: $btn.data('parent-id'),
                        reply_user_id: $btn.data('reply-user-id'),
                        content: content
                    }, function(res) {
                        $btn.prop('disabled', false).text('回复');
                        if (res.code === 200) {
                            $form.remove();
                            // 重新加载当前评论
                            loadComments(currentPage, currentSort);
                        } else {
                            alert(res.msg || '回复失败');
                        }
                    }, 'json');
                });

                // ============================================================
                // 发表顶级评论
                // ============================================================
                var $input = $('#commentInput');
                if ($input.length) {
                    $input.on('input', function() {
                        $('#commentCharCount').text($(this).val().length);
                    });

                    $('#commentSubmitBtn').on('click', function() {
                        var content = $.trim($input.val());
                        if (!content) return;

                        var $btn = $(this);
                        $btn.prop('disabled', true).text('提交中...');
                        $.post('?c=blog_comment', {
                            _action: 'post',
                            blog_id: blogId,
                            parent_id: 0,
                            reply_user_id: 0,
                            content: content
                        }, function(res) {
                            $btn.prop('disabled', false).text('发表评论');
                            if (res.code === 200) {
                                $input.val('');
                                $('#commentCharCount').text('0');
                                loadComments(1, 'newest');
                            } else {
                                alert(res.msg || '评论失败');
                            }
                        }, 'json');
                    });
                }

                // ============================================================
                // 删除评论
                // ============================================================
                $(document).on('click', '.comment-delete-btn', function(e) {
                    e.preventDefault();
                    if (!confirm('确定要删除这条评论吗？')) return;
                    var $item = $(this).closest('.comment-item');
                    var id = $(this).data('id');

                    $.post('?c=blog_comment', {
                        _action: 'delete',
                        id: id
                    }, function(res) {
                        if (res.code === 200) {
                            loadComments(currentPage, currentSort);
                        } else {
                            alert(res.msg || '删除失败');
                        }
                    }, 'json');
                });

                // ============================================================
                // 排序切换
                // ============================================================
                $section.on('click', '.comment-sort-btn', function() {
                    var sort = $(this).data('sort');
                    $section.find('.comment-sort-btn').removeClass('active');
                    $(this).addClass('active');
                    loadComments(1, sort);
                });

                // ============================================================
                // 分页渲染
                // ============================================================
                function renderPagination(total, page, limit, container, onClick) {
                    var totalPages = Math.ceil(total / limit);
                    if (totalPages <= 1) return;
                    var html = '';
                    if (page > 1) html += '<a href="javascript:;" class="comment-page-btn" data-page="' + (page - 1) + '">&laquo;</a>';
                    for (var i = 1; i <= totalPages; i++) {
                        if (totalPages > 7 && Math.abs(i - page) > 2 && i !== 1 && i !== totalPages) {
                            if (i === 2 || i === totalPages - 1) html += '<span class="comment-page-dots">...</span>';
                            continue;
                        }
                        html += '<a href="javascript:;" class="comment-page-btn' + (i === page ? ' active' : '') + '" data-page="' + i + '">' + i + '</a>';
                    }
                    if (page < totalPages) html += '<a href="javascript:;" class="comment-page-btn" data-page="' + (page + 1) + '">&raquo;</a>';
                    $(container).html(html);
                    $(container).off('click').on('click', '.comment-page-btn', function(e) {
                        e.preventDefault();
                        onClick($(this).data('page'));
                    });
                }

                // ============================================================
                // 工具函数
                // ============================================================
                function escHtml(str) {
                    if (!str) return '';
                    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
                }
                function escAttr(str) {
                    return escHtml(str).replace(/'/g, '&#39;');
                }
                function formatTime(datetime) {
                    if (!datetime) return '';
                    var d = new Date(datetime.replace(/-/g, '/'));
                    var now = new Date();
                    var diff = Math.floor((now - d) / 1000);
                    if (diff < 60) return '刚刚';
                    if (diff < 3600) return Math.floor(diff / 60) + '分钟前';
                    if (diff < 86400) return Math.floor(diff / 3600) + '小时前';
                    if (diff < 86400 * 30) return Math.floor(diff / 86400) + '天前';
                    return datetime.substring(0, 10);
                }

                // 初始加载
                loadComments(1, 'newest');
            })();
            </script>
            <?php else: ?>
            <div class="card empty-state">
                <h3>文章不存在</h3>
                <p>该文章可能已被删除</p>
                <a href="<?= url_blog_list() ?>" data-pjax class="btn btn-primary">浏览其他文章</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- 侧边栏 -->
        <?php include __DIR__ . '/blog_side.php'; ?>

    </div>
</div>
