<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var bool $officialLoggedIn 是否已登录官方账号 */

$pageTitle = '激活码仓库';
$logged    = !empty($officialLoggedIn);

include __DIR__ . '/header.php';
?>

<div class="popup-inner cw-wrap">
<?php if (!$logged): ?>
    <div class="cw-state"><i class="fa fa-user-circle-o"></i>尚未登录官方账号</div>
<?php else: ?>
    <div class="cw-gen">
        <div class="cw-gen__title">
            <span class="cw-gen__title-text"><i class="fa fa-plus-square-o"></i>生成激活码</span>
            <span class="cw-gen__balance">当前余额 <b id="cwBalance">—</b></span>
        </div>

        <div class="cw-prices" id="cwPrices"></div>

        <div class="cw-gen__bar">
            <label class="cw-gen__label" for="cwType">档位</label>
            <select class="form-group__input cw-select" id="cwType"></select>

            <label class="cw-gen__label" for="cwCountInput">数量</label>
            <input type="number" class="form-group__input cw-count" id="cwCountInput" min="1" max="200" step="1" value="1">

            <button type="button" class="popup-btn popup-btn--primary cw-gen__btn" id="cwGenerate">生成</button>
        </div>

        <div class="cw-gen__meta" id="cwMeta" hidden></div>

        <div class="form-group__error" id="cwError"></div>
    </div>

    <div class="cw-tabs" role="tablist" aria-label="激活码使用状态">
        <button type="button" class="cw-tab is-active" role="tab" aria-selected="true" data-usage="unused">未使用 <span class="cw-tab__count" id="cwCountUnused"></span></button>
        <button type="button" class="cw-tab" role="tab" aria-selected="false" data-usage="used">已使用 <span class="cw-tab__count" id="cwCountUsed"></span></button>
    </div>

    <div class="cw-list" id="cwList">
        <div class="cw-state"><i class="fa fa-spinner fa-spin"></i>加载中…</div>
    </div>

    <div class="cw-more-wrap" id="cwMoreWrap" hidden>
        <button type="button" class="cw-more" id="cwMore">加载更多</button>
    </div>
<?php endif; ?>
</div>

<style>
.cw-wrap { padding: 16px; }

/* ---------- 生成 ---------- */
.cw-gen {
    margin-bottom: 12px;
    padding: 14px 16px;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 14px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.cw-gen__title {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    margin-bottom: 12px;
    font-size: 13.5px; font-weight: 600; color: #111827;
}
.cw-gen__title-text { display: inline-flex; align-items: center; gap: 7px; }
.cw-gen__title i { color: #4f46e5; font-size: 13px; }
.cw-gen__balance { font-size: 12px; font-weight: 400; color: #9ca3af; }
.cw-gen__balance b {
    margin-left: 4px;
    font-family: Menlo, Consolas, Monaco, monospace;
    font-size: 13px; font-weight: 600; color: #111827;
}
.cw-prices {
    display: flex; flex-wrap: wrap; gap: 6px;
    margin-bottom: 10px;
}
.cw-price {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 9px;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    background: #f9fafb; color: #6b7280;
    font-size: 11.5px; line-height: 18px;
}
.cw-price b {
    font-family: Menlo, Consolas, Monaco, monospace;
    font-size: 12px; font-weight: 400; color: inherit;
}
.cw-price__unit { font-size: 11px; opacity: 0.72; }
.cw-price.is-vip { background: #eff6ff; border-color: #dbeafe; color: #2563eb; }
.cw-price.is-svip { background: #f5f3ff; border-color: #ede9fe; color: #7c3aed; }
.cw-price.is-supreme { background: #fffbeb; border-color: #fef3c7; color: #b45309; }
.cw-gen__bar {
    display: flex; align-items: center; gap: 8px;
}
.cw-gen__label {
    flex-shrink: 0;
    font-size: 12.5px; color: #6b7280;
}
.cw-select { flex: 1 1 0; min-width: 0; height: 36px; padding: 0 8px; }
.cw-count {
    flex: 0 0 64px; width: 64px;
    height: 36px; padding: 0 6px;
    text-align: center;
}
.cw-gen__btn { flex-shrink: 0; height: 36px; line-height: 36px; padding: 0 18px; }
.cw-gen__meta { margin-top: 8px; font-size: 12px; color: #9ca3af; line-height: 1.5; }
.cw-gen .form-group__error { margin-top: 8px; }

/* ---------- 分类标签 ---------- */
.cw-tabs { display: flex; gap: 8px; margin-bottom: 10px; }
.cw-tab {
    height: 32px; padding: 0 14px;
    border: 1px solid #e5e7eb; border-radius: 8px;
    background: #fff; color: #6b7280;
    font-size: 13px; cursor: pointer;
    transition: color 0.15s ease, border-color 0.15s ease, background 0.15s ease;
}
.cw-tab:hover { color: #4f46e5; border-color: #c7d2fe; }
.cw-tab.is-active { background: #eef2ff; border-color: #c7d2fe; color: #4f46e5; font-weight: 600; }
.cw-tab__count { font-size: 11.5px; font-weight: 400; opacity: 0.72; }

/* ---------- 列表 ---------- */
.cw-list {
    display: flex; flex-direction: column;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 12px;
    overflow: hidden;
}
.cw-item {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 12px;
    border-bottom: 1px solid #f1f3f7;
    transition: background 0.15s ease;
    user-select: none;
}
.cw-item:last-child { border-bottom: 0; }
.cw-item:hover { background: #fafbff; }
.cw-item__no {
    flex-shrink: 0;
    width: 24px;
    font-size: 11.5px; color: #9ca3af;
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.cw-item__code {
    flex: 1; min-width: 0;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    font-size: 12.5px; font-weight: 400; color: #111827;
    word-break: break-all;
    cursor: pointer;
    user-select: text;
}
.cw-item__code:hover { color: #4f46e5; }
.cw-item__tag {
    flex-shrink: 0;
    padding: 1px 6px;
    border-radius: 3px;
    font-size: 11px; line-height: 18px;
    background: #eef2ff; color: #4f46e5;
}
.cw-item__tag.is-vip { background: #eff6ff; color: #2563eb; }
.cw-item__tag.is-svip { background: #f5f3ff; color: #7c3aed; }
.cw-item__tag.is-supreme { background: #fffbeb; color: #b45309; }
.cw-item__time { flex-shrink: 0; font-size: 11.5px; color: #9ca3af; white-space: nowrap; }

.cw-more-wrap { margin-top: 12px; }
.cw-more {
    width: 100%; height: 34px;
    border: 1px solid #e0e0e0; border-radius: 4px;
    background: #fff; color: #333;
    font-size: 13px; cursor: pointer;
    transition: background 0.15s ease;
}
.cw-more:hover { background: #f5f5f5; }
.cw-more:disabled { opacity: 0.6; cursor: not-allowed; }

.cw-state {
    padding: 44px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
}
.cw-state i {
    display: block; margin-bottom: 8px;
    font-size: 26px; color: #d1d5db;
}

@media (max-width: 600px) {
    .cw-wrap { padding: 12px; }
    .cw-gen__bar { gap: 6px; }
    .cw-gen__btn { padding: 0 14px; }
}
</style>

<script>
$(function () {
    layui.use(['layer'], function () {
        var layer = layui.layer;
        var csrfToken = <?= json_encode($csrfToken) ?>;

        var pricing = null;
        var balance = '0.00';
        var currentPage = 0;
        var lastPage = 1;
        var usage = 'unused';
        var requestSeq = 0;

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

        function esc(s) {
            return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function money(v) {
            var n = parseFloat(v);
            return isNaN(n) ? '0.00' : n.toFixed(2);
        }

        function typeClass(type) {
            return ({ '1': ' is-vip', '2': ' is-svip', '3': ' is-supreme' })[String(type)] || '';
        }

        function refreshPreview() {
            if (!pricing || !pricing.configured) {
                $('#cwMeta')
                    .text('站长还没配好授权码价格，暂时生成不了，请联系管理员。')
                    .prop('hidden', false);
                return;
            }

            $('#cwMeta').prop('hidden', true).text('');
        }

        function renderPrices() {
            var $box = $('#cwPrices');
            $box.empty();

            if (!pricing || !pricing.fees) {
                return;
            }

            Object.keys(pricing.fees).forEach(function (type) {
                var fee = pricing.fees[type];
                var raw = fee.fee;
                var price;

                if (raw === '' || raw === undefined || raw === null) {
                    price = '未配置';
                } else if ((parseFloat(raw) || 0) <= 0) {
                    price = '免费';
                } else {
                    price = '¥ ' + money(raw);
                }

                $box.append(
                    '<span class="cw-price' + typeClass(type) + '">' +
                        '<span class="cw-price__label">' + esc(fee.label || '') + '</span>' +
                        '<b>' + esc(price) + '</b>' +
                        (price.indexOf('¥') === 0 ? '<span class="cw-price__unit">/ 个</span>' : '') +
                    '</span>'
                );
            });
        }

        function renderPricing() {
            renderPrices();

            var $select = $('#cwType');
            $select.empty();

            if (!pricing || !pricing.fees) {
                $('#cwGenerate').prop('disabled', true);
                refreshPreview();
                return;
            }

            Object.keys(pricing.fees).forEach(function (type) {
                var fee = pricing.fees[type];
                $select.append($('<option>')
                    .val(type)
                    .text(fee.label));
            });

            $('#cwGenerate').prop('disabled', !pricing.configured);
            refreshPreview();
        }

        function shortTime(v) {
            var m = String(v || '').match(/^\d{4}-(\d{2}-\d{2})[ T](\d{2}:\d{2})/);
            return m ? m[1] + ' ' + m[2] : String(v || '');
        }

        function renderRow(item, seq) {
            return '<div class="cw-item">' +
                       '<span class="cw-item__no">' + seq + '</span>' +
                       '<span class="cw-item__code" data-copy="' + esc(item.code) + '" title="点击复制">' +
                           esc(item.code) +
                       '</span>' +
                       '<span class="cw-item__tag' + typeClass(item.type) + '">' + esc(item.type_label || '') + '</span>' +
                       '<span class="cw-item__time" title="' + esc(item.created_at || '') + '">' +
                           esc(shortTime(item.created_at)) +
                       '</span>' +
                   '</div>';
        }

        function activateTab(next) {
            usage = next === 'used' ? 'used' : 'unused';

            $('.cw-tab').each(function () {
                var active = String($(this).data('usage')) === usage;
                $(this).toggleClass('is-active', active).attr('aria-selected', active ? 'true' : 'false');
            });
        }

        function load(nextPage) {
            var seq = ++requestSeq;
            $('#cwMore').prop('disabled', true);

            post({ _action: 'official_em_codes', usage: usage, page: nextPage, per_page: 10 }).done(function (res) {
                if (seq !== requestSeq) return;
                $('#cwMore').prop('disabled', false);

                if (!res || res.code !== 200) {
                    if (nextPage === 1) {
                        $('#cwList').html('<div class="cw-state"><i class="fa fa-exclamation-circle"></i>' +
                                          esc((res && res.msg) || '获取失败') + '</div>');
                    } else {
                        layer.msg((res && res.msg) || '获取失败');
                    }
                    return;
                }

                var data = res.data || {};
                var rows = data.data || [];

                pricing = data.pricing || null;
                balance = data.balance || '0.00';
                currentPage = nextPage;
                lastPage = parseInt(data.last_page, 10) || 1;

                $('#cwBalance').text('¥ ' + money(balance));

                if (data.counts) {
                    $('#cwCountUnused').text('(' + (parseInt(data.counts.unused, 10) || 0) + ')');
                    $('#cwCountUsed').text('(' + (parseInt(data.counts.used, 10) || 0) + ')');
                }

                renderPricing();

                if (nextPage === 1) {
                    $('#cwList').empty();
                }

                if (!rows.length) {
                    if (nextPage === 1) {
                        $('#cwList').html('<div class="cw-state"><i class="fa fa-inbox"></i>' +
                                          (usage === 'used'
                                              ? '还没有已使用的激活码'
                                              : '仓库里还没有激活码，可以在上面生成') + '</div>');
                    }
                    $('#cwMoreWrap').prop('hidden', true);
                    return;
                }

                var perPage = parseInt(data.per_page, 10) || 10;

                $('#cwList').append(rows.map(function (item, index) {
                    return renderRow(item, (nextPage - 1) * perPage + index + 1);
                }).join(''));
                $('#cwMoreWrap').prop('hidden', currentPage >= lastPage);
            }).fail(function () {
                if (seq !== requestSeq) return;
                $('#cwMore').prop('disabled', false);
                if (nextPage === 1) {
                    $('#cwList').html('<div class="cw-state"><i class="fa fa-exclamation-circle"></i>网络异常，请稍后重试</div>');
                } else {
                    layer.msg('网络异常');
                }
            });
        }

        $('#cwType').on('change', refreshPreview);
        $('#cwCountInput').on('input change', refreshPreview);

        $('#cwMore').on('click', function () {
            load(currentPage + 1);
        });

        $('.cw-tab').on('click', function () {
            var next = String($(this).data('usage') || 'unused');
            if (next === usage) return;

            activateTab(next);
            $('#cwMoreWrap').prop('hidden', true);
            $('#cwList').html('<div class="cw-state"><i class="fa fa-spinner fa-spin"></i>加载中…</div>');
            load(1);
        });

        $(document).on('click', '.cw-item__code', function () {
            var code = String($(this).data('copy') || '');
            if (code === '') return;

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(code).then(function () {
                    layer.msg('已复制');
                }, function () {
                    layer.msg('复制失败');
                });
                return;
            }
            var $ta = $('<textarea>').val(code).css({ position: 'fixed', top: 0, left: 0, opacity: 0 }).appendTo('body').select();
            try { document.execCommand('copy'); layer.msg('已复制'); } catch (e) { layer.msg('复制失败'); }
            $ta.remove();
        });

        $('#cwGenerate').on('click', function () {
            var $btn = $(this);
            if ($btn.prop('disabled')) return;

            var type = String($('#cwType').val() || '');
            var count = parseInt($('#cwCountInput').val(), 10);

            $('#cwError').removeClass('is-visible').text('');

            if (pricing && !pricing.configured) {
                $('#cwError').text('站长还没配好授权码价格，暂时生成不了').addClass('is-visible');
                return;
            }
            if (isNaN(count) || count < 1 || count > 200) {
                $('#cwError').text('生成数量需在 1 - 200 之间').addClass('is-visible');
                return;
            }

            var fee = pricing && pricing.fees ? pricing.fees[type] : null;
            if (!fee) {
                $('#cwError').text('该档位还没配置价格，暂时生成不了').addClass('is-visible');
                return;
            }

            var feeValue = parseFloat(fee.fee) || 0;
            var affordable = parseInt(fee.affordable, 10) || 0;
            if (feeValue > 0 && count > affordable) {
                $('#cwError').text('余额不足，当前最多还能生成 ' + affordable + ' 个').addClass('is-visible');
                return;
            }

            $btn.prop('disabled', true).text('生成中…');

            post({ _action: 'official_em_codes_generate', type: type, count: count }).done(function (res) {
                $btn.prop('disabled', false).text('生成');

                if (!res || res.code !== 200) {
                    $('#cwError').text((res && res.msg) || '生成失败').addClass('is-visible');
                    return;
                }

                var data = res.data || {};
                layer.msg('已生成激活码，扣费 ¥' + money(data.cost));

                /* 新码一定在「未使用」里，停在已使用页会看不到刚生成的码 */
                if (usage !== 'unused') {
                    activateTab('unused');
                }
                load(1);
            }).fail(function () {
                $btn.prop('disabled', false).text('生成');
                $('#cwError').text('网络异常，请稍后重试').addClass('is-visible');
            });
        });

        <?php if ($logged): ?>
        load(1);
        <?php endif; ?>
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
