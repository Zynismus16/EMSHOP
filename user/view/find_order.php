<?php
defined('EM_ROOT') || exit('access denied!');

// ===== 查单页 tab 路由（PJAX 模式）=====
// 由 user/find_order.php 注入：$currentTab / $contactOn / $passwordOn / $captchaExpr / $guestToken / $currencySymbol
// tab 改成 <a data-pjax> 链接：
//   /user/find_order.php          → token（默认）
//   /user/find_order.php?tab=credentials
//   /user/find_order.php?tab=orderno
// 切 tab 走 PJAX 替换 #foContent；刷新页面停在当前 tab；浏览器前进后退也能用

// tab2 名称按实际开启的凭据组合
if ($contactOn && $passwordOn) {
    $credLabel = '联系方式/订单密码查单';
} elseif ($contactOn) {
    $credLabel = '联系方式查单';
} elseif ($passwordOn) {
    $credLabel = '订单密码查单';
} else {
    $credLabel = '';
}
$showCredTab = ($contactOn || $passwordOn);
$showOrderNoTab = ($contactOn || $passwordOn);
?>
<div class="find-order-layout">

    <!-- 查询方式 tab：每个 tab 是独立 URL，点击走 PJAX 替换 #foContent -->
    <div class="find-order-tabs" id="findOrderTabs">
        <a href="/user/find_order.php" data-pjax
           class="find-order-tab<?= $currentTab === 'token' ? ' active' : '' ?>">
            <i class="fa fa-user"></i> 浏览器订单
        </a>
        <?php if ($showCredTab): ?>
        <a href="/user/find_order.php?tab=credentials" data-pjax
           class="find-order-tab<?= $currentTab === 'credentials' ? ' active' : '' ?>">
            <i class="fa fa-id-card"></i> <?= htmlspecialchars($credLabel) ?>
        </a>
        <?php endif; ?>
        <?php if ($showOrderNoTab): ?>
        <a href="/user/find_order.php?tab=orderno" data-pjax
           class="find-order-tab<?= $currentTab === 'orderno' ? ' active' : '' ?>">
            <i class="fa fa-file-text-o"></i> 订单编号查单
        </a>
        <?php endif; ?>
    </div>

    <?php if ($currentTab === 'token'): ?>
    <!-- 浏览器订单：仅当前 tab 才渲染 -->
    <div class="find-order-panel" id="panelToken">
        <div class="find-order-hint">
            <i class="fa fa-info-circle"></i> 正在从浏览器获取订单记录...
        </div>
        <div class="find-order-loading" id="tokenLoading">
            <div class="find-order-spinner"></div>
            正在加载订单...
        </div>
    </div>
    <?php endif; ?>

    <?php if ($currentTab === 'credentials' && $showCredTab): ?>
    <!-- 凭据查单 -->
    <div class="find-order-panel" id="panelCredentials">
        <div class="find-order-form">
            <?php if ($contactOn): ?>
            <div class="find-order-form-group">
                <label><?= htmlspecialchars($gfConfig['contact_type_label']) ?></label>
                <input type="<?= $gfConfig['contact_input_type'] ?>" class="find-order-input" id="credContactQuery"
                       placeholder="<?= htmlspecialchars($gfConfig['contact_lookup_placeholder']) ?>" maxlength="32">
            </div>
            <?php endif; ?>
            <?php if ($passwordOn): ?>
            <div class="find-order-form-group">
                <label>订单密码</label>
                <input type="text" class="find-order-input" id="credPasswordQuery"
                       placeholder="<?= htmlspecialchars($gfConfig['password_lookup_placeholder']) ?>" maxlength="32">
            </div>
            <?php endif; ?>
            <div class="find-order-form-group fo-captcha-row">
                <label>验证码</label>
                <div class="fo-captcha">
                    <span class="fo-captcha__expr"><?= htmlspecialchars($captchaExpr) ?> = ?</span>
                    <input type="text" class="find-order-input fo-captcha__input"
                           placeholder="算出结果" maxlength="2" inputmode="numeric" autocomplete="off">
                    <button type="button" class="fo-captcha__refresh" title="换一题">
                        <i class="fa fa-refresh"></i>
                    </button>
                </div>
            </div>
            <button type="button" class="find-order-submit" id="credentialsSubmitBtn">
                <i class="fa fa-search"></i> 查询订单
            </button>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($currentTab === 'orderno' && $showOrderNoTab): ?>
    <!-- 仅订单编号查单 -->
    <div class="find-order-panel" id="panelOrderNo">
        <div class="find-order-form">
            <div class="find-order-form-group">
                <label>订单编号</label>
                <input type="text" class="find-order-input" id="orderNoInput"
                       placeholder="请输入订单编号" maxlength="32">
            </div>
            <div class="find-order-form-group fo-captcha-row">
                <label>验证码</label>
                <div class="fo-captcha">
                    <span class="fo-captcha__expr"><?= htmlspecialchars($captchaExpr) ?> = ?</span>
                    <input type="text" class="find-order-input fo-captcha__input"
                           placeholder="算出结果" maxlength="2" inputmode="numeric" autocomplete="off">
                    <button type="button" class="fo-captcha__refresh" title="换一题">
                        <i class="fa fa-refresh"></i>
                    </button>
                </div>
            </div>
            <button type="button" class="find-order-submit" id="orderNoSubmitBtn">
                <i class="fa fa-search"></i> 查询订单
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- 查询结果容器（GuestFind.renderResults 写入） -->
    <div class="find-order-results" id="findOrderResults" style="display:none;">
        <div class="find-order-results-header">
            <i class="fa fa-check-circle" style="color:#52c41a;"></i>
            查询到 <strong id="resultsCount">0</strong> 条订单
        </div>
        <div id="resultsList"></div>
    </div>

</div>

<script>
/**
 * 注意：本块脚本会随 PJAX 替换重复执行。
 * 防重复绑定的策略：
 *   - 所有 $(document).on(...) 都用命名空间 + 先 .off(命名空间) 再 .on
 *   - GuestFind.init() 内部已经做了 $doc.off('.findGuestFind')
 * 不要把 handler 直接绑到 .find-order-tab 等元素上（DOM 替换后旧绑定会丢失，新元素拿不到事件）
 */
(function () {
    window.findOrderCsrfToken = <?= json_encode(Csrf::token(), JSON_UNESCAPED_UNICODE) ?>;
    var currentTab = <?= json_encode($currentTab) ?>;
    var guestToken = <?= json_encode($guestToken ?: '') ?>;
    var currencySymbol = <?= json_encode($currencySymbol) ?>;

    // ============ Captcha 处理（仅 credentials/orderno tab 用到）============
    function readCaptcha() {
        return ($('.fo-captcha__input').val() || '').trim();
    }
    function setCaptchaExpr(expr) {
        $('.fo-captcha__expr').text((expr || '') + ' = ?');
        $('.fo-captcha__input').val('');
    }

    // 换一题：服务端签发新 captcha，前端同步显示
    // 用命名空间 + off 先解绑，避免 PJAX 重新执行本脚本时累积 handler
    $(document).off('click.foCaptchaRefresh').on('click.foCaptchaRefresh', '.fo-captcha__refresh', function () {
        var $btn = $(this);
        $btn.find('i').addClass('fa-spin');
        $.post('/user/find_order.php', { action: 'refresh_captcha' }, function (res) {
            if (res && res.code === 200 && res.data && res.data.expr) {
                setCaptchaExpr(res.data.expr);
            }
        }, 'json').always(function () {
            $btn.find('i').removeClass('fa-spin');
        });
    });

    GuestFind.init({
        currencySymbol: currencySymbol,
        beforeSubmit: function (data, mode) {
            // 只有需要 captcha 的模式才校验
            if (mode === 'credentials' || mode === 'orderno') {
                var captcha = readCaptcha();
                if (!captcha) {
                    layer.msg('请填写验证码');
                    return false;
                }
                data.captcha = captcha;
            }
            return data;
        },
        // 后端在错误响应里附带新 captcha_expr，前端同步替换显示
        onError: function (res) {
            if (res && res.data && res.data.captcha_expr) {
                setCaptchaExpr(res.data.captcha_expr);
            } else if (res && res.code === 422) {
                // 未查到订单（422）：body 正常解析时由上面的 captcha_expr 处理；
                // 这里兜底 body 无法解析的情况，主动换一道新验证码
                $('.fo-captcha__refresh').trigger('click');
            }
        }
    });

    // ============ token tab：自动加载浏览器订单 ============
    if (currentTab === 'token') {
        if (!guestToken) {
            $('#tokenLoading').hide();
            $('#panelToken .find-order-hint').hide();
            $('#panelToken').append(
                '<div class="find-order-empty">未找到浏览器订单记录<br>'
              + '<small>请使用其他方式查询订单</small></div>'
            );
        } else {
            function renderTokenResult(res) {
                $('#tokenLoading').hide();
                $('#panelToken .find-order-hint').hide();
                if (res && res.code === 200 && res.data && res.data.length > 0) {
                    GuestFind.renderResults(res.data, { autoScroll: false, meta: res.meta });
                } else {
                    $('#panelToken').append(
                        '<div class="find-order-empty">未找到浏览器订单记录<br>'
                      + '<small>请确认您之前在此浏览器下过单</small></div>'
                    );
                }
            }
            $.post('/user/find_order.php', { mode: 'token', guest_token: guestToken }, function (res) {
                renderTokenResult(res);
            }, 'json').fail(function (jqXHR) {
                // 未查到订单返回 422，body 仍是业务错误结构；命中就直接渲染“未找到”
                if (jqXHR && jqXHR.responseJSON) {
                    renderTokenResult(jqXHR.responseJSON);
                    return;
                }
                if (jqXHR && jqXHR.status === 422) {
                    // 未查到订单（422）；兜底 body 无法解析的情况
                    renderTokenResult({code: 422});
                    return;
                }
                $('#tokenLoading').hide();
                $('#panelToken .find-order-hint').hide();
                $('#panelToken').append('<div class="find-order-empty">加载失败，请刷新重试</div>');
            });
        }
    }
})();
</script>
