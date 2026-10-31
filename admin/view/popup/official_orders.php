<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var bool $officialLoggedIn 是否已登录官方账号 */

$pageTitle = '用户订单记录';
$logged    = !empty($officialLoggedIn);

include __DIR__ . '/header.php';
?>

<div class="popup-inner oo-wrap">
<?php if (!$logged): ?>
    <div class="oo-state"><i class="fa fa-user-circle-o"></i>尚未登录官方账号</div>
<?php else: ?>
    <div class="oo-filter">
        <input type="text" class="form-group__input oo-keyword" id="ooKeyword"
               placeholder="订单号 / 授权码 / 域名 / 应用名" autocomplete="off">
        <select class="form-group__input oo-select" id="ooState">
            <option value="">全部状态</option>
            <option value="pending">待支付</option>
            <option value="paid">已支付</option>
            <option value="expired">已过期</option>
            <option value="closed">已关闭</option>
        </select>
        <button type="button" class="popup-btn popup-btn--primary oo-search" id="ooSearch">搜索</button>
        <button type="button" class="popup-btn popup-btn--default oo-reset" id="ooReset" hidden>重置</button>
    </div>

    <div class="oo-rate" id="ooRate"></div>

    <div class="oo-list" id="ooList">
        <div class="oo-state"><i class="fa fa-spinner fa-spin"></i>加载中…</div>
    </div>

    <div class="oo-more-wrap" id="ooMoreWrap" hidden>
        <button type="button" class="oo-more" id="ooMore">加载更多</button>
    </div>
<?php endif; ?>
</div>

<style>
.oo-wrap { padding: 16px; }

.oo-filter { display: flex; flex-wrap: wrap; gap: 8px; }
.oo-keyword { flex: 1 1 180px; min-width: 0; height: 34px; }
.oo-select { flex: 0 0 112px; width: 112px; height: 34px; padding: 0 6px; }
.oo-search, .oo-reset { height: 34px; line-height: 34px; padding: 0 14px; }
.oo-reset { border: 1px solid #e0e0e0; background: #fff; color: #333; }
.oo-reset:hover { background: #f5f5f5; }
.oo-rate { margin: 8px 0 10px; font-size: 11.5px; color: #9ca3af; line-height: 1.5; }

.oo-list {
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 12px;
    overflow: hidden;
}
.oo-item {
    padding: 9px 12px;
    border-bottom: 1px solid #f1f3f7;
}
.oo-item:last-child { border-bottom: 0; }
.oo-item:hover { background: #fafbff; }
.oo-item__head {
    display: flex; align-items: center; gap: 8px;
    cursor: pointer;
}
.oo-item__no {
    flex: 1; min-width: 0;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    font-size: 12.5px; color: #111827;
    word-break: break-all;
}
.oo-item__state {
    flex-shrink: 0;
    padding: 1px 6px; border-radius: 3px;
    font-size: 11px; line-height: 18px;
    background: #f4f5f3; color: #6b7280;
}
.oo-item__state.is-pending { background: #fffbeb; color: #b45309; }
.oo-item__state.is-paid { background: #ecfdf5; color: #059669; }
.oo-item__state.is-closed { background: #fff1f2; color: #e11d48; }
.oo-item__state.is-expired { background: #f4f5f3; color: #9ca3af; }
.oo-item__amount {
    flex-shrink: 0;
    font-family: Menlo, Consolas, Monaco, monospace;
    font-size: 12.5px; font-weight: 600; color: #111827;
}
.oo-item__arrow { flex-shrink: 0; font-size: 12px; color: #c4c9d4; transition: transform 0.15s ease; }
.oo-item.is-open .oo-item__arrow { transform: rotate(180deg); }
.oo-item__meta {
    display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px;
    margin-top: 5px;
    font-size: 11.5px; color: #6b7280;
}
.oo-item__meta span { word-break: break-all; }
.oo-item__meta .is-mono { font-family: Menlo, Consolas, Monaco, monospace; }
.oo-item__commission.is-done { color: #059669; }

.oo-detail {
    margin: 9px 0 0;
    padding-top: 9px;
    border-top: 1px dashed #eef0f4;
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px 14px;
}
.oo-detail > div { display: flex; gap: 6px; font-size: 11.5px; line-height: 1.6; }
.oo-detail dt { flex: 0 0 58px; color: #9ca3af; }
.oo-detail dd { margin: 0; min-width: 0; color: #374151; word-break: break-all; }

.oo-state {
    padding: 44px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
}
.oo-state i {
    display: block; margin-bottom: 8px;
    font-size: 26px; color: #d1d5db;
}

.oo-more-wrap { margin-top: 10px; }
.oo-more {
    width: 100%; height: 34px;
    border: 1px solid #e0e0e0; border-radius: 4px;
    background: #fff; color: #333;
    font-size: 13px; cursor: pointer;
    transition: background 0.15s ease;
}
.oo-more:hover { background: #f5f5f5; }
.oo-more:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 600px) {
    .oo-wrap { padding: 12px; }
    .oo-select { flex: 1 1 100px; width: auto; }
    .oo-search, .oo-reset { padding: 0 12px; }
    .oo-detail { grid-template-columns: 1fr; }
}
</style>

<script>
$(function () {
    layui.use(['layer'], function () {
        var layer = layui.layer;
        var csrfToken = <?= json_encode($csrfToken) ?>;

        var currentPage = 0;
        var lastPage = 1;
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

        function shortTime(v) {
            var m = String(v || '').match(/^\d{4}-(\d{2}-\d{2})[ T](\d{2}:\d{2})/);
            return m ? m[1] + ' ' + m[2] : String(v || '');
        }

        function fullTime(v) {
            return String(v || '') === '' ? '—' : String(v);
        }

        function stateText(state) {
            return ({ pending: '待支付', paid: '已支付', expired: '已过期', closed: '已关闭' })[state] || '未知';
        }

        function stateClass(state) {
            return ({ pending: ' is-pending', paid: ' is-paid', expired: ' is-expired', closed: ' is-closed' })[state] || '';
        }

        function percentText(rate) {
            var text = String(rate === undefined || rate === null ? '' : rate).trim();
            if (text === '') return '—';
            return isNaN(Number(text)) ? (text + '%') : (parseFloat(text) + '%');
        }

        function commissionText(row) {
            if (row.commission_at === null || row.commission_at === undefined) {
                return '未结佣';
            }
            return '¥ ' + money(row.commission_amount);
        }

        function detailHtml(row) {
            var rows = [
                ['程序', esc(row.project_label || row.project || '—')],
                ['应用', esc(row.app_name || '—') + (row.app_type_label ? '（' + esc(row.app_type_label) + '）' : '')],
                ['授权档位', esc(row.license_type_label || '—')],
                ['授权码', '<span class="is-mono">' + esc(row.license_code || '—') + '</span>'],
                ['授权域名', '<span class="is-mono">' + esc(row.domain || '—') + '</span>'],
                ['订单金额', '¥ ' + money(row.amount)],
                ['佣金', commissionText(row)],
                ['订单状态', esc(stateText(row.state))],
                ['下单时间', esc(fullTime(row.created_at))],
                ['支付时间', row.paid_at ? esc(row.paid_at) : '—'],
                ['支付来源', esc(row.paid_source_label || '—')],
                ['支付方式', esc(row.pay_method_label || '—')],
                ['交易号', esc(row.trade_no || '—')],
                ['过期时间', esc(fullTime(row.expires_at))]
            ];

            return '<dl class="oo-detail">' + rows.map(function (item) {
                return '<div><dt>' + item[0] + '</dt><dd>' + item[1] + '</dd></div>';
            }).join('') + '</dl>';
        }

        function renderRow(row) {
            return '<div class="oo-item">' +
                       '<div class="oo-item__head" role="button" tabindex="0" aria-expanded="false">' +
                           '<span class="oo-item__no">' + esc(row.order_no || '') + '</span>' +
                           '<span class="oo-item__state' + stateClass(row.state) + '">' + esc(stateText(row.state)) + '</span>' +
                           '<span class="oo-item__amount">¥ ' + money(row.amount) + '</span>' +
                           '<i class="fa fa-angle-down oo-item__arrow"></i>' +
                       '</div>' +
                       '<div class="oo-item__meta">' +
                           '<span>' + esc(row.project_label || row.project || '—') +
                               (row.app_name ? ' · ' + esc(row.app_name) : '') + '</span>' +
                           '<span class="is-mono">' + esc(row.license_code || '—') + '</span>' +
                           (row.domain ? '<span class="is-mono">' + esc(row.domain) + '</span>' : '') +
                           '<span class="oo-item__commission' + (row.commission_at ? ' is-done' : '') + '">' +
                               commissionText(row) + '</span>' +
                           '<span>' + esc(shortTime(row.created_at)) + '</span>' +
                       '</div>' +
                       '<div class="oo-item__detail" hidden>' + detailHtml(row) + '</div>' +
                   '</div>';
        }

        function load(nextPage, reset) {
            var seq = ++requestSeq;
            $('#ooMore').prop('disabled', true);

            post({
                _action: 'official_orders',
                page: nextPage,
                per_page: 10,
                keyword: $.trim($('#ooKeyword').val()),
                state: $('#ooState').val()
            }).done(function (res) {
                if (seq !== requestSeq) return;
                $('#ooMore').prop('disabled', false);

                if (!res || res.code !== 200) {
                    if (nextPage === 1) {
                        $('#ooList').html('<div class="oo-state"><i class="fa fa-exclamation-circle"></i>' +
                                          esc((res && res.msg) || '获取失败') + '</div>');
                    } else {
                        layer.msg((res && res.msg) || '获取失败');
                    }
                    return;
                }

                var data = res.data || {};
                var rows = data.data || [];

                currentPage = nextPage;
                lastPage = parseInt(data.last_page, 10) || 1;

                $('#ooRate').text('佣金按订单金额的 ' + percentText(data.commission_rate) +
                                  ' 结算，只在真实支付回调时发放');

                if (reset) {
                    $('#ooList').empty();
                }

                if (!rows.length) {
                    if (nextPage === 1) {
                        $('#ooList').html('<div class="oo-state"><i class="fa fa-inbox"></i>还没有订单记录</div>');
                    }
                    $('#ooMoreWrap').prop('hidden', true);
                    return;
                }

                $('#ooList').append(rows.map(renderRow).join(''));
                $('#ooMoreWrap').prop('hidden', currentPage >= lastPage);
            }).fail(function () {
                if (seq !== requestSeq) return;
                $('#ooMore').prop('disabled', false);
                if (nextPage === 1) {
                    $('#ooList').html('<div class="oo-state"><i class="fa fa-exclamation-circle"></i>网络异常，请稍后重试</div>');
                } else {
                    layer.msg('网络异常');
                }
            });
        }

        function search() {
            $('#ooMoreWrap').prop('hidden', true);
            $('#ooList').html('<div class="oo-state"><i class="fa fa-spinner fa-spin"></i>加载中…</div>');
            load(1, true);
        }

        function refreshReset() {
            var active = $.trim($('#ooKeyword').val()) !== '' || $('#ooState').val() !== '';
            $('#ooReset').prop('hidden', !active);
        }

        $('#ooSearch').on('click', search);
        $('#ooKeyword').on('keydown', function (e) {
            if (e.key === 'Enter') search();
        });
        $('#ooState').on('change', search);
        $('#ooKeyword').on('input', refreshReset);
        $('#ooState').on('change', refreshReset);

        $('#ooReset').on('click', function () {
            $('#ooKeyword').val('');
            $('#ooState').val('');
            refreshReset();
            search();
        });

        $('#ooMore').on('click', function () {
            load(currentPage + 1, false);
        });

        $(document).on('click', '.oo-item__head', function () {
            var $item = $(this).closest('.oo-item');
            var open = !$item.hasClass('is-open');

            $item.toggleClass('is-open', open);
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            $item.find('.oo-item__detail').prop('hidden', !open);
        });

        $(document).on('keydown', '.oo-item__head', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault();
                $(this).trigger('click');
            }
        });

        load(1, true);
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
