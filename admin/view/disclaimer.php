<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/**
 * 免责协议查看页视图。
 *
 * @var array<string, mixed>|null $__record
 */
$esc = function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$__record = DisclaimerService::record();
$__accepted = DisclaimerService::accepted();
$__version = (string) DisclaimerService::VERSION;
$__recordVersion = $__record !== null ? (string) ($__record['version'] ?? '') : '';
?>
<div class="em-license-page">
    <div class="em-license-card">
        <div class="em-license-card__head">
            <h2>免责声明与使用协议</h2>
            <p>
                当前版本 V<?= $esc($__version); ?> ·
                <?php if ($__accepted): ?>
                    <span class="em-license-badge is-signed">已签署</span>
                <?php else: ?>
                    <span class="em-license-badge is-unsigned">未签署</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="em-license-card__body">
            <?php include __DIR__ . '/disclaimer_content.php'; ?>
        </div>
        <div class="em-license-card__record">
            <h4>签署记录</h4>
            <?php if ($__record !== null): ?>
            <dl class="em-license-record">
                <div>
                    <dt>协议版本</dt>
                    <dd>
                        V<?= $esc($__recordVersion); ?>
                        <?php if ($__recordVersion !== $__version): ?>
                            （当前版本 V<?= $esc($__version); ?>，需重新签署）
                        <?php endif; ?>
                    </dd>
                </div>
                <div>
                    <dt>签署时间</dt>
                    <dd><?= $esc((string) ($__record['time'] ?? '')); ?></dd>
                </div>
                <div>
                    <dt>签署账号</dt>
                    <dd><?= $esc((string) ($__record['account'] ?? '')); ?></dd>
                </div>
                <div>
                    <dt>签署 IP</dt>
                    <dd><?= $esc((string) ($__record['ip'] ?? '')); ?></dd>
                </div>
            </dl>
            <?php else: ?>
            <p class="em-license-empty">尚未签署。进入后台时会弹出签署窗口，勾选同意后才会记录。</p>
            <?php endif; ?>
        </div>
    </div>
</div>
