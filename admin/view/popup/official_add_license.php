<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var bool $officialLoggedIn 是否已登录官方账号 */

$pageTitle = '添加激活码';
$logged    = !empty($officialLoggedIn);

include __DIR__ . '/header.php';
?>

<?php if (!$logged): ?>
<div class="popup-inner add-wrap">
    <div class="add-state"><i class="fa fa-user-circle-o"></i>尚未登录官方账号</div>
</div>
<?php else: ?>
<div class="popup-inner add-wrap">
    <div class="form-tips">输入要绑定的激活码，绑定后归到当前账号名下。已被其他账号绑定的激活码无法添加。</div>
    <div class="form-group">
        <label class="form-group__label" for="addCode">激活码</label>
        <input type="text" class="form-group__input" id="addCode" placeholder="请输入激活码" maxlength="64" autocomplete="off" spellcheck="false">
        <div class="form-group__error" id="addError"></div>
    </div>
</div>

<div class="popup-footer">
    <button type="button" class="popup-btn popup-btn--default" id="addCancel">取消</button>
    <button type="button" class="popup-btn popup-btn--primary" id="addSubmit">绑定</button>
</div>
<?php endif; ?>

<style>
.add-wrap { padding: 16px; }
.add-state {
    padding: 44px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
}
.add-state i {
    display: block; margin-bottom: 8px;
    font-size: 26px; color: #d1d5db;
}
.add-wrap .form-group { margin-bottom: 0; }
.add-wrap .form-group__input { height: 38px; }
</style>

<script>
$(function () {
    $('#addCode').trigger('focus');

    function showError(msg) {
        $('#addError').text(msg).addClass('is-visible');
    }

    function closeSelf() {
        var p = window.parent;
        try {
            if (p && p !== window && p.layer && typeof p.layer.getFrameIndex === 'function') {
                var idx = p.layer.getFrameIndex(window.name);
                p.layer.close(idx);
                return;
            }
        } catch (e) {}
        /* 不是被弹窗打开的（直连访问）—— 退化成浏览器返回 */
        if (history.length > 1) { history.back(); }
    }

    $('#addCancel').on('click', closeSelf);

    function submit() {
        var $btn = $('#addSubmit');
        if ($btn.prop('disabled')) return;

        var code = $.trim(String($('#addCode').val() || ''));
        if (code === '') {
            showError('请输入激活码');
            $('#addCode').trigger('focus');
            return;
        }

        $('#addError').removeClass('is-visible').text('');
        $btn.prop('disabled', true).text('绑定中…');

        $.ajax({
            url: '/admin/license.php',
            type: 'POST',
            dataType: 'json',
            data: {
                _action: 'official_add_license',
                code: code,
                csrf_token: <?= json_encode($csrfToken) ?>
            }
        }).done(function (res) {
            if (!res || res.code !== 200) {
                $btn.prop('disabled', false).text('绑定');
                showError((res && res.msg) || '绑定失败');
                return;
            }

            /* 成功：通知宫格页刷新数量，再关掉自己 */
            try {
                var p = window.parent;
                if (p && p !== window && typeof p.__oaAfterAddLicense === 'function') {
                    p.__oaAfterAddLicense();
                }
            } catch (e) {}

            closeSelf();
        }).fail(function () {
            $btn.prop('disabled', false).text('绑定');
            showError('网络异常，请稍后重试');
        });
    }

    $('#addSubmit').on('click', submit);
    $('#addCode').on('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            submit();
        }
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
