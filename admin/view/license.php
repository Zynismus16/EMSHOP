<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var array<string, mixed>|null $current */
/** @var string $csrfToken */
/** @var array<int, array{url:string, name:string}> $lines */
/** @var int $currentLineIndex */

$esc = function (string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
};

$activated = $current !== null;

// 「官方网站」入口 = 当前首选线路地址（多线路，见 LicenseService::getAllLines）
$officialSiteUrl = (string) ($lines[$currentLineIndex]['url'] ?? '');
?>
<div class="lic-page lic-page--centered">

    <!-- 激活卡（未激活：主角；已激活：降级为"更换激活码"） -->
    <section class="lic-activate <?= $activated ? 'is-secondary' : 'is-primary' ?>">
        <?php if (!$activated): ?>
        <!-- 未激活：激活表单 -->
        <div class="lic-activate__shield">
            <i class="fa fa-shield"></i>
        </div>

        <h2 class="lic-activate__title">激活正版 EMSHOP</h2>
        <p class="lic-activate__subtitle">请求加载失败时系统会自动尝试其他线路，请稍后重试</p>

        <div class="lic-activate__form">
            <label for="licenseCode" class="lic-input-label">授权许可证 (License Key)</label>
            <div class="lic-input-inline">
                <input type="text" id="licenseCode"
                       class="lic-input-inline__input"
                       placeholder="请输入您的激活码"
                       maxlength="64" autocomplete="off" spellcheck="false">
                <button type="button" class="lic-input-inline__btn" id="btnActivate">
                    立即激活 <i class="fa fa-arrow-right"></i>
                </button>
            </div>

            <div class="lic-activate__hint">
                还没有激活码？
                <a href="javascript:void(0);" class="lic-activate__link" id="btnGetLicense">
                    获取正版授权 <i class="fa fa-arrow-right"></i>
                </a>
            </div>

            <!-- 未激活时也要能找到客服：购买咨询、激活失败、域名被占用等都会用到 -->
            <div class="lic-activate__actions">
                <button type="button" class="lic-btn-ghost-primary js-lic-contact">
                    <i class="fa fa-headphones"></i> 联系客服
                </button>
                <?php if ($officialSiteUrl !== ''): ?>
                <a href="<?= $esc($officialSiteUrl) ?>" target="_blank" rel="noopener noreferrer" class="lic-btn-ghost-primary">
                    <i class="fa fa-globe"></i> 官方网站
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php else: ?>
        <!-- 已激活：展示型卡片（不可更换码；需更换请到官方服务中心解绑） -->
        <?php
        $levelVisual = [
            'vip'     => ['color' => '#2563eb', 'soft' => '#dbeafe', 'icon' => 'fa-certificate', 'label' => 'VIP'],
            'svip'    => ['color' => '#7e22ce', 'soft' => '#f3e8ff', 'icon' => 'fa-star',        'label' => 'SVIP'],
            'supreme' => ['color' => '#d97706', 'soft' => '#fef3c7', 'icon' => 'fa-trophy',      'label' => '至尊'],
        ];
        $lv = $levelVisual[(string) $current['level']] ?? $levelVisual['vip'];
        $maskedCode = strlen((string) $current['license_code']) > 12
            ? substr((string) $current['license_code'], 0, 8) . '…' . substr((string) $current['license_code'], -4)
            : (string) $current['license_code'];
        ?>
        <div class="lic-licensed">
            <div class="lic-licensed__head">
                <div class="lic-licensed__badge" style="background: <?= $lv['soft'] ?>; color: <?= $lv['color'] ?>;">
                    <i class="fa <?= $lv['icon'] ?>"></i>
                </div>
                <h2 class="lic-licensed__title">正版已激活</h2>
                <p class="lic-licensed__sub">
                    当前等级 <strong style="color: <?= $lv['color'] ?>;"><?= $esc($lv['label']) ?></strong>
                </p>
            </div>

            <div class="lic-info">
                <div class="lic-info__row">
                    <span class="lic-info__label">授权等级</span>
                    <span class="lic-info__value"><?= $esc($lv['label']) ?></span>
                </div>
                <div class="lic-info__row">
                    <span class="lic-info__label">激活码</span>
                    <span class="lic-info__value mono"><?= $esc($maskedCode) ?></span>
                </div>
                <div class="lic-info__row">
                    <span class="lic-info__label">主授权域名</span>
                    <span class="lic-info__value mono"><?= $esc((string) $current['bound_domain']) ?></span>
                </div>
                <div class="lic-info__row">
                    <span class="lic-info__label">授权域名</span>
                    <span class="lic-info__value mono" id="licAliasesView">
                        <?php
                        $aliases = $current['alias_hosts'] ?? [];
                        echo $aliases === [] ? '<span style="color:#9ca3af;">（暂无）</span>' : $esc(implode('，', $aliases));
                        ?>
                    </span>
                    <a href="javascript:void(0);" id="btnEditAliases" class="lic-info__action">
                        <i class="fa fa-edit"></i> 添加授权域名
                    </a>
                </div>
            </div>

            <div class="lic-notice">
                <i class="fa fa-info-circle"></i>
                <div>
                    <div class="lic-notice__title">如需更换激活码或迁移站点</div>
                    <div class="lic-notice__desc">点击下方"解绑当前主授权域名"释放此域名的激活码后，即可在此页面用新激活码重新激活</div>
                </div>
            </div>

            <div class="lic-licensed__actions">
                <button type="button" class="lic-btn-danger-outline" id="btnUnbind">
                    <i class="fa fa-unlink"></i> 解绑当前主授权域名
                </button>
                <button type="button" class="lic-btn-ghost-primary js-lic-contact" id="btnContactSupport">
                    <i class="fa fa-headphones"></i> 联系客服
                </button>
                <?php if ($officialSiteUrl !== ''): ?>
                <a href="<?= $esc($officialSiteUrl) ?>" target="_blank" rel="noopener noreferrer" class="lic-btn-ghost-primary">
                    <i class="fa fa-globe"></i> 官方网站
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </section>

</div>

<style>
.lic-page {
    --radius: 16px;
    --shadow-sm: 0 1px 3px rgba(15, 23, 42, 0.04), 0 1px 2px rgba(15, 23, 42, 0.06);
    --shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
    --shadow-lg: 0 20px 50px rgba(15, 23, 42, 0.12);
    --border: #e4e7eb;
    --muted: #6b7280;
    --text: #111827;
    --primary: #6366f1;
    --primary-dark: #4f46e5;
    padding: 8px 4px 40px;
    position: relative;
}

/* ================================ Hero（已激活） ================================ */
.lic-hero {
    position: relative;
    border-radius: var(--radius);
    overflow: hidden;
    margin-bottom: 24px;
    color: #fff;
    box-shadow: var(--shadow);
}
.lic-hero__glow {
    position: absolute; inset: 0;
    background:
        radial-gradient(600px 300px at 10% 0%, rgba(255,255,255,0.25), transparent 60%),
        radial-gradient(500px 300px at 110% 100%, rgba(255,255,255,0.15), transparent 60%);
    pointer-events: none;
}
.lic-hero__inner {
    position: relative;
    display: flex; align-items: center; justify-content: space-between; gap: 24px;
    padding: 36px 36px; flex-wrap: wrap;
}
.lic-hero__brand {
    font-size: 12px; font-weight: 600; letter-spacing: 1.5px; text-transform: uppercase;
    opacity: 0.75; margin-bottom: 16px;
}
.lic-hero__brand i { margin-right: 6px; }

.lic-hero__level {
    font-size: 40px; font-weight: 700; letter-spacing: 0.5px; line-height: 1;
    display: flex; align-items: center; gap: 16px; margin-bottom: 20px;
}
.lic-hero__level i { font-size: 32px; opacity: 0.95; }
.lic-hero__badge {
    font-size: 11px; font-weight: 600; letter-spacing: 1px;
    padding: 5px 12px; border-radius: 14px;
    background: rgba(255,255,255,0.22);
    text-transform: uppercase;
}

.lic-hero__meta {
    display: flex; gap: 36px; flex-wrap: wrap;
    font-size: 13px; line-height: 1.6;
}
.lic-hero__meta > div { display: flex; flex-direction: column; gap: 4px; }
.lic-hero__meta-label {
    font-size: 11px; opacity: 0.7; letter-spacing: 0.8px; text-transform: uppercase;
}
.lic-hero__meta code {
    background: rgba(255,255,255,0.18);
    padding: 3px 10px; border-radius: 5px;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    font-size: 12px;
    align-self: flex-start;
}

.lic-hero__right {
    display: flex; gap: 10px; flex-wrap: wrap;
}
.lic-hero-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 10px 18px; border-radius: 10px;
    background: rgba(255,255,255,0.18);
    color: #fff; border: 1px solid rgba(255,255,255,0.25);
    font-size: 13px; font-weight: 500; cursor: pointer;
    transition: all 0.2s ease;
    backdrop-filter: blur(8px);
}
.lic-hero-btn:hover { background: rgba(255,255,255,0.28); border-color: rgba(255,255,255,0.42); transform: translateY(-1px); }
.lic-hero-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; }
.lic-hero-btn--danger:hover { background: rgba(239,68,68,0.55); border-color: rgba(239,68,68,0.75); }

/* ================================ 激活卡 ================================ */
.lic-activate {
    width: 100%;
    max-width: 660px;
    margin: 0 auto 24px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 40px 36px 32px;
    box-shadow: var(--shadow);
    position: relative;
    box-sizing: border-box;
}

/* 整页垂直居中（未激活/已激活都适用） */
.lic-page--centered {
    min-height: calc(100vh - 140px);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}
.lic-page--centered .lic-activate.is-primary,
.lic-page--centered .lic-activate.is-secondary { margin-top: 0; }
.lic-page--centered .lic-history { width: 100%; }
.lic-activate.is-primary {
    margin-top: 24px;
    padding: 56px 40px 40px;
    box-shadow: var(--shadow-lg);
}
.lic-activate.is-secondary {
    padding: 24px 28px;
}

.lic-activate__shield {
    width: 72px; height: 72px;
    margin: 0 auto 24px;
    border-radius: 22px;
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    display: flex; align-items: center; justify-content: center;
    font-size: 30px; color: var(--primary);
}

.lic-activate__title {
    font-size: 26px; font-weight: 700; color: var(--text);
    text-align: center; margin: 0 0 10px;
    letter-spacing: 0.3px;
}
.lic-activate__title--sm {
    font-size: 15px; margin-bottom: 16px; text-align: left;
    color: var(--muted); font-weight: 600;
}
.lic-activate__subtitle {
    font-size: 13px; color: var(--muted);
    text-align: center; margin: 0 0 30px;
    line-height: 1.6;
}

/* 输入框 label */
.lic-input-label {
    display: block;
    font-size: 14px;
    font-weight: 400;
    color: var(--text);
    margin-bottom: 10px;
    letter-spacing: 0.3px;
}

/* 输入框内嵌按钮 */
.lic-input-inline {
    position: relative;
    margin-bottom: 20px;
}
.lic-input-inline__input {
    width: 100%;
    height: 52px;
    padding: 0 150px 0 18px;
    border: 1.5px solid var(--border);
    border-radius: 12px;
    font-size: 14px;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    background: #fafbfc;
    outline: none;
    transition: all 0.2s ease;
    box-sizing: border-box;
    letter-spacing: 0.5px;
}
.lic-input-inline__input:focus {
    border-color: var(--primary);
    background: #fff;
    box-shadow: 0 0 0 4px rgba(99,102,241,0.12);
}
.lic-input-inline__input::placeholder {
    color: #787e88;
    letter-spacing: 0.5px;
}

.lic-input-inline__btn {
    position: absolute;
    right: 6px; top: 6px; bottom: 6px;
    padding: 0 22px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
    box-shadow: 0 2px 6px rgba(79,70,229,0.3);
    letter-spacing: 0.3px;
}
.lic-input-inline__btn:hover {
    box-shadow: 0 4px 12px rgba(79,70,229,0.4);
    filter: brightness(1.08);
}
.lic-input-inline__btn:active { filter: brightness(0.95); }
.lic-input-inline__btn:disabled { opacity: 0.65; cursor: not-allowed; filter: none; box-shadow: none; }
.lic-input-inline__btn i { font-size: 11px; transition: transform 0.2s; }
.lic-input-inline__btn:hover i { transform: translateX(2px); }

.lic-activate.is-secondary .lic-input-inline__input { height: 44px; padding-right: 134px; font-size: 13px; }
.lic-activate.is-secondary .lic-input-inline__btn { padding: 0 18px; font-size: 12px; }

/* "还没有激活码？获取正版授权 →" */
.lic-activate__hint {
    text-align: center;
    color: var(--muted);
    padding-top: 4px;
}
.lic-activate__link {
    color: var(--primary);
    text-decoration: none;
    font-weight: 500;
    margin-left: 4px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: color 0.2s ease;
}
.lic-activate__link:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}
.lic-activate__link i {
    font-size: 10px;
    transition: transform 0.2s ease;
}
.lic-activate__link:hover i {
    transform: translateX(3px);
}
/* 未激活态的操作区（联系客服）；按钮复用已激活卡片的 lic-btn-ghost-primary */
.lic-activate__actions {
    display: flex; justify-content: center;
    margin-top: 18px;
}

/* ================================ 历史 ================================ */
.lic-history {
    max-width: 900px;
    margin: 0 auto;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
}
.lic-history > summary {
    padding: 16px 24px;
    cursor: pointer;
    font-size: 14px; font-weight: 600; color: var(--text);
    display: flex; align-items: center; justify-content: space-between;
    list-style: none;
    user-select: none;
    transition: background 0.15s;
}
.lic-history > summary:hover { background: #f8fafc; }
.lic-history > summary::-webkit-details-marker { display: none; }
.lic-history > summary i { margin-right: 8px; color: var(--primary); }
.lic-history__count {
    background: #f1f5f9; color: #64748b;
    padding: 2px 10px; border-radius: 10px;
    font-size: 12px; font-weight: 600;
}
.lic-history[open] > summary { border-bottom: 1px solid var(--border); }

.lic-history__body { padding: 0; overflow-x: auto; }
.lic-history__table { width: 100%; border-collapse: collapse; }
.lic-history__table th, .lic-history__table td {
    padding: 12px 24px;
    text-align: left;
    font-size: 13px;
    border-bottom: 1px solid #f1f5f9;
}
.lic-history__table th {
    background: #f8fafc; color: var(--muted);
    font-weight: 500; font-size: 11px;
    text-transform: uppercase; letter-spacing: 0.6px;
}
.lic-history__table tbody tr:last-child td { border-bottom: none; }
.lic-history__table tbody tr.is-revoked { opacity: 0.55; }
.lic-history__table tbody tr:hover:not(.is-revoked) { background: #fafbfc; }

.lic-history__table .mono    { font-family: 'JetBrains Mono', Consolas, Monaco, monospace; font-size: 12px; }
.lic-history__table .mono-sm { font-family: 'JetBrains Mono', Consolas, Monaco, monospace; font-size: 11px; color: var(--muted); }

.lic-tag {
    display: inline-block;
    padding: 2px 10px; border-radius: 10px;
    font-size: 11px; font-weight: 600; letter-spacing: 0.5px;
}
.lic-tag--ok    { background: #dcfce7; color: #15803d; }
.lic-tag--muted { background: #f1f5f9; color: #94a3b8; }

/* ================================ 线路切换（卡片右上角） ================================ */
.lic-line {
    position: absolute;
    top: 14px; right: 14px;
    z-index: 5;
}
.lic-line__trigger {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 9px 16px;
    border: 1px solid #c7d2fe;
    border-radius: 5px;
    background: #eef2ff;
    font-size: 13px; font-weight: 500;
    color: #4f46e5;
    cursor: pointer;
    transition: all 0.18s ease;
    box-shadow: 0 2px 8px rgba(99, 102, 241, 0.12);
}
.lic-line__trigger:hover {
    background: #e0e7ff;
    border-color: #a5b4fc;
    box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2);
    transform: translateY(-1px);
}
.lic-line__trigger i.fa-signal {
    font-size: 12px;
    color: #10b981;
    animation: licPulse 2s ease-in-out infinite;
}
.lic-line__trigger .lic-line__name { color: #4f46e5; }
.lic-line__trigger i.fa-caret-down { font-size: 11px; opacity: 0.75; }

@keyframes licPulse {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%      { opacity: 0.6; transform: scale(0.92); }
}

.lic-line__menu {
    position: absolute;
    top: calc(100% + 6px); right: 0;
    min-width: 280px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12);
    padding: 8px;
    animation: licLineMenuIn 0.15s ease;
}
@keyframes licLineMenuIn {
    from { opacity: 0; transform: translateY(-4px); }
    to   { opacity: 1; transform: translateY(0); }
}
.lic-line__title {
    font-size: 11px; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.6px;
    padding: 8px 12px 6px;
}
.lic-line__item {
    display: flex; align-items: center; gap: 10px;
    width: 100%; padding: 10px 12px;
    background: transparent; border: none; border-radius: 8px;
    cursor: pointer; text-align: left;
    transition: background 0.15s ease;
}
.lic-line__item:hover { background: #f8fafc; }
.lic-line__item.is-current { background: #f5f3ff; }
.lic-line__item > i.fa-circle-o { font-size: 10px; color: #cbd5e1; flex-shrink: 0; }
.lic-line__item.is-current > i.fa-circle-o { color: var(--primary); }
.lic-line__item-body { flex: 1; min-width: 0; }
.lic-line__item-name { font-size: 13px; font-weight: 500; color: var(--text); }
.lic-line__item-url {
    font-size: 11px; color: var(--muted);
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    margin-top: 2px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.lic-line__item > i.fa-check { color: var(--primary); font-size: 13px; }

/* ================================ 代理商信息弹窗内容（与 layer 配合） ================================ */
.lic-agent {
    padding: 24px 28px;
    text-align: left;
}
.lic-agent__head {
    display: flex; align-items: center; gap: 12px;
    padding-bottom: 16px; border-bottom: 1px solid var(--border);
    margin-bottom: 18px;
}
.lic-agent__icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, #eef2ff, #e0e7ff);
    display: flex; align-items: center; justify-content: center;
    color: var(--primary); font-size: 20px; flex-shrink: 0;
}
.lic-agent__head-text h3 { font-size: 16px; font-weight: 600; color: var(--text); margin: 0 0 2px; }
.lic-agent__head-text p  { font-size: 12px; color: var(--muted); margin: 0; }

.lic-agent__section {
    margin-bottom: 18px;
}
.lic-agent__section:last-child { margin-bottom: 0; }
.lic-agent__section-title {
    font-size: 11px; font-weight: 600; color: var(--muted);
    text-transform: uppercase; letter-spacing: 0.6px;
    margin-bottom: 10px;
}
.lic-agent__contacts {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 10px;
}
.lic-agent__contact {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    background: #f8fafc;
    border-radius: 10px;
    font-size: 13px;
}
.lic-agent__contact i { color: var(--primary); width: 16px; text-align: center; }
.lic-agent__contact-label { color: var(--muted); margin-right: 4px; font-size: 12px; }
.lic-agent__contact-value { color: var(--text); font-weight: 500; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
.lic-agent__contact a { color: var(--primary); text-decoration: none; }
.lic-agent__contact a:hover { text-decoration: underline; }

.lic-agent__list { display: flex; flex-direction: column; gap: 8px; }
.lic-agent__list a {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 10px;
    text-decoration: none; color: var(--text); font-size: 13px;
    transition: all 0.15s ease;
}
.lic-agent__list a:hover {
    border-color: var(--primary);
    background: #faf8ff;
    color: var(--primary);
    transform: translateY(-1px);
}
.lic-agent__list a i.fa-external-link { color: var(--muted); font-size: 11px; margin-left: auto; }
.lic-agent__list a:hover i.fa-external-link { color: var(--primary); }
.lic-agent__list__name { font-weight: 500; }
.lic-agent__list__url {
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    font-size: 11px; color: var(--muted);
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    max-width: 260px;
}
.lic-agent__empty {
    padding: 20px; text-align: center;
    color: var(--muted); font-size: 13px;
    background: #f8fafc; border-radius: 8px;
}

/* ================================ 已激活：展示卡片 ================================ */
.lic-licensed {
    text-align: center;
}
.lic-licensed__head {
    margin-bottom: 28px;
}
.lic-licensed__badge {
    width: 72px; height: 72px;
    margin: 0 auto 18px;
    border-radius: 22px;
    display: flex; align-items: center; justify-content: center;
    font-size: 32px;
}
.lic-licensed__title {
    font-size: 24px; font-weight: 700; color: var(--text);
    margin: 0 0 8px;
    letter-spacing: 0.3px;
}
.lic-licensed__sub {
    font-size: 13px; color: var(--muted);
    margin: 0;
    display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; justify-content: center;
}
/* 授权信息表 */
.lic-info {
    background: #fafbfc;
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 4px 16px;
    margin-bottom: 18px;
    text-align: left;
}
.lic-info__row {
    display: flex; align-items: center;
    padding: 12px 0;
    border-bottom: 1px dashed #e5e7eb;
    font-size: 13px;
}
.lic-info__row:last-child { border-bottom: none; }
.lic-info__label {
    width: 88px; flex-shrink: 0;
    color: var(--muted); font-size: 12px;
    letter-spacing: 0.2px;
}
.lic-info__value {
    flex: 1; min-width: 0;
    color: var(--text); font-weight: 500;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.lic-info__value.mono {
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    font-size: 12.5px;
}

/* 授权信息行尾的操作链接（如"添加授权域名"）：不随内容压缩、不换行 */
.lic-info__action {
    flex-shrink: 0;
    margin-left: 10px;
    font-size: 12px;
    color: var(--primary);
    text-decoration: none;
    white-space: nowrap;
    transition: color 0.15s ease;
}
.lic-info__action:hover {
    color: var(--primary-dark);
    text-decoration: underline;
}
.lic-info__action i { font-size: 11px; margin-right: 2px; }

/* 提示条 */
.lic-notice {
    display: flex; gap: 12px; align-items: flex-start;
    padding: 14px 16px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    margin-bottom: 20px;
    text-align: left;
}
.lic-notice > i {
    color: #2563eb; font-size: 16px; margin-top: 1px; flex-shrink: 0;
}
.lic-notice__title {
    font-size: 13px; font-weight: 600; color: #1e3a8a;
    margin-bottom: 3px;
}
.lic-notice__desc {
    font-size: 12px; color: #1e40af; line-height: 1.6;
}

/* 操作区 */
.lic-licensed__actions {
    display: flex; justify-content: center; gap: 10px;
    flex-wrap: wrap;
}
.lic-btn-ghost-primary {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px;
    border-radius: 10px;
    background: #fff;
    color: var(--primary);
    border: 1px solid #c7d2fe;
    font-size: 13px; font-weight: 500;
    cursor: pointer;
    transition: all 0.18s ease;
    text-decoration: none; /* 该样式也用于 <a>（官方网站入口） */
}
.lic-btn-ghost-primary:hover {
    background: #eef2ff;
    border-color: var(--primary);
    transform: translateY(-1px);
}
.lic-btn-ghost-primary i { font-size: 12px; }

.lic-btn-danger-outline {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px;
    border-radius: 10px;
    background: #fff;
    color: #dc2626;
    border: 1px solid #fecaca;
    font-size: 13px; font-weight: 500;
    cursor: pointer;
    transition: all 0.18s ease;
}
.lic-btn-danger-outline:hover {
    background: #fef2f2;
    border-color: #dc2626;
    transform: translateY(-1px);
}
.lic-btn-danger-outline:disabled {
    opacity: 0.6; cursor: not-allowed; transform: none;
}
.lic-btn-danger-outline i { font-size: 12px; }

/* ================================ 添加授权域名弹窗 ================================ */
.lic-alias-modal {
    padding: 18px 20px 10px;
    box-sizing: border-box;
    max-height: calc(88vh - 120px);
    overflow-y: auto;
}
.lic-alias-modal__input {
    width: 100%;
    height: 180px;
    max-height: min(180px, 36vh);
    padding: 10px 12px;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    font-family: Consolas, Monaco, monospace;
    font-size: 13px;
    resize: vertical;
    outline: none;
    box-sizing: border-box;
}
.lic-alias-modal__hint {
    margin-top: 10px;
    font-size: 12px;
    color: #6b7280;
    line-height: 1.7;
}

/* ================================ 响应式 ================================ */
@media (max-width: 768px) {
    .lic-hero__inner { padding: 26px 22px; flex-direction: column; align-items: flex-start; }
    .lic-hero__right { width: 100%; }
    .lic-hero__level { font-size: 30px; }
    .lic-hero__meta { gap: 18px; }
    .lic-activate { padding: 32px 24px; }
    .lic-activate.is-primary { padding: 40px 24px 28px; }
    .lic-activate__title { font-size: 22px; }
    .lic-input-inline__input { padding-right: 118px; }
    .lic-history__table th, .lic-history__table td { padding: 10px 16px; font-size: 12px; }
    .lic-alias-modal { padding: 14px 14px 8px; }
    .lic-alias-modal__input { height: 140px; font-size: 12px; }
}
</style>

<script>
$(function(){
    'use strict';
    var csrfToken = <?php echo json_encode($csrfToken); ?>;

    layui.use(['layer'], function () {
        var layer = layui.layer;

        function post(data) {
            data.csrf_token = csrfToken;
            return $.ajax({
                url: '/admin/license.php',
                type: 'POST',
                dataType: 'json',
                data: data
            }).then(function (res) {
                if (res && res.data && res.data.csrf_token) {
                    csrfToken = res.data.csrf_token;
                }
                return res;
            });
        }

        // 激活
        $('#btnActivate').on('click', function () {
            var code = $.trim($('#licenseCode').val());
            if (!code) {
                $('#licenseCode').focus();
                layer.msg('请输入激活码');
                return;
            }
            var $btn = $(this);
            var originalHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fa fa-refresh fa-spin"></i> 激活中');
            post({_action: 'activate', license_code: code}).done(function (res) {
                if (res.code === 200) {
                    layer.msg('激活成功：' + (res.data.level_label || ''), {time: 900});
                    setTimeout(function () { location.reload(); }, 900);
                } else {
                    layer.msg(res.msg || '激活失败');
                    $btn.prop('disabled', false).html(originalHtml);
                }
            }).fail(function () {
                layer.msg('网络异常');
                $btn.prop('disabled', false).html(originalHtml);
            });
        });
        $('#licenseCode').on('keydown', function (e) {
            if (e.key === 'Enter') $('#btnActivate').click();
        });

        // 线路切换已迁移到后台 toolbar 全局（admin/view/index.php），此页不再承载

        // ============= 页面级弹窗：获取激活码 / 联系客服 =============
        // mode 决定弹窗内容：agent=购买渠道，contact=客服联系方式（两者不共用内容）
        function openAgentPopup(title, mode) {
            layer.open({
                type: 2,
                title: title || '获取正版激活码',
                skin: 'admin-modal',
                maxmin: false,
                area: [window.innerWidth >= 640 ? '580px' : '94%', window.innerHeight >= 640 ? '580px' : '88%'],
                shadeClose: true,
                content: '/admin/license.php?_popup=' + (mode === 'contact' ? 'contact' : 'agent')
            });
        }

        $('#btnGetLicense').on('click', function (e) {
            e.preventDefault();
            openAgentPopup('获取正版激活码', 'agent');
        });

        // 联系客服：已激活卡片和未激活卡片各有一个入口，用同一个类一起兜住
        $(document).on('click', '.js-lic-contact', function () {
            openAgentPopup('联系客服', 'contact');
        });

        // 已激活卡片：解绑当前主授权域名
        $('#btnUnbind').on('click', function () {
            var $btn = $(this);
            layer.confirm(
                '确定解绑当前主授权域名吗？<br>解绑后此域名将恢复未授权状态，可使用同一激活码绑定到其他域名。',
                { btn: ['确认解绑', '取消'], icon: 3 },
                function (idx) {
                    layer.close(idx);
                    var origHtml = $btn.html();
                    $btn.prop('disabled', true).html('<i class="fa fa-refresh fa-spin"></i> 解绑中');
                    post({_action: 'unbind'}).done(function (res) {
                        if (res.code === 200) {
                            layer.msg('解绑成功', {time: 800, icon: 1});
                            setTimeout(function () { location.reload(); }, 800);
                        } else {
                            layer.msg(res.msg || '解绑失败', {icon: 2});
                            $btn.prop('disabled', false).html(origHtml);
                        }
                    }).fail(function () {
                        layer.msg('网络异常', {icon: 2});
                        $btn.prop('disabled', false).html(origHtml);
                    });
                }
            );
        });

        // 已激活卡片：添加授权域名（编辑别名列表）
        $('#btnEditAliases').on('click', function () {
            // textarea 预填当前别名，一行一个
            var cur = <?= json_encode($current['alias_hosts'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            var textareaValue = (cur || []).join('\n');
            var aliasW = window.innerWidth >= 640 ? '480px' : '94%';
            var aliasH = window.innerHeight >= 560 ? 'auto' : '88%';
            layer.open({
                type: 1,
                title: '添加授权域名',
                skin: 'admin-modal',
                area: [aliasW, aliasH],
                shadeClose: true,
                content:
                    '<div class="lic-alias-modal">'
                  +   '<textarea id="licAliasesInput" class="lic-alias-modal__input" placeholder="一行一个域名，例如：&#10;www.example.com&#10;shop.example.net">' + $('<div/>').text(textareaValue).html() + '</textarea>'
                  +   '<div class="lic-alias-modal__hint">'
                  +     '仅使用 1 个激活码即可为站点绑定多个域名，避免多次购买。一行一个，最多 <?= LicenseService::MAX_ALIAS_HOSTS ?> 个。<br>'
                  +     '提示：别名域名必须指向本站点服务器，且会和主授权域名使用同一个 <code>emkey</code> 与中心服务通信。'
                  +   '</div>'
                  + '</div>',
                btn: ['保存', '取消'],
                btnAlign: 'c',
                yes: function (idx) {
                    var raw = $('#licAliasesInput').val() || '';
                    post({_action: 'save_aliases', aliases: raw}).done(function (res) {
                        if (res.code === 200) {
                            layer.close(idx);
                            layer.msg('已保存');
                            setTimeout(function () { location.reload(); }, 600);
                        } else {
                            layer.msg(res.msg || '保存失败', {icon: 2});
                        }
                    }).fail(function () {
                        layer.msg('网络异常', {icon: 2});
                    });
                }
            });
        });
    });
});
</script>
