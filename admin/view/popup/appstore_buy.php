<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
?>
<style>
/* ============================================================
 * 购买弹窗 —— 上下分栏：上为应用信息、下为支付方式；立即支付按钮由 popup-footer 固定在底部
 * 布局模型复用 popup.css：popup-wrap > popup-content > popup-inner(滚动) + popup-footer(flex-shrink:0)
 * ============================================================ */

/* 覆盖 popup.css 给 popup-body 设的灰底（本弹窗用白底更贴合 hero 渐变） */
body.popup-body { background: #fff; }

/* popup-inner 默认有 16px padding，这里去掉让 hero 铺满 */
.popup-inner.buy-modal__inner { padding: 0; }

/* 顶部渐变装饰 + 应用信息 */
.buy-modal__hero {
    padding: 22px 24px 18px;
    background: linear-gradient(135deg, #eef2ff 0%, #fdf4ff 100%);
    border-bottom: 1px solid #f1f5f9;
}
.buy-modal__app {
    display: flex; gap: 16px; align-items: flex-start;
}
.buy-modal__cover {
    width: 72px; height: 72px;
    border-radius: 14px;
    background: #fff;
    object-fit: cover;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
    flex-shrink: 0;
}
.buy-modal__cover--empty {
    display: inline-flex; align-items: center; justify-content: center;
    color: #9ca3af; font-size: 28px;
}
.buy-modal__meta { flex: 1; min-width: 0; }
.buy-modal__name {
    font-size: 18px; font-weight: 700;
    color: #0f172a; letter-spacing: 0.3px;
    margin-bottom: 4px;
    word-break: break-all;
}
.buy-modal__name-en {
    font-size: 11px; color: #9ca3af;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    margin-bottom: 8px;
}
.buy-modal__tags {
    display: flex; gap: 6px; flex-wrap: wrap;
    margin-bottom: 8px;
}
.buy-modal__tag {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px;
    font-size: 11px; font-weight: 500;
    border-radius: 4px;
    line-height: 18px;
}
.buy-modal__tag i { font-size: 10px; }
.buy-modal__tag--template { background: #ecfeff; color: #0891b2; }
.buy-modal__tag--plugin   { background: #f5f3ff; color: #7c3aed; }
.buy-modal__tag--version  { background: #fff; color: #6b7280; border: 1px solid #e5e7eb; }
.buy-modal__tag--install  { background: #fff; color: #6b7280; border: 1px solid #e5e7eb; }
.buy-modal__desc {
    font-size: 12px; color: #4b5563;
    line-height: 1.7;
    display: -webkit-box; -webkit-line-clamp: 3; line-clamp: 3;
    -webkit-box-orient: vertical; overflow: hidden;
}
.buy-modal__desc--empty { color: #9ca3af; font-style: italic; }

/* 下半：金额 + 支付方式 */
.buy-modal__pay { padding: 18px 24px 22px; }
.buy-modal__amount-row {
    display: flex; align-items: baseline; justify-content: space-between;
    padding-bottom: 14px;
    margin-bottom: 14px;
    border-bottom: 1px dashed #e5e7eb;
}
.buy-modal__amount-label { font-size: 13px; color: #6b7280; }
.buy-modal__amount-value {
    font-size: 24px; font-weight: 700;
    color: #dc2626;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    letter-spacing: 0.5px;
}
.buy-modal__amount-value .buy-modal__amount-cur {
    font-size: 14px; font-weight: 500;
    color: #dc2626;
    margin-right: 3px;
}

.buy-modal__pay-title {
    font-size: 13px; font-weight: 600;
    color: #374151;
    margin-bottom: 10px;
    display: inline-flex; align-items: center; gap: 6px;
}
.buy-modal__pay-title::before {
    content: '';
    width: 3px; height: 12px;
    background: linear-gradient(180deg, #6366f1, #8b5cf6);
    border-radius: 2px;
}

.buy-modal__methods {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    gap: 10px;
}
.buy-modal__method {
    position: relative;
    display: flex; flex-direction: column; align-items: center; gap: 6px;
    padding: 14px 10px 12px;
    background: #fff;
    border: 1.5px solid #e5e7eb;
    border-radius: 10px;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.buy-modal__method:hover {
    border-color: #c7d2fe;
    background: #fafafe;
    transform: translateY(-1px);
}
.buy-modal__method.is-active {
    border-color: #6366f1;
    background: #eef2ff;
    box-shadow: 0 2px 10px rgba(99, 102, 241, 0.18);
}
.buy-modal__method.is-active::after {
    content: '\f00c'; /* fa-check */
    font-family: FontAwesome;
    position: absolute; top: 4px; right: 6px;
    font-size: 10px; color: #6366f1;
}
/* 支付方式图标槽位：内部直接放 <img>，图标自带品牌色，不再做背景/颜色区分 */
.buy-modal__method-icon {
    width: 36px; height: 36px;
    display: inline-flex; align-items: center; justify-content: center;
}
.buy-modal__method-icon img {
    width: 100%; height: 100%;
    object-fit: contain;
}
.buy-modal__method-icon i {
    font-size: 22px; color: #9ca3af; /* 无对应图片时的兜底 icon */
}
.buy-modal__method-name {
    font-size: 12px; font-weight: 500;
    color: #374151;
    letter-spacing: 0.2px;
}
.buy-modal__methods-empty {
    grid-column: 1 / -1;
    padding: 30px 12px;
    text-align: center;
    color: #9ca3af;
    font-size: 13px;
    background: #f9fafb;
    border-radius: 8px;
}
.buy-modal__methods-empty i { display: block; font-size: 22px; margin-bottom: 6px; color: #d1d5db; }

/* 底部固定按钮：全宽紫色渐变 CTA */
.popup-footer.buy-modal__footer {
    padding: 14px 20px;
    justify-content: stretch;
}
.buy-modal__confirm {
    flex: 1;
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    padding: 12px 20px;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: #fff;
    border: none; border-radius: 10px;
    font-size: 14px; font-weight: 600;
    letter-spacing: 0.5px;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
}
.buy-modal__confirm:hover {
    filter: brightness(1.05);
    box-shadow: 0 6px 18px rgba(79, 70, 229, 0.35);
    transform: translateY(-1px);
}
.buy-modal__confirm:disabled {
    background: #e5e7eb;
    color: #9ca3af;
    box-shadow: none;
    cursor: not-allowed;
    transform: none;
}

/* Loading / 错误态：居中占满 popup-inner 可用高度 */
.buy-modal__state {
    min-height: 100%;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    color: #9ca3af;
    font-size: 13px;
    padding: 40px 20px;
    text-align: center;
}
.buy-modal__state i { font-size: 30px; margin-bottom: 12px; color: #d1d5db; }
.buy-modal__state--error i { color: #ef4444; }
.buy-modal__state .buy-modal__state-hint {
    margin-top: 6px; color: #6b7280;
    font-size: 12px;
}
</style>

<div class="popup-wrap">
    <div class="popup-content">
        <!-- 滚动区：应用信息 + 支付方式 -->
        <div class="popup-inner buy-modal__inner" id="buyModalRoot">
            <div class="buy-modal__state">
                <i class="fa fa-spinner fa-spin"></i>
                <div>正在加载应用信息…</div>
            </div>
        </div>
        <!-- 固定底部：立即支付；加载完成前先隐藏 -->
        <div class="popup-footer buy-modal__footer" id="buyModalFooter" style="display:none;">
            <button type="button" class="buy-modal__confirm" id="buyModalConfirm">
                立即支付
            </button>
        </div>
    </div>
</div>

<script>
$(function () {
    'use strict';

    var appId = parseInt(window.APPSTORE_BUY_ID, 10) || 0;
    var assetHost = window.APPSTORE_ASSET_HOST || '';

    // 支付方式图标：每种 code 对应 /content/static/img 下的图片（content 静态图）
    // 没有映射的 code 走 fa-credit-card 兜底
    var PAY_METHOD_ICON = {
        alipay: '/content/static/img/alipay.png',
        wxpay:  '/content/static/img/wxpay.png',
        trx:    '/content/static/img/trx.png',
        usdt:   '/content/static/img/usdt.png'
    };

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }
    function absUrl(url) {
        if (!url) return '';
        if (/^https?:\/\//i.test(url)) return url;
        return assetHost + (url.charAt(0) === '/' ? '' : '/') + url;
    }
    function renderState(iconCls, msg, hint, isError) {
        $('#buyModalFooter').hide();
        $('#buyModalRoot').html(
            '<div class="buy-modal__state' + (isError ? ' buy-modal__state--error' : '') + '">'
          +   '<i class="fa ' + iconCls + '"></i>'
          +   '<div>' + escapeHtml(msg) + '</div>'
          +   (hint ? '<div class="buy-modal__state-hint">' + escapeHtml(hint) + '</div>' : '')
          + '</div>'
        );
    }

    if (!appId) {
        renderState('fa-exclamation-circle', '未指定应用', '请从应用商店点击"购买"进入此页', true);
        return;
    }

    // 一次拉齐：/api/app_detail.php 同时返回 app 详情 + 支付方式
    $.ajax({
        url: '/admin/appstore.php',
        method: 'GET',
        dataType: 'json',
        timeout: 12000,
        data: { _action: 'app_detail', id: appId, tab: window.APPSTORE_BUY_TAB || 'main' }
    }).done(function (resp) {
        if (!resp || resp.code !== 200 || !resp.data || !resp.data.app) {
            renderState('fa-exclamation-circle', (resp && resp.msg) || '加载失败',
                '可能已下架或被授权服务器剔除', true);
            return;
        }
        renderApp(resp.data.app, resp.data.pay_methods || []);
    }).fail(function () {
        renderState('fa-exclamation-triangle', '加载失败', '请检查网络后重试', true);
    });

    function renderApp(app, methods) {
        var typeCls  = app.type === 'template' ? 'template' : 'plugin';
        var typeIcon = app.type === 'template' ? 'fa-paint-brush' : 'fa-puzzle-piece';
        var typeText = app.type === 'template' ? '模板' : '插件';

        var rawDesc = (app.content || '').replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
        var descHtml = rawDesc
            ? escapeHtml(rawDesc)
            : '<span class="buy-modal__desc--empty">该应用未配置描述信息</span>';

        var coverHtml = app.cover
            ? '<img class="buy-modal__cover" src="' + escapeHtml(absUrl(app.cover)) + '" alt="">'
            : '<span class="buy-modal__cover buy-modal__cover--empty"><i class="fa fa-cube"></i></span>';

        var versionTag = app.version
            ? '<span class="buy-modal__tag buy-modal__tag--version"><i class="fa fa-tag"></i> v' + escapeHtml(String(app.version)) + '</span>'
            : '';
        var installTag = (Number(app.install_num) || 0) > 0
            ? '<span class="buy-modal__tag buy-modal__tag--install"><i class="fa fa-download"></i> ' + Number(app.install_num).toLocaleString() + ' 次安装</span>'
            : '';

        var methodsHtml;
        if (methods.length === 0) {
            methodsHtml = '<div class="buy-modal__methods-empty">'
                        +   '<i class="fa fa-credit-card"></i>'
                        +   '暂无可用支付方式，请联系客服'
                        + '</div>';
        } else {
            methodsHtml = methods.map(function (m, i) {
                var iconSrc = PAY_METHOD_ICON[m.code];
                var iconHtml = iconSrc
                    ? '<img src="' + escapeHtml(iconSrc) + '" alt="">'
                    : '<i class="fa fa-credit-card"></i>';
                return '<div class="buy-modal__method' + (i === 0 ? ' is-active' : '') + '" data-code="' + escapeHtml(m.code) + '">'
                     +     '<span class="buy-modal__method-icon">' + iconHtml + '</span>'
                     +     '<span class="buy-modal__method-name">' + escapeHtml(m.name) + '</span>'
                     + '</div>';
            }).join('');
        }

        var price = parseFloat(app.my_price || 0).toFixed(2);
        var displayName   = app.name_cn || app.name_en || '-';
        var displayNameEn = app.name_en && app.name_en !== app.name_cn ? app.name_en : '';

        // 滚动区：应用信息 + 金额 + 支付方式（不含按钮，按钮在 popup-footer 里）
        var html =
            '<div class="buy-modal__hero">'
          +   '<div class="buy-modal__app">'
          +     coverHtml
          +     '<div class="buy-modal__meta">'
          +       '<div class="buy-modal__name">' + escapeHtml(displayName) + '</div>'
          +       (displayNameEn ? '<div class="buy-modal__name-en">' + escapeHtml(displayNameEn) + '</div>' : '')
          +       '<div class="buy-modal__tags">'
          +         '<span class="buy-modal__tag buy-modal__tag--' + typeCls + '">'
          +           '<i class="fa ' + typeIcon + '"></i> ' + typeText
          +         '</span>'
          +         versionTag + installTag
          +       '</div>'
          +       '<div class="buy-modal__desc">' + descHtml + '</div>'
          +     '</div>'
          +   '</div>'
          + '</div>'
          + '<div class="buy-modal__pay">'
          +   '<div class="buy-modal__amount-row">'
          +     '<span class="buy-modal__amount-label">应付金额</span>'
          +     '<span class="buy-modal__amount-value">'
          +       '<span class="buy-modal__amount-cur">¥</span>' + price
          +     '</span>'
          +   '</div>'
          +   '<div class="buy-modal__pay-title">选择支付方式</div>'
          +   '<div class="buy-modal__methods">' + methodsHtml + '</div>'
          + '</div>';

        $('#buyModalRoot').html(html);

        // 底部按钮：根据是否有支付方式启用/禁用，金额放进按钮里更醒目
        var $confirm = $('#buyModalConfirm');
        $confirm.html('立即支付 ¥' + price)
                .prop('disabled', methods.length === 0);
        $('#buyModalFooter').show();

        // 支付方式单选切换
        $(document).off('click.buyModalMethod').on('click.buyModalMethod', '.buy-modal__method', function () {
            $('.buy-modal__method').removeClass('is-active');
            $(this).addClass('is-active');
        });
        // 立即支付：下单 → 拿到 pay_url → 顶层跳转到收银台
        $(document).off('click.buyModalConfirm').on('click.buyModalConfirm', '#buyModalConfirm:not(:disabled)', function () {
            var code = $('.buy-modal__method.is-active').attr('data-code') || '';
            if (!code) { layui.layer.msg('请先选择支付方式'); return; }

            var $btn = $(this);
            var origHtml = $btn.html();
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 下单中…');
            var loadingIdx = layui.layer.load(2, { shade: [0.3, '#000'] });

            $.ajax({
                url: '/admin/appstore.php',
                method: 'POST',
                dataType: 'json',
                timeout: 15000,
                data: {
                    _action:    'app_buy',
                    app_id:     app.id,
                    pay_method: code,
                    tab:        window.APPSTORE_BUY_TAB || 'main',
                    csrf_token: window.adminCsrfToken || ''
                }
            }).done(function (resp) {
                if (resp && resp.data && resp.data.csrf_token) {
                    window.adminCsrfToken = resp.data.csrf_token;
                }
                if (!resp || resp.code !== 200 || !resp.data || !resp.data.pay_url) {
                    layui.layer.close(loadingIdx);
                    layui.layer.msg((resp && resp.msg) || '下单失败');
                    $btn.prop('disabled', false).html(origHtml);
                    return;
                }
                // iframe 与父页同源，浏览器允许顶层跳转；直接切到收银台完成支付
                try {
                    window.top.location.href = resp.data.pay_url;
                } catch (e) {
                    // 兜底：真出现跨域异常时退化为 iframe 内跳
                    window.location.href = resp.data.pay_url;
                }
            }).fail(function () {
                layui.layer.close(loadingIdx);
                layui.layer.msg('网络异常，请稍后重试');
                $btn.prop('disabled', false).html(origHtml);
            });
        });
    }
});
</script>
