<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var bool $officialLoggedIn 是否已登录官方账号（未登录时只显示提示，不发请求） */

$pageTitle = '账户余额';
$logged    = !empty($officialLoggedIn);

include __DIR__ . '/header.php';
?>

<div class="popup-inner bl-wrap">
<?php if (!$logged): ?>
    <div class="bl-state"><i class="fa fa-user-circle-o"></i>尚未登录官方账号</div>
<?php else: ?>
    <div class="bl-summary">
        <div class="bl-summary__label">当前余额</div>
        <div class="bl-summary__value" id="blBalance">—</div>
        <div class="bl-summary__count" id="blCount"></div>
    </div>

    <div class="bl-list" id="blList">
        <div class="bl-state"><i class="fa fa-spinner fa-spin"></i>正在获取余额变动…</div>
    </div>

    <div class="bl-more-wrap" id="blMoreWrap" hidden>
        <button type="button" class="bl-more" id="blMore">加载更多</button>
    </div>
<?php endif; ?>
</div>

<style>
.bl-wrap { padding: 16px; }

/* ---------- 当前余额 ---------- */
.bl-summary {
    margin-bottom: 12px;
    padding: 16px 18px;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 14px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.bl-summary__label { font-size: 12px; color: #9ca3af; }
.bl-summary__value {
    margin-top: 6px;
    font-family: Menlo, Consolas, Monaco, monospace;
    font-size: 24px; font-weight: 700; color: #111827;
    line-height: 1.2;
}
.bl-summary__value.is-negative { color: #dc2626; }
.bl-summary__count {
    margin-top: 6px;
    min-height: 17px;
    font-size: 11.5px; color: #9ca3af;
}

/* ---------- 变动列表 ---------- */
.bl-list { display: flex; flex-direction: column; gap: 8px; }
.bl-item {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 12px 14px;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 12px;
}
.bl-item__main { flex: 1; min-width: 0; }
.bl-item__title { font-size: 13px; font-weight: 600; color: #111827; }
.bl-item__remark {
    margin-top: 3px;
    font-size: 11.5px; color: #6b7280; line-height: 1.5;
    word-break: break-all;
}
.bl-item__time { margin-top: 4px; font-size: 11px; color: #9ca3af; }
.bl-item__amount {
    flex-shrink: 0;
    font-family: Menlo, Consolas, Monaco, monospace;
    font-size: 14px; font-weight: 600; line-height: 1.4;
}
.bl-item__amount.is-plus  { color: #059669; }
.bl-item__amount.is-minus { color: #dc2626; }

/* ---------- 加载更多 ---------- */
.bl-more-wrap { margin-top: 12px; }
.bl-more {
    width: 100%; height: 34px;
    border: 1px solid #e0e0e0; border-radius: 4px;
    background: #fff; color: #333;
    font-size: 13px; cursor: pointer;
    transition: background 0.15s ease;
}
.bl-more:hover { background: #f5f5f5; }
.bl-more:disabled { opacity: 0.6; cursor: not-allowed; }

/* ---------- 状态 ---------- */
.bl-state {
    padding: 44px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
}
.bl-state i {
    display: block; margin-bottom: 8px;
    font-size: 26px; color: #d1d5db;
}

@media (max-width: 600px) {
    .bl-wrap { padding: 12px; }
}
</style>

<script>
$(function () {
    layui.use(['layer'], function () {
        var layer = layui.layer;
        var csrfToken = <?= json_encode($csrfToken) ?>;
        var perPage = 10;
        var currentPage = 0;
        var lastPage = 1;
        var loading = false;

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

        // amount 恒为正数，方向看前后余额（与官方站口径一致）
        function renderRow(log) {
            var positive = Number(log.balance_after) >= Number(log.balance_before);

            return '<div class="bl-item">' +
                        '<div class="bl-item__main">' +
                            '<div class="bl-item__title">' + esc(log.action_label || log.action || '余额变动') + '</div>' +
                            '<div class="bl-item__remark">' + esc(log.remark || '（无备注）') + '</div>' +
                            '<div class="bl-item__time">' + esc(log.created_at || '') + '</div>' +
                        '</div>' +
                        '<div class="bl-item__amount ' + (positive ? 'is-plus' : 'is-minus') + '">' +
                            (positive ? '+' : '-') + money(log.amount) +
                        '</div>' +
                    '</div>';
        }

        function load(nextPage) {
            if (loading) return;
            loading = true;
            $('#blMore').prop('disabled', true);

            post({ _action: 'official_balance_logs', page: nextPage, per_page: perPage }).done(function (res) {
                loading = false;
                $('#blMore').prop('disabled', false);

                if (!res || res.code !== 200) {
                    if (nextPage === 1) {
                        $('#blList').html('<div class="bl-state"><i class="fa fa-exclamation-circle"></i>' +
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

                var balance = parseFloat(data.balance);
                $('#blBalance')
                    .text('¥ ' + (isNaN(balance) ? '0.00' : balance.toFixed(2)))
                    .toggleClass('is-negative', !isNaN(balance) && balance < 0);
                $('#blCount').text(data.total ? '共 ' + data.total + ' 条变动' : '');

                if (nextPage === 1) {
                    $('#blList').empty();
                }

                if (!rows.length) {
                    if (nextPage === 1) {
                        $('#blList').html('<div class="bl-state"><i class="fa fa-inbox"></i>还没有余额变动</div>');
                    }
                    $('#blMoreWrap').prop('hidden', true);
                    return;
                }

                $('#blList').append(rows.map(renderRow).join(''));
                $('#blMoreWrap').prop('hidden', currentPage >= lastPage);
            }).fail(function () {
                loading = false;
                $('#blMore').prop('disabled', false);
                if (nextPage === 1) {
                    $('#blList').html('<div class="bl-state"><i class="fa fa-exclamation-circle"></i>网络异常，请稍后重试</div>');
                } else {
                    layer.msg('网络异常');
                }
            });
        }

        $('#blMore').on('click', function () {
            load(currentPage + 1);
        });

        <?php if ($logged): ?>
        load(1);
        <?php endif; ?>
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
