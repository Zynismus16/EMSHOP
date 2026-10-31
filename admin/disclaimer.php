<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * 免责协议查看页。
 *
 * 签署后正文不会随弹层一起渲染，这里提供常驻入口：正文 + 当前签署记录。
 */
adminRequireLogin();

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/disclaimer.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/disclaimer.php';
    require __DIR__ . '/index.php';
}
