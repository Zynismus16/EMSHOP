<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var bool $officialLoggedIn 是否已登录官方账号（未登录时只显示提示，不发请求） */

$pageTitle = '我的激活码';
$logged    = !empty($officialLoggedIn);

include __DIR__ . '/header.php';
?>

<div class="popup-inner ol-wrap">
    <div class="ol-head">
        <span class="ol-head__count" id="olCount"></span>
    </div>
<?php if (!$logged): ?>
    <div class="ol-state"><i class="fa fa-user-circle-o"></i>尚未登录官方账号</div>
<?php else: ?>
    <div class="ol-list" id="olList">
        <div class="ol-state"><i class="fa fa-spinner fa-spin"></i>正在获取账号下的激活码…</div>
    </div>
<?php endif; ?>
</div>

<style>
.ol-wrap { padding: 16px; }

.ol-head {
    display: flex; align-items: center; justify-content: flex-end;
    min-height: 18px;
    margin-bottom: 10px;
}
.ol-head__count { font-size: 12px; color: #9ca3af; }

.ol-list { display: flex; flex-direction: column; gap: 10px; }

/* ---------- 激活码卡片 ---------- */
.ol-lic {
    padding: 14px 16px;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 14px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
    transition: border-color 0.15s ease;
}
.ol-lic:hover { border-color: #c7d2fe; }
.ol-lic.is-off { opacity: 0.65; }
.ol-lic__head { display: flex; align-items: center; gap: 8px; }
.ol-lic__code {
    flex: 1; min-width: 0;
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    font-size: 13px; font-weight: 600; color: #111827;
    word-break: break-all;
}
.ol-lic__copy {
    flex-shrink: 0;
    width: 26px; height: 26px;
    border: 1px solid #e5e7eb; border-radius: 6px;
    background: #fff; color: #9ca3af;
    font-size: 11px; cursor: pointer;
    transition: border-color 0.15s ease, color 0.15s ease;
}
.ol-lic__copy:hover { color: #4f46e5; border-color: #c7d2fe; }
.ol-lic__status {
    flex-shrink: 0;
    padding: 1px 6px; border-radius: 3px;
    font-size: 11px; line-height: 18px;
}
.ol-lic__status.is-ok  { background: #ecfdf5; color: #059669; }
.ol-lic__status.is-off { background: #f4f5f3; color: #9ca3af; }
.ol-lic__meta {
    display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
    margin-top: 8px;
    font-size: 12px; color: #6b7280;
}
.ol-lic__tag {
    padding: 1px 6px; border-radius: 3px;
    background: #eef2ff; color: #4f46e5;
    font-size: 11px; line-height: 18px;
}
/* 档位区分：VIP 蓝 / SVIP 紫 / 至尊 金 */
.ol-lic__tag.is-vip     { background: #eff6ff; color: #2563eb; }
.ol-lic__tag.is-svip    { background: #f5f3ff; color: #7c3aed; }
.ol-lic__tag.is-supreme { background: #fffbeb; color: #b45309; }
.ol-lic__domain { display: inline-flex; align-items: center; gap: 4px; }
.ol-lic__domain i { color: #9ca3af; }
.ol-lic__domain.is-empty { color: #9ca3af; }
.ol-lic__actions {
    margin-left: auto;
    display: flex; align-items: center; gap: 12px;
    flex-shrink: 0;
}
.ol-lic__reset,
.ol-lic__remove {
    padding: 0;
    border: 0; background: none;
    font-size: 11.5px; font-family: inherit;
    cursor: pointer;
    transition: color 0.15s ease;
}
.ol-lic__reset { color: #4f46e5; }
.ol-lic__reset:hover { color: #4338ca; text-decoration: underline; }
.ol-lic__remove { color: #e11d48; }
.ol-lic__remove:hover { color: #be123c; text-decoration: underline; }
.ol-lic__reset:disabled,
.ol-lic__remove:disabled { opacity: 0.6; cursor: not-allowed; text-decoration: none; }

.ol-state {
    padding: 48px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
}
.ol-state i {
    display: block; margin-bottom: 8px;
    font-size: 26px; color: #d1d5db;
}

@media (max-width: 600px) {
    .ol-wrap { padding: 12px; }
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

        function esc(s) {
            return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function copyText(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function () {
                    layer.msg('已复制');
                }, function () {
                    layer.msg('复制失败');
                });
                return;
            }
            var $ta = $('<textarea>').val(text).css({ position: 'fixed', top: 0, left: 0, opacity: 0 }).appendTo('body').select();
            try { document.execCommand('copy'); layer.msg('已复制'); } catch (e) { layer.msg('复制失败'); }
            $ta.remove();
        }

        function render(list) {
            var $list = $('#olList');
            $('#olCount').text(list.length ? '共 ' + list.length + ' 个' : '');
            if (!list.length) {
                $list.html('<div class="ol-state"><i class="fa fa-inbox"></i>该账号下还没有激活码</div>');
                return;
            }
            var html = '';
            list.forEach(function (it) {
                var ok = parseInt(it.status, 10) === 1;
                // 上游 type：1=VIP / 2=SVIP / 3=至尊
                var typeCls = ({ '1': ' is-vip', '2': ' is-svip', '3': ' is-supreme' })[String(it.type)] || '';
                var domain = it.domain
                    ? '<span class="ol-lic__domain"><i class="fa fa-globe"></i>' + esc(it.domain) + '</span>'
                    : '<span class="ol-lic__domain is-empty"><i class="fa fa-chain-broken"></i>未绑定域名</span>';
                var actions = '';
                if (ok) {
                    actions += '<button type="button" class="ol-lic__reset" data-code="' + esc(it.code) + '">重置授权码</button>';
                }
                if (it.domain) {
                    actions += '<button type="button" class="ol-lic__remove" data-code="' + esc(it.code) + '">移除授权</button>';
                }
                var actionsHtml = actions !== '' ? '<span class="ol-lic__actions">' + actions + '</span>' : '';
                html += '<div class="ol-lic' + (ok ? '' : ' is-off') + '" data-code="' + esc(it.code) + '">' +
                            '<div class="ol-lic__head">' +
                                '<span class="ol-lic__code">' + esc(it.code) + '</span>' +
                                '<span class="ol-lic__status ' + (ok ? 'is-ok' : 'is-off') + '">' + (ok ? '有效' : '已作废') + '</span>' +
                                '<button type="button" class="ol-lic__copy" data-copy="' + esc(it.code) + '" title="复制激活码">' +
                                    '<i class="fa fa-clone"></i>' +
                                '</button>' +
                            '</div>' +
                            '<div class="ol-lic__meta">' +
                                '<span class="ol-lic__tag' + typeCls + '">' + esc(it.type_label || '') + '</span>' +
                                domain +
                                actionsHtml +
                            '</div>' +
                        '</div>';
            });
            $list.html(html);
        }

        function load() {
            post({ _action: 'official_licenses' }).done(function (res) {
                if (!res || res.code !== 200) {
                    $('#olList').html('<div class="ol-state"><i class="fa fa-exclamation-circle"></i>' +
                                      esc((res && res.msg) || '获取失败') + '</div>');
                    return;
                }
                render((res.data && res.data.licenses) || []);
            }).fail(function () {
                $('#olList').html('<div class="ol-state"><i class="fa fa-exclamation-circle"></i>网络异常，请稍后重试</div>');
            });
        }

        $(document).on('click', '.ol-lic__copy', function () {
            copyText($(this).data('copy'));
        });

        // 本页是 iframe，确认框开到父窗口顶层
        function topLayer() {
            try {
                if (window.top && window.top !== window && window.top.layui && window.top.layui.layer) {
                    return window.top.layui.layer;
                }
            } catch (e) {}
            return layer;
        }

        // 移除授权：清空上游域名；如果这条码就是本机在用的，本地授权一起清掉
        $(document).on('click', '.ol-lic__remove', function () {
            var $btn = $(this);
            var code = String($btn.data('code') || '');
            if (code === '') return;

            var L = topLayer();
            L.confirm('确定移除该激活码的授权吗？移除后会清空上游绑定的域名，站点需要重新激活。', { skin: 'admin-modal' }, function (idx) {
                L.close(idx);
                $btn.prop('disabled', true).text('移除中…');

                post({ _action: 'official_remove_license', code: code }).done(function (res) {
                    if (!res || res.code !== 200) {
                        $btn.prop('disabled', false).text('移除授权');
                        L.msg((res && res.msg) || '移除失败');
                        return;
                    }

                    var localCleared = !!(res.data && res.data.local_cleared);

                    // 这一行：域名清空、按钮去掉
                    $btn.closest('.ol-lic')
                        .find('.ol-lic__domain')
                        .replaceWith('<span class="ol-lic__domain is-empty"><i class="fa fa-chain-broken"></i>未绑定域名</span>');
                    $btn.remove();

                    if (localCleared) {
                        L.msg('已移除授权，本机授权状态已清空');
                        setTimeout(function () {
                            try {
                                if (window.parent && window.parent !== window) {
                                    window.parent.location.reload();
                                }
                            } catch (e) {}
                        }, 800);
                    } else {
                        L.msg('已移除授权');
                    }
                }).fail(function () {
                    $btn.prop('disabled', false).text('移除授权');
                    L.msg('网络异常，请稍后重试');
                });
            });
        });

        // 重置授权码：换一个新码，旧码立即失效（域名、档位不动）
        $(document).on('click', '.ol-lic__reset', function () {
            var $btn = $(this);
            var code = String($btn.data('code') || '');
            if (code === '') return;

            var L = topLayer();
            L.confirm('确定重置这条授权码吗？重置后旧码立即失效，系统会换发一个新码，域名保持不变。', { skin: 'admin-modal' }, function (idx) {
                L.close(idx);
                $btn.prop('disabled', true).text('重置中…');

                post({ _action: 'official_reset_license', code: code }).done(function (res) {
                    if (!res || res.code !== 200) {
                        $btn.prop('disabled', false).text('重置授权码');
                        L.msg((res && res.msg) || '重置失败');
                        return;
                    }

                    var license = (res.data && res.data.license) || {};
                    var newCode = String(license.code || '');

                    if (newCode !== '') {
                        var $card = $btn.closest('.ol-lic');
                        $card.attr('data-code', newCode);
                        $card.find('.ol-lic__code').text(newCode);
                        $card.find('.ol-lic__copy').attr('data-copy', newCode).data('copy', newCode);
                        $card.find('.ol-lic__reset').attr('data-code', newCode).data('code', newCode);
                        $card.find('.ol-lic__remove').attr('data-code', newCode).data('code', newCode);
                    }

                    $btn.prop('disabled', false).text('重置授权码');
                    L.msg(res.data && res.data.local_updated
                        ? '已重置，本机保存的激活码已同步为新码'
                        : '已重置授权码，旧的立即失效');
                }).fail(function () {
                    $btn.prop('disabled', false).text('重置授权码');
                    L.msg('网络异常，请稍后重试');
                });
            });
        });

        <?php if ($logged): ?>
        load();
        <?php endif; ?>
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
