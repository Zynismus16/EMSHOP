<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
?>
<div class="uc-page">
    <div class="uc-page-header">
        <h2 class="uc-page-title">我的钱包</h2>
        <p class="uc-page-desc">查看余额、充值与提现</p>
    </div>

    <!-- 余额卡片 -->
    <div class="uc-wallet-hero uc-glass-card">
        <div class="uc-wallet-hero__glow" aria-hidden="true"></div>
        <div class="uc-wallet-hero__inner">
            <div class="uc-wallet-hero__main">
                <div class="uc-wallet-hero__icon"><i class="fa fa-credit-card"></i></div>
                <div class="uc-wallet-hero__info">
                    <span class="uc-wallet-hero__label">账户余额</span>
                    <div class="uc-wallet-hero__amount"><?= Currency::displayAmount((int) ($frontUser['money'] ?? 0)) ?></div>
                    <p class="uc-wallet-hero__desc">可用余额 · 支持在线充值与提现到收款账户</p>
                </div>
            </div>
            <div class="uc-wallet-hero__actions">
                <button type="button" class="uc-wallet-hero__btn uc-wallet-hero__btn--primary" id="rechargeBtn">
                    <i class="fa fa-plus-circle"></i> 充值
                </button>
                <button type="button" class="uc-wallet-hero__btn" id="withdrawBtn">
                    <i class="fa fa-sign-out"></i> 提现
                </button>
            </div>
        </div>
    </div>

    <!-- 规则提示 -->
    <div class="uc-wallet-tips">
        <div class="uc-wallet-tip">
            <i class="fa fa-info-circle"></i>
            单次充值范围：<?= htmlspecialchars($currencySymbol . $displayMinRecharge) ?> ~ <?= htmlspecialchars($currencySymbol . $displayMaxRecharge) ?>
        </div>
        <div class="uc-wallet-tip">
            <i class="fa fa-info-circle"></i>
            单次提现范围：<?= htmlspecialchars($currencySymbol . $displayMinWithdraw) ?> ~ <?= htmlspecialchars($currencySymbol . $displayMaxWithdraw) ?>
        </div>
    </div>

    <!-- 最近明细 -->
    <div class="uc-section">
        <div class="uc-section-title">
            最近变动
            <a href="/user/balance_log.php" data-pjax="#userContent" class="uc-section-link">查看全部 &rarr;</a>
        </div>

        <?php if (!empty($recentLogs)): ?>
        <div class="uc-form-card" style="padding:0; overflow:hidden;">
            <table class="uc-table">
                <thead>
                    <tr>
                        <th>时间</th>
                        <th>类型</th>
                        <th>金额</th>
                        <th>变动后</th>
                        <th>备注</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td class="uc-table-time"><?= htmlspecialchars(substr((string) $log['created_at'], 0, 19)) ?></td>
                        <td>
                            <?php if ($log['type'] === 'increase'): ?>
                            <span class="uc-badge uc-badge--green">收入</span>
                            <?php else: ?>
                            <span class="uc-badge uc-badge--red">支出</span>
                            <?php endif; ?>
                        </td>
                        <td class="<?= $log['type'] === 'increase' ? 'uc-text-green' : 'uc-text-red' ?>">
                            <?= $log['type'] === 'increase' ? '+' : '-' ?><?= Currency::displayAmount((int) $log['amount']) ?>
                        </td>
                        <td><?= Currency::displayAmount((int) $log['after_balance']) ?></td>
                        <td class="uc-table-remark"><?= htmlspecialchars($log['remark'] ?: '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="uc-empty">
            <i class="fa fa-inbox"></i>
            <p>暂无余额变动记录</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
// 把后端数据交给 JS（避免字符串拼接和 XSS）
$walletJsCtx = [
    'currency'    => $currencySymbol,
    'csrfToken'   => Csrf::token(),
    'minRecharge' => (float) $displayMinRecharge,
    'maxRecharge' => (float) $displayMaxRecharge,
    'minWithdraw' => (float) $displayMinWithdraw,
    'maxWithdraw' => (float) $displayMaxWithdraw,
    'balance'     => (float) $displayMoney,
    'methods'     => array_map(static fn($m) => [
        'code'  => (string) ($m['code'] ?? ''),
        'name'  => (string) ($m['display_name'] ?? $m['name'] ?? ''),
        'image' => (string) ($m['image'] ?? ''),
    ], $paymentMethods),
];
?>
<script>
(function () {
    var CTX = <?= json_encode($walletJsCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    $(document).off('click.wallet');

    function esc(s){ var d=document.createElement('div'); d.textContent=(s==null?'':s); return d.innerHTML; }

    // 界面上的金额（输入框、区间提示、余额）都是「访客展示币种」的数值，
    // 而后端统一按主货币解释并校验 —— 所以提交前必须换回主货币。
    // rate 的含义是「1 主货币 = N 访客币」，见 Currency::visitorFactor()。
    // 访客币种 = 主货币时 rate 为 1，行为与改动前完全一致。
    function toPrimaryAmount(v) {
        var rate = (window.EMSHOP_CURRENCY && window.EMSHOP_CURRENCY.rate) || 1;
        if (!(rate > 0)) { rate = 1; }
        // 保留 6 位小数（微单位精度），避免浮点尾数超出后端白名单
        return Math.round((v / rate) * 1e6) / 1e6;
    }

    // ===== 充值 =====
    $(document).on('click.wallet', '#rechargeBtn', function () {
        if (!CTX.methods.length) {
            layui.layer.msg('未启用任何在线支付，请先在后台启用支付插件');
            return;
        }
        var methodsHtml = '<div class="wallet-modal__methods">';
        CTX.methods.forEach(function (m, i) {
            methodsHtml += '<label class="wallet-modal__method">'
                + '<input type="radio" name="payment_code" value="' + esc(m.code) + '"' + (i === 0 ? ' checked' : '') + '>'
                + (m.image ? '<img src="' + esc(m.image) + '" alt="">' : '')
                + '<span>' + esc(m.name) + '</span>'
                + '</label>';
        });
        methodsHtml += '</div>';

        var html = '<form class="wallet-modal" onsubmit="return false;">'
            + '<div class="wallet-modal__row">'
            +   '<label class="wallet-modal__label">充值金额</label>'
            +   '<div class="wallet-modal__input-wrap">'
            +     '<span class="wallet-modal__prefix">' + esc(CTX.currency) + '</span>'
            +     '<input type="number" class="wallet-modal__input" name="amount" min="' + CTX.minRecharge + '" max="' + CTX.maxRecharge + '" step="0.01" placeholder="请输入金额" autofocus>'
            +   '</div>'
            +   '<div class="wallet-modal__hint">单次范围 ' + esc(CTX.currency) + CTX.minRecharge.toFixed(2) + ' ~ ' + esc(CTX.currency) + CTX.maxRecharge.toFixed(2) + '</div>'
            + '</div>'
            + '<div class="wallet-modal__row">'
            +   '<label class="wallet-modal__label">支付方式</label>'
            +   methodsHtml
            + '</div>'
            + '</form>';

        layui.layer.open({
            type: 1, title: '账户充值', area: ['420px', 'auto'], shadeClose: true,
            content: html, btn: ['确认充值', '取消'], btnAlign: 'c',
            yes: function (idx) {
                var $f = $('.wallet-modal');
                var amt = parseFloat($f.find('[name=amount]').val() || '0');
                if (!(amt > 0)) { layui.layer.msg('请输入金额'); return; }
                if (amt < CTX.minRecharge || amt > CTX.maxRecharge) {
                    layui.layer.msg('金额超出允许范围'); return;
                }
                var payCode = $f.find('[name=payment_code]:checked').val() || '';
                if (!payCode) { layui.layer.msg('请选择支付方式'); return; }
                var loadIdx = layui.layer.load(2);
                $.post('/?c=recharge&a=create', { amount: toPrimaryAmount(amt), payment_code: payCode }, function (res) {
                    layui.layer.close(loadIdx);
                    if (res.code === 200 && res.data && res.data.pay_url) {
                        layui.layer.close(idx);
                        location.href = res.data.pay_url;
                    } else {
                        layui.layer.msg(res.msg || '创建充值订单失败');
                    }
                }, 'json').fail(function () {
                    layui.layer.close(loadIdx);
                    layui.layer.msg('网络错误，请重试');
                });
            }
        });
    });

    // ===== 提现 =====
    $(document).on('click.wallet', '#withdrawBtn', function () {
        if (CTX.balance <= 0) { layui.layer.msg('余额为 0，无可提现金额'); return; }

        var html = '<form class="wallet-modal" onsubmit="return false;">'
            + '<div class="wallet-modal__row">'
            +   '<label class="wallet-modal__label">提现金额</label>'
            +   '<div class="wallet-modal__input-wrap">'
            +     '<span class="wallet-modal__prefix">' + esc(CTX.currency) + '</span>'
            +     '<input type="number" class="wallet-modal__input" name="amount" min="' + CTX.minWithdraw + '" max="' + CTX.maxWithdraw + '" step="0.01" placeholder="请输入金额">'
            +   '</div>'
            +   '<div class="wallet-modal__hint">单次范围 ' + esc(CTX.currency) + CTX.minWithdraw.toFixed(2) + ' ~ ' + esc(CTX.currency) + CTX.maxWithdraw.toFixed(2) + '，当前余额 ' + esc(CTX.currency) + CTX.balance.toFixed(2) + '</div>'
            + '</div>'
            + '<div class="wallet-modal__row">'
            +   '<label class="wallet-modal__label">收款方式</label>'
            +   '<div class="wallet-modal__channels">'
            +     '<label class="wallet-modal__channel"><input type="radio" name="channel" value="alipay" checked> 支付宝</label>'
            +     '<label class="wallet-modal__channel"><input type="radio" name="channel" value="wxpay"> 微信</label>'
            +     '<label class="wallet-modal__channel"><input type="radio" name="channel" value="bank"> 银行卡</label>'
            +   '</div>'
            + '</div>'
            + '<div class="wallet-modal__row">'
            +   '<label class="wallet-modal__label">收款人姓名</label>'
            +   '<input type="text" class="wallet-modal__input wallet-modal__input--full" name="account_name" placeholder="请输入真实姓名">'
            + '</div>'
            + '<div class="wallet-modal__row">'
            +   '<label class="wallet-modal__label">收款账号</label>'
            +   '<input type="text" class="wallet-modal__input wallet-modal__input--full" name="account_no" placeholder="支付宝 / 微信 / 银行卡号">'
            + '</div>'
            + '<div class="wallet-modal__row wallet-modal__row--bank" style="display:none;">'
            +   '<label class="wallet-modal__label">开户行</label>'
            +   '<input type="text" class="wallet-modal__input wallet-modal__input--full" name="bank_name" placeholder="如 中国工商银行 朝阳支行">'
            + '</div>'
            + '</form>';

        layui.layer.open({
            type: 1, title: '余额提现', area: ['460px', 'auto'], shadeClose: true,
            content: html, btn: ['提交申请', '取消'], btnAlign: 'c',
            success: function () {
                $(document).off('change.walletWd').on('change.walletWd', '.wallet-modal [name=channel]', function () {
                    $('.wallet-modal__row--bank').toggle($(this).val() === 'bank');
                });
            },
            yes: function (idx) {
                var $f = $('.wallet-modal');
                // 输入与区间/余额校验都按「访客展示币种」进行（与界面提示口径一致），
                // 提交前再把金额换回主货币 —— 后端统一按主货币解释与校验。
                // 此前是直接把访客币种数值当主货币提交，外币访客会差一个汇率倍数。
                var amtInput = parseFloat($f.find('[name=amount]').val() || '0');
                var payload = {
                    csrf_token:   CTX.csrfToken,
                    amount:       toPrimaryAmount(amtInput),
                    channel:      $f.find('[name=channel]:checked').val() || '',
                    account_name: $.trim($f.find('[name=account_name]').val() || ''),
                    account_no:   $.trim($f.find('[name=account_no]').val() || ''),
                    bank_name:    $.trim($f.find('[name=bank_name]').val() || ''),
                };
                if (!(amtInput > 0)) { layui.layer.msg('请输入金额'); return; }
                if (amtInput < CTX.minWithdraw || amtInput > CTX.maxWithdraw) {
                    layui.layer.msg('金额超出允许范围'); return;
                }
                if (amtInput > CTX.balance) { layui.layer.msg('余额不足'); return; }
                if (!payload.account_name) { layui.layer.msg('请填写收款人姓名'); return; }
                if (!payload.account_no)   { layui.layer.msg('请填写收款账号'); return; }
                if (payload.channel === 'bank' && !payload.bank_name) { layui.layer.msg('请填写开户行'); return; }

                var loadIdx = layui.layer.load(2);
                $.post('/?c=withdraw&a=create', payload, function (res) {
                    layui.layer.close(loadIdx);
                    if (res.code === 200) {
                        layui.layer.close(idx);
                        layui.layer.msg(res.msg || '申请已提交');
                        setTimeout(function(){ location.reload(); }, 800);
                    } else {
                        layui.layer.msg(res.msg || '提交失败');
                    }
                }, 'json').fail(function () {
                    layui.layer.close(loadIdx);
                    layui.layer.msg('网络错误，请重试');
                });
            }
        });
    });
})();
</script>
