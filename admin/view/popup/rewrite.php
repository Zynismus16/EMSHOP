<?php

declare(strict_types=1);

if (!defined('EM_ROOT')) {
    exit('Access Denied');
}

$pageTitle = '伪静态配置说明';

/*
 * 按当前 Web 服务器预选对应的规则。
 *
 * 此前这里只有一份 Nginx 规则，可代码本身（Dispatcher::tryPrettyRoute 走 REQUEST_URI）
 * 对 Apache 一样有效，README 也把 Apache 列为支持项 —— 等于 Apache / IIS 用户
 * 拿到一份照做不了的说明。SERVER_SOFTWARE 由 Web 服务器自己注入，足够可靠；
 * 万一识别不出来也只是预选错标签页，用户可以手点，不影响功能。
 */
$serverSoftware = strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''));
if (strpos($serverSoftware, 'iis') !== false) {
    $detected = 'iis';
} elseif (strpos($serverSoftware, 'apache') !== false || strpos($serverSoftware, 'litespeed') !== false) {
    // LiteSpeed / OpenLiteSpeed 直接读 .htaccess，规则与 Apache 通用，归到同一档
    $detected = 'apache';
} else {
    $detected = 'nginx';
}
$serverLabel = (string) ($_SERVER['SERVER_SOFTWARE'] ?? '');

/*
 * 三套规则。代码片段统一走 htmlspecialchars 输出 —— IIS 那份是 XML，尖括号太多，
 * 直接手写 &lt; 极易漏转义。
 *
 * 相反的，shipped / prereq / note / text 四个字段是**原样输出**的 HTML（文案里要用
 * <code>、<strong> 做强调，转义了就没法排版）。它们全部是本文件里的静态字符串，
 * 不含任何用户输入 —— 但后续要往里拼变量的话，必须先转义再拼。
 *
 * .htaccess 与 web.config 已随程序放在站点根目录（Apache / IIS 开箱即用），
 * 所以这里讲的是「为什么不生效 / 怎么排查」，而不是「去新建这个文件」。
 */
$guides = [
    'nginx' => [
        'name'  => 'Nginx',
        'shipped' => 'Nginx <strong>不读</strong>站点根目录的 <code>.htaccess</code> 和 <code>web.config</code>，只能在站点配置或面板里配 —— 就是下面这两步。',
        'steps' => [
            [
                'title' => '1. 在站点配置中添加以下规则',
                'code'  => 'location ~* ^/content/(uploads|uploadfile|cache|tmp)/.*\.php(/|$) {
    return 404;
}
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}',
            ],
            [
                'title' => '2. 宝塔面板操作入口',
                'text'  => '宝塔面板 → 网站 → 设置 → 伪静态 → 填写伪静态 → 保存',
            ],
        ],
        'note'  => '配置完成后，建议重启 Nginx 或重新加载站点配置。',
    ],
    'apache' => [
        'name'     => 'Apache',
        'shipped'  => '<code>.htaccess</code> <strong>已随程序放在站点根目录</strong>，正常情况下无需你做任何事。下面第 3 步是它不生效时的排查顺序。',
        'prereq'   => [
            'mod_rewrite 模块必须已启用（宝塔默认启用）。',
            '站点目录必须允许覆盖配置：<code>AllowOverride All</code>。没开的话 .htaccess 会被<strong>静默忽略</strong>，表现是「文件明明在、规则也没错，就是不生效」，且不报任何错 —— 这是 Apache 下最常见的原因。',
            '.htaccess 每次请求都会重新读取，改完无需重启 Apache。',
        ],
        'steps'    => [
            [
                'title' => '1. 文件已被误删时，按此内容补回',
                'text'  => '站点装在子目录（如 /shop/）时，取消 RewriteBase 那行注释并改成实际目录：',
                'code'  => '<IfModule mod_rewrite.c>
    RewriteEngine On

    # RewriteBase /shop/

    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>',
            ],
            [
                'title' => '2. 或写进虚拟主机配置（二选一，需重启 Apache）',
                'text'  => '同时要放开目录覆盖权限，否则 .htaccess 同样会被忽略：',
                'code'  => '<Directory "/www/wwwroot/你的站点目录">
    AllowOverride All
    Options -Indexes +FollowSymLinks
</Directory>',
            ],
            [
                'title' => '3. 仍不生效时，按顺序排查',
                'text'  => '① 站点根目录的 .htaccess 是否还在（升级不会覆盖你自己改过的版本）；'
                         . '② mod_rewrite 是否启用；'
                         . '③ AllowOverride 是否为 All；'
                         . '④ 站点在子目录时是否配了 RewriteBase。'
                         . '宝塔面板中也可直接编辑：网站 → 设置 → 伪静态。',
            ],
        ],
        'note'  => '命中真实存在的文件或目录时不会被重写，所以图片 / CSS / JS 不受影响。',
    ],
    'iis' => [
        'name'     => 'IIS',
        'shipped'  => '<code>web.config</code> <strong>已随程序放在站点根目录</strong>，无需手动创建 —— 但有一个必须先满足的前提，见下方「前置条件」。',
        'prereq'   => [
            '<strong>必须先安装 URL Rewrite 模块</strong>（IIS 不自带）：微软官网搜索 “URL Rewrite” 下载安装，或用 Web 平台安装器安装。',
            '没装该模块时，web.config 里的 rewrite 节点会被 IIS 当作无法识别的配置，'
                . '<strong>站点所有请求返回 HTTP 500.19，连这个后台都打不开</strong> —— 注意它不会「不生效」，是整站不可用。'
                . '若站点突然打不开且报 500.19，先装模块即可恢复。',
            'PHP 需已按 FastCGI 方式接入 IIS。装好模块后 web.config 立即生效，无需重启 IIS。',
        ],
        'steps'    => [
            [
                'title' => '1. 文件已被误删时，按此内容补回',
                'code'  => '<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <rewrite>
      <rules>
        <rule name="EMSHOP Rewrite" stopProcessing="true">
          <match url="^(.*)$" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
            <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
          </conditions>
          <action type="Rewrite" url="index.php" appendQueryString="true" />
        </rule>
      </rules>
    </rewrite>
  </system.webServer>
</configuration>',
            ],
        ],
        'note'  => 'appendQueryString 必须为 true，否则 <code>?c=goods&amp;a=list</code> 这类参数会被丢掉。',
    ],
];

include __DIR__ . '/header.php';
?>

<div class="popup-inner">
    <div class="form-tips form-tips--warn">
        <strong>为什么需要配置伪静态</strong>
        <p>伪静态主要用于支付后的回调与路由处理。未配置时，客户支付成功后，订单可能显示为“未支付”，或者跳转到 404 页面。</p>
    </div>

    <div class="rewrite-guide">
        <div class="rewrite-guide__title">按 Web 服务器选择配置方式</div>

        <?php if ($serverLabel !== ''): ?>
        <div class="rewrite-guide__detect">
            <i class="fa fa-info-circle"></i>
            <span>检测到当前服务器：<code><?= htmlspecialchars($serverLabel, ENT_QUOTES, 'UTF-8') ?></code></span>
        </div>
        <?php endif; ?>

        <div class="rewrite-tabs" role="tablist">
            <?php foreach ($guides as $key => $g): ?>
            <button type="button"
                    class="rewrite-tabs__item<?= $key === $detected ? ' is-active' : '' ?>"
                    data-rewrite-tab="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($key === $detected): ?><span class="rewrite-tabs__badge">当前</span><?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <?php foreach ($guides as $key => $g): ?>
        <div class="rewrite-panel<?= $key === $detected ? ' is-active' : '' ?>" data-rewrite-panel="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">

            <?php if (!empty($g['shipped'])): ?>
            <div class="rewrite-shipped">
                <i class="fa fa-check-circle"></i>
                <span><?= $g['shipped'] ?></span>
            </div>
            <?php endif; ?>

            <?php if (!empty($g['prereq'])): ?>
            <div class="rewrite-prereq">
                <div class="rewrite-prereq__title"><i class="fa fa-exclamation-triangle"></i> 前置条件</div>
                <ul>
                    <?php foreach ($g['prereq'] as $line): ?>
                    <li><?= $line ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php foreach ($g['steps'] as $step): ?>
            <div class="rewrite-guide__step">
                <div class="rewrite-guide__step-title"><?= htmlspecialchars($step['title'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php if (!empty($step['text'])): ?>
                <p><?= $step['text'] ?></p>
                <?php endif; ?>
                <?php if (!empty($step['code'])): ?>
                <div class="rewrite-code">
                    <button type="button" class="rewrite-code__copy" title="复制">
                        <i class="fa fa-copy"></i> 复制
                    </button>
                    <pre><code><?= htmlspecialchars($step['code'], ENT_QUOTES, 'UTF-8') ?></code></pre>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <?php if (!empty($g['note'])): ?>
            <div class="rewrite-guide__note">
                <i class="fa fa-info-circle"></i>
                <span><?= $g['note'] ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="popup-footer popup-footer--single">
    <button type="button" class="popup-btn popup-btn--primary" id="rewritePopupCloseBtn"><i class="fa fa-check mr-5"></i>我知道了</button>
</div>

<style>
body.popup-body { background: #fff; }

.form-tips--warn {
    background: #fff7ed;
    border-color: #fed7aa;
    color: #78350f;
}
.form-tips--warn strong {
    color: #9a3412;
    display: block;
    margin-bottom: 6px;
}
.form-tips--warn p {
    margin: 0;
    line-height: 1.6;
}

.rewrite-guide {
    margin-top: 16px;
    padding: 16px;
    background: #f8fafc;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
}
.rewrite-guide__title {
    font-size: 14px;
    font-weight: 600;
    color: #111827;
    margin-bottom: 12px;
}
.rewrite-guide__detect {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    margin-bottom: 12px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    font-size: 12px;
    color: #6b7280;
}
.rewrite-guide__detect i { color: #6366f1; }
.rewrite-guide__detect code {
    font-family: Menlo, Consolas, Monaco, monospace;
    color: #111827;
    background: #f3f4f6;
    padding: 1px 5px;
    border-radius: 3px;
    word-break: break-all;
}

/* ---------- 服务器切换标签 ---------- */
.rewrite-tabs {
    display: flex;
    gap: 6px;
    margin-bottom: 14px;
    border-bottom: 1px solid #e5e7eb;
}
.rewrite-tabs__item {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 14px;
    margin-bottom: -1px;
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: 6px 6px 0 0;
    background: transparent;
    font-size: 13px;
    color: #6b7280;
    cursor: pointer;
    transition: color .15s ease, background .15s ease;
}
.rewrite-tabs__item:hover { color: #374151; background: #f3f4f6; }
.rewrite-tabs__item.is-active {
    background: #fff;
    border-color: #e5e7eb;
    color: #4f46e5;
    font-weight: 600;
}
.rewrite-tabs__badge {
    font-size: 10px;
    font-weight: 500;
    padding: 1px 6px;
    border-radius: 999px;
    background: #eef2ff;
    color: #4f46e5;
}

/* 未选中的面板不占位，避免弹窗一打开就要滚 */
.rewrite-panel { display: none; }
.rewrite-panel.is-active { display: block; }

/* ---------- 已随程序提供 ---------- */
.rewrite-shipped {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    padding: 10px 12px;
    margin-bottom: 14px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 6px;
    color: #15803d;
    font-size: 12.5px;
    line-height: 1.65;
}
.rewrite-shipped i {
    font-size: 14px;
    margin-top: 1px;
}
.rewrite-shipped code {
    font-family: Menlo, Consolas, Monaco, monospace;
    background: #fff;
    border: 1px solid #bbf7d0;
    padding: 1px 5px;
    border-radius: 3px;
}

/* ---------- 前置条件 ---------- */
.rewrite-prereq {
    padding: 10px 12px;
    margin-bottom: 14px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 6px;
}
.rewrite-prereq__title {
    font-size: 13px;
    font-weight: 600;
    color: #b45309;
    margin-bottom: 6px;
}
.rewrite-prereq ul {
    margin: 0;
    padding-left: 18px;
    font-size: 12.5px;
    line-height: 1.75;
    color: #78350f;
}
.rewrite-prereq code {
    font-family: Menlo, Consolas, Monaco, monospace;
    background: #fff;
    border: 1px solid #fde68a;
    padding: 1px 5px;
    border-radius: 3px;
}

/* ---------- 步骤 ---------- */
.rewrite-guide__step + .rewrite-guide__step {
    margin-top: 12px;
}
.rewrite-guide__step-title {
    font-size: 13px;
    font-weight: 600;
    color: #374151;
    margin-bottom: 8px;
}
.rewrite-guide__step p {
    margin: 0 0 8px;
    color: #4b5563;
    font-size: 12.5px;
    line-height: 1.6;
}

/* ---------- 代码块 + 复制 ---------- */
.rewrite-code { position: relative; }
.rewrite-code pre {
    margin: 0;
    padding: 12px;
    padding-right: 72px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    overflow-x: auto;
}
.rewrite-code code {
    font-family: Menlo, Consolas, Monaco, monospace;
    font-size: 12.5px;
    color: #111827;
    white-space: pre;
}
.rewrite-code__copy {
    position: absolute;
    top: 8px;
    right: 8px;
    padding: 3px 9px;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    background: #fff;
    color: #6b7280;
    font-size: 11.5px;
    cursor: pointer;
    transition: color .15s ease, border-color .15s ease;
}
.rewrite-code__copy:hover { color: #4f46e5; border-color: #c7d2fe; }
.rewrite-code__copy.is-done { color: #059669; border-color: #bbf7d0; }

.rewrite-guide__note {
    margin-top: 12px;
    padding: 10px 12px;
    background: #eef2ff;
    border: 1px solid #c7d2fe;
    border-radius: 6px;
    color: #4338ca;
    font-size: 12.5px;
    line-height: 1.6;
    display: flex;
    align-items: flex-start;
    gap: 8px;
}
.rewrite-guide__note i {
    font-size: 14px;
    margin-top: 1px;
}
.rewrite-guide__note code {
    font-family: Menlo, Consolas, Monaco, monospace;
    background: #fff;
    border: 1px solid #c7d2fe;
    padding: 1px 5px;
    border-radius: 3px;
}
</style>

<script>
$(function () {
    $('#rewritePopupCloseBtn').on('click', function () {
        var idx = parent.layer.getFrameIndex(window.name);
        parent.layer.close(idx);
    });

    // 服务器切换
    $('.rewrite-tabs__item').on('click', function () {
        var tab = $(this).data('rewrite-tab');
        $('.rewrite-tabs__item').removeClass('is-active');
        $(this).addClass('is-active');
        $('.rewrite-panel').removeClass('is-active');
        $('.rewrite-panel[data-rewrite-panel="' + tab + '"]').addClass('is-active');
    });

    // 复制规则：用隐藏 textarea + execCommand，和后台首页复制客服 QQ 同一套做法。
    // 不依赖 navigator.clipboard —— 后者要求 HTTPS 安全上下文，而没配伪静态的站
    // 很可能是 http 直连 IP 访问，那时 clipboard 直接不可用。
    $('.rewrite-code__copy').on('click', function () {
        var $btn = $(this);
        var code = $btn.siblings('pre').find('code').text();
        var $tmp = $('<textarea>').val(code).css({ position: 'fixed', top: '-1000px' }).appendTo('body');
        $tmp[0].select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        $tmp.remove();
        if (ok) {
            $btn.addClass('is-done').html('<i class="fa fa-check"></i> 已复制');
            setTimeout(function () {
                $btn.removeClass('is-done').html('<i class="fa fa-copy"></i> 复制');
            }, 1600);
        } else {
            // 复制失败时给个明确出口，别让用户以为按钮坏了
            if (parent.layer) parent.layer.msg('复制失败，请手动选中代码复制');
        }
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
