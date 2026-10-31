<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var bool $officialLoggedIn 是否已登录官方账号 */

$pageTitle = '代理配置';
$logged    = !empty($officialLoggedIn);

include __DIR__ . '/header.php';
?>

<?php if (!$logged): ?>
<div class="popup-inner ag-wrap">
    <div class="ag-state"><i class="fa fa-user-circle-o"></i>尚未登录官方账号</div>
</div>
<?php else: ?>
<div class="popup-inner ag-wrap">
    <div class="form-tips">这里的修改会直接保存到官方站，本站不保存副本；保存时需要验证当前账号的登录密码。</div>

    <div class="ag-section">
        <div class="ag-section__title"><i class="fa fa-id-badge"></i>身份标识</div>
        <div class="form-group">
            <input type="text" class="form-group__input" id="agIdentity" maxlength="64" autocomplete="off" placeholder="字母、数字、减号、下划线">
            <p class="form-group__help">给外部系统认这个账号用的标识，只能用字母、数字、减号和下划线。</p>
        </div>
    </div>

    <div class="ag-section">
        <div class="ag-section__title"><i class="fa fa-phone"></i>联系方式</div>
        <div class="ag-fields">
            <div class="form-group">
                <label class="form-group__label" for="agQqService">QQ 客服</label>
                <input type="text" class="form-group__input" id="agQqService" maxlength="32" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-group__label" for="agQqGroup">QQ 群号码</label>
                <input type="text" class="form-group__input" id="agQqGroup" maxlength="32" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-group__label" for="agWechatService">微信客服</label>
                <input type="text" class="form-group__input" id="agWechatService" maxlength="64" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-group__label" for="agWechatQr">微信二维码</label>
                <input type="text" class="form-group__input" id="agWechatQr" maxlength="255" autocomplete="off" placeholder="图片地址">
            </div>
            <div class="form-group">
                <label class="form-group__label" for="agTgService">Tg 客服</label>
                <input type="text" class="form-group__input" id="agTgService" maxlength="64" autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-group__label" for="agTgGroupUrl">Tg 群链接</label>
                <input type="text" class="form-group__input" id="agTgGroupUrl" maxlength="255" autocomplete="off" placeholder="https://t.me/...">
            </div>
        </div>
    </div>

    <div class="ag-section">
        <div class="ag-section__title"><i class="fa fa-link"></i>EMSHOP 链接</div>
        <div class="form-group">
            <label class="form-group__label" for="agBuyLinks">购买链接</label>
            <textarea class="form-group__textarea" id="agBuyLinks" placeholder="一行一条：名称|链接&#10;正式版|https://shop.example.com/em"></textarea>
        </div>
        <div class="form-group">
            <label class="form-group__label" for="agDownloadLinks">下载链接</label>
            <textarea class="form-group__textarea" id="agDownloadLinks" placeholder="一行一条：名称|链接&#10;正式版|https://shop.example.com/em/download"></textarea>
        </div>
    </div>

    <div class="ag-section">
        <div class="ag-section__title"><i class="fa fa-lock"></i>安全验证</div>
        <div class="form-group">
            <label class="form-group__label" for="agPassword">登录密码 <span class="ag-req">*</span></label>
            <input type="password" class="form-group__input" id="agPassword" autocomplete="current-password" placeholder="保存前请输入当前账号的登录密码">
            <p class="form-group__help">用于确认是本人操作；密码只参与校验，不会被保存。</p>
            <div class="form-group__error" id="agError"></div>
        </div>
    </div>
</div>

<div class="popup-footer">
    <button type="button" class="popup-btn popup-btn--default" id="agCancel">取消</button>
    <button type="button" class="popup-btn popup-btn--primary" id="agSave">保存</button>
</div>
<?php endif; ?>

<style>
.ag-wrap { padding: 16px; }

.ag-section {
    margin-bottom: 12px;
    padding: 14px 16px;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 14px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.ag-section:last-child { margin-bottom: 0; }
.ag-section__title {
    display: flex; align-items: center; gap: 7px;
    margin-bottom: 12px;
    font-size: 13.5px; font-weight: 600; color: #111827;
}
.ag-section__title i { color: #4f46e5; font-size: 13px; }

.ag-section .form-group { margin-bottom: 12px; }
.ag-section .form-group:last-child { margin-bottom: 0; }

.ag-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0 14px;
}
.ag-fields .form-group { margin-bottom: 12px; }

.ag-req { color: #e11d48; }

.ag-state {
    padding: 44px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
}
.ag-state i {
    display: block; margin-bottom: 8px;
    font-size: 26px; color: #d1d5db;
}

@media (max-width: 600px) {
    .ag-wrap { padding: 12px; }
    .ag-fields { grid-template-columns: 1fr; }
}
</style>

<script>
$(function () {
    layui.use(['layer'], function () {
        var layer = layui.layer;
        var csrfToken = <?= json_encode($csrfToken) ?>;

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

        function showError(msg) {
            $('#agError').text(msg).addClass('is-visible');
        }

        function clearError() {
            $('#agError').removeClass('is-visible').text('');
        }

        function fill(config) {
            var cfg = config || {};
            $('#agIdentity').val(cfg.identity || '');
            $('#agQqService').val(cfg.qq_service || '');
            $('#agQqGroup').val(cfg.qq_group || '');
            $('#agWechatService').val(cfg.wechat_service || '');
            $('#agWechatQr').val(cfg.wechat_qr || '');
            $('#agTgService').val(cfg.tg_service || '');
            $('#agTgGroupUrl').val(cfg.tg_group_url || '');
            $('#agBuyLinks').val(cfg.em_buy_links || '');
            $('#agDownloadLinks').val(cfg.em_download_links || '');
        }

        function load() {
            post({ _action: 'official_agent_config' }).done(function (res) {
                if (!res || res.code !== 200) {
                    showError((res && res.msg) || '获取配置失败');
                    return;
                }
                fill((res.data && res.data.config) || {});
            }).fail(function () {
                showError('网络异常，请稍后重试');
            });
        }

        function closeSelf() {
            var p = window.parent;
            try {
                if (p && p !== window && p.layer && typeof p.layer.getFrameIndex === 'function') {
                    p.layer.close(p.layer.getFrameIndex(window.name));
                    return;
                }
            } catch (e) {}
            if (history.length > 1) { history.back(); }
        }

        $('#agCancel').on('click', closeSelf);

        $('#agSave').on('click', function () {
            var $btn = $(this);
            if ($btn.prop('disabled')) return;

            var data = {
                _action: 'official_agent_config_save',
                identity: $.trim(String($('#agIdentity').val() || '')),
                qq_service: $.trim(String($('#agQqService').val() || '')),
                qq_group: $.trim(String($('#agQqGroup').val() || '')),
                wechat_service: $.trim(String($('#agWechatService').val() || '')),
                wechat_qr: $.trim(String($('#agWechatQr').val() || '')),
                tg_service: $.trim(String($('#agTgService').val() || '')),
                tg_group_url: $.trim(String($('#agTgGroupUrl').val() || '')),
                em_buy_links: String($('#agBuyLinks').val() || ''),
                em_download_links: String($('#agDownloadLinks').val() || ''),
                password: String($('#agPassword').val() || '')
            };

            if (data.identity === '') {
                showError('请输入身份标识');
                $('#agIdentity').trigger('focus');
                return;
            }
            if (data.password === '') {
                showError('请输入登录密码');
                $('#agPassword').trigger('focus');
                return;
            }

            clearError();
            $btn.prop('disabled', true).text('保存中…');

            post(data).done(function (res) {
                $btn.prop('disabled', false).text('保存');
                if (!res || res.code !== 200) {
                    showError((res && res.msg) || '保存失败');
                    return;
                }

                fill((res.data && res.data.config) || {});
                $('#agPassword').val('');
                layer.msg('已保存');
            }).fail(function () {
                $btn.prop('disabled', false).text('保存');
                showError('网络异常，请稍后重试');
            });
        });

        <?php if ($logged): ?>
        load();
        <?php endif; ?>
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
