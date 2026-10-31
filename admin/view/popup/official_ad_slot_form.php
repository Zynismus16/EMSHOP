<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var bool $officialLoggedIn 是否已登录官方账号 */
/** @var int $slotId 0 = 新建，>0 = 编辑 */

$slotId    = (int) ($slotId ?? 0);
$pageTitle = $slotId > 0 ? '编辑广告位' : '新建广告位';
$logged    = !empty($officialLoggedIn);

include __DIR__ . '/header.php';
?>

<div class="popup-inner asf-wrap">
<?php if (!$logged): ?>
    <div class="asf-state"><i class="fa fa-user-circle-o"></i>尚未登录官方账号</div>
<?php else: ?>
    <div class="form-tips">这里的广告位只属于你自己。到期后只是不再展示，改到期日就能续上。</div>

    <input type="hidden" id="asfId" value="<?= (int) $slotId ?>">

    <div class="asf-row">
        <div class="form-group asf-grow">
            <label class="form-group__label" for="asfTitle">名称 <span class="asf-req">*</span></label>
            <input type="text" class="form-group__input" id="asfTitle" maxlength="100"
                   placeholder="例如：首页轮播广告" autocomplete="off">
        </div>
        <div class="form-group asf-status">
            <label class="form-group__label" for="asfStatus">状态</label>
            <select class="form-group__input" id="asfStatus">
                <option value="1">启用</option>
                <option value="0">停用</option>
            </select>
        </div>
    </div>

    <div class="asf-row">
        <div class="form-group">
            <label class="form-group__label" for="asfLabel">标签</label>
            <input type="text" class="form-group__input" id="asfLabel" maxlength="20"
                   placeholder="例如：活动" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-group__label" for="asfColor">标签颜色</label>
            <input type="text" class="form-group__input asf-mono" id="asfColor" maxlength="7"
                   placeholder="#2563EB" autocomplete="off">
        </div>
        <div class="form-group">
            <label class="form-group__label" for="asfExpires">到期日 <span class="asf-req">*</span></label>
            <input type="date" class="form-group__input" id="asfExpires">
        </div>
    </div>

    <div class="form-group">
        <label class="form-group__label" for="asfLink">跳转链接</label>
        <input type="text" class="form-group__input" id="asfLink" maxlength="500"
               placeholder="https://example.com/promo 或 /events" autocomplete="off">
    </div>

    <div class="form-group">
        <label class="form-group__label" for="asfContent">正文</label>
        <textarea class="form-group__input asf-textarea" id="asfContent" rows="5"
                  placeholder="支持常见 HTML 标签；保存时会按白名单过滤"></textarea>
    </div>

    <div class="form-group__error" id="asfError"></div>
<?php endif; ?>
</div>

<?php if ($logged): ?>
<div class="popup-footer">
    <button type="button" class="popup-btn popup-btn--default" id="asfCancel">取消</button>
    <button type="button" class="popup-btn popup-btn--primary" id="asfSave">保存</button>
</div>
<?php endif; ?>

<style>
.asf-wrap { padding: 16px; }
.asf-state {
    padding: 60px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
}
.asf-state i {
    display: block; margin-bottom: 8px;
    font-size: 26px; color: #d1d5db;
}
.asf-row { display: flex; flex-wrap: wrap; gap: 12px; }
.asf-row .form-group { flex: 1 1 150px; min-width: 0; }
.asf-grow { flex: 1 1 220px; }
.asf-status { flex: 0 0 100px; }
.asf-req { color: #e11d48; }
.asf-mono { font-family: Menlo, Consolas, Monaco, monospace; }
.asf-wrap .form-group { margin-bottom: 12px; }
.asf-textarea {
    height: auto; min-height: 100px;
    padding: 8px 10px; line-height: 1.5;
    resize: vertical;
}

@media (max-width: 600px) {
    .asf-wrap { padding: 12px; }
    .asf-status { flex: 1 1 90px; }
}
</style>

<script>
$(function () {
    layui.use(['layer'], function () {
        var layer = layui.layer;
        var csrfToken = <?= json_encode($csrfToken) ?>;
        var slotId = <?= (int) $slotId ?>;
        var editing = slotId > 0;

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
            $('#asfError').text(msg).addClass('is-visible');
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
            if (history.length > 1) { history.back(); }
        }

        function pad(n) { return n < 10 ? ('0' + n) : String(n); }

        function addMonths(months) {
            var d = new Date();
            var day = d.getDate();

            d.setDate(1);
            d.setMonth(d.getMonth() + months);

            var last = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
            d.setDate(Math.min(day, last));

            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        }

        function fill(slot) {
            $('#asfTitle').val(slot.title || '');
            $('#asfLabel').val(slot.label || '');
            $('#asfColor').val(slot.label_color || '');
            $('#asfLink').val(slot.link_url || '');
            $('#asfContent').val(slot.content || '');
            $('#asfExpires').val(slot.expires_on || addMonths(1));
            $('#asfStatus').val(String(Number(slot.status) === 0 ? 0 : 1));
            $('#asfError').removeClass('is-visible').text('');
            $('#asfSave').prop('disabled', false).text('保存');
        }

        if (editing) {
            $('#asfSave').prop('disabled', true).text('加载中…');
            $('#asfExpires').val(addMonths(1));

            post({ _action: 'official_ad_slot_detail', id: slotId }).done(function (res) {
                if (!res || res.code !== 200) {
                    showError((res && res.msg) || '获取广告位失败');
                    $('#asfSave').text('保存');
                    return;
                }

                fill((res.data && res.data.slot) || {});
            }).fail(function () {
                showError('网络异常，请稍后重试');
                $('#asfSave').text('保存');
            });
        } else {
            $('#asfExpires').val(addMonths(1));
        }

        function save() {
            var $btn = $('#asfSave');
            if ($btn.prop('disabled')) return;

            var title = $.trim(String($('#asfTitle').val() || ''));
            var expires = $.trim(String($('#asfExpires').val() || ''));

            $('#asfError').removeClass('is-visible').text('');

            if (title === '') {
                showError('请填名称');
                $('#asfTitle').trigger('focus');
                return;
            }
            if (expires === '') {
                showError('请填到期日');
                return;
            }

            $btn.prop('disabled', true).text('保存中…');

            post({
                _action: 'official_ad_slot_save',
                id: slotId,
                title: title,
                label: $.trim(String($('#asfLabel').val() || '')),
                label_color: $.trim(String($('#asfColor').val() || '')),
                link_url: $.trim(String($('#asfLink').val() || '')),
                content: String($('#asfContent').val() || ''),
                expires_on: expires,
                status: String($('#asfStatus').val() || '1')
            }).done(function (res) {
                if (!res || res.code !== 200) {
                    $btn.prop('disabled', false).text('保存');
                    showError((res && res.msg) || '保存失败');
                    return;
                }

                /* 通知广告位列表刷新，再关掉自己 */
                try {
                    var p = window.parent;
                    if (p && p !== window && typeof p.__osAfterSave === 'function') {
                        p.__osAfterSave();
                    }
                } catch (e) {}

                closeSelf();
            }).fail(function () {
                $btn.prop('disabled', false).text('保存');
                showError('网络异常，请稍后重试');
            });
        }

        $('#asfSave').on('click', save);
        $('#asfCancel').on('click', closeSelf);
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
