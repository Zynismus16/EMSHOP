<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var bool $officialLoggedIn 是否已登录官方账号 */

$pageTitle = '版本安装包';
$logged    = !empty($officialLoggedIn);

include __DIR__ . '/header.php';
?>

<div class="popup-inner ov-wrap">
<?php if (!$logged): ?>
    <div class="ov-state"><i class="fa fa-user-circle-o"></i>尚未登录官方账号</div>
<?php else: ?>
    <div class="ov-note">
        <i class="fa fa-info-circle"></i>
        <span>下载的源码包会写入你的身份标识，客户装完归属才算在你名下。</span>
    </div>

    <div class="ov-list" id="ovList">
        <div class="ov-state"><i class="fa fa-spinner fa-spin"></i>加载中…</div>
    </div>

    <div class="ov-more-wrap" id="ovMoreWrap" hidden>
        <button type="button" class="ov-more" id="ovMore">加载更多</button>
    </div>
<?php endif; ?>
</div>

<style>
.ov-wrap { padding: 16px; }

.ov-note {
    display: flex; align-items: flex-start; gap: 8px;
    margin-bottom: 10px;
    padding: 9px 12px;
    background: #f8fafc;
    border: 1px solid #eef0f4;
    border-radius: 8px;
    font-size: 12px; color: #6b7280; line-height: 1.6;
}
.ov-note i { margin-top: 3px; color: #4f46e5; }

.ov-list {
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 12px;
    overflow: hidden;
}
.ov-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 12px;
    border-bottom: 1px solid #f1f3f7;
    transition: background 0.15s ease;
}
.ov-item:last-child { border-bottom: 0; }
.ov-item:hover { background: #fafbff; }
.ov-item__main { flex: 1; min-width: 0; }
.ov-item__top { display: flex; align-items: baseline; gap: 10px; }
.ov-item__version {
    font-family: 'JetBrains Mono', Consolas, Monaco, monospace;
    font-size: 13px; font-weight: 600; color: #111827;
}
.ov-item__time { font-size: 11px; color: #9ca3af; white-space: nowrap; }
.ov-item__changelog {
    margin-top: 3px;
    font-size: 12px; color: #6b7280; line-height: 1.5;
    word-break: break-all;
}
.ov-item__dl {
    flex-shrink: 0;
    height: 30px; padding: 0 12px;
    border: 1px solid #c7d2fe; border-radius: 6px;
    background: #eef2ff; color: #4f46e5;
    font-size: 12px; cursor: pointer;
    transition: background 0.15s ease;
}
.ov-item__dl:hover { background: #e0e7ff; }
.ov-item__dl:disabled { opacity: 0.6; cursor: not-allowed; }
.ov-item__noinstall { flex-shrink: 0; font-size: 11.5px; color: #9ca3af; }

.ov-state {
    padding: 44px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
}
.ov-state i {
    display: block; margin-bottom: 8px;
    font-size: 26px; color: #d1d5db;
}

.ov-more-wrap { margin-top: 10px; }
.ov-more {
    width: 100%; height: 34px;
    border: 1px solid #e0e0e0; border-radius: 4px;
    background: #fff; color: #333;
    font-size: 13px; cursor: pointer;
    transition: background 0.15s ease;
}
.ov-more:hover { background: #f5f5f5; }
.ov-more:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 600px) {
    .ov-wrap { padding: 12px; }
    .ov-item { gap: 8px; }
    .ov-item__dl { padding: 0 9px; }
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

        function shortTime(v) {
            var m = String(v || '').match(/^\d{4}-(\d{2}-\d{2})[ T](\d{2}:\d{2})/);
            return m ? m[1] + ' ' + m[2] : String(v || '');
        }

        function summarize(html) {
            var text = String(html || '')
                .replace(/<[^>]*>/g, ' ')
                .replace(/&nbsp;/gi, ' ')
                .replace(/\s+/g, ' ')
                .trim();

            if (text === '') return '—';

            return text.length > 60 ? (text.slice(0, 60) + '…') : text;
        }

        function renderRow(item) {
            var id = String(item.id || '');
            var hasInstall = !!item.has_install;

            return '<div class="ov-item">' +
                       '<div class="ov-item__main">' +
                           '<div class="ov-item__top">' +
                               '<span class="ov-item__version">' + esc(item.version || '—') + '</span>' +
                               '<span class="ov-item__time">' + esc(shortTime(item.updated_at)) + '</span>' +
                           '</div>' +
                           '<div class="ov-item__changelog">' + esc(summarize(item.changelog)) + '</div>' +
                       '</div>' +
                       (hasInstall
                           ? '<button type="button" class="ov-item__dl" data-id="' + esc(id) + '" data-version="' + esc(item.version || '') + '">下载源码</button>'
                           : '<span class="ov-item__noinstall">无安装包</span>') +
                   '</div>';
        }

        function load(nextPage) {
            var seq = ++requestSeq;
            $('#ovMore').prop('disabled', true);

            post({ _action: 'official_versions', page: nextPage, per_page: 10 }).done(function (res) {
                if (seq !== requestSeq) return;
                $('#ovMore').prop('disabled', false);

                if (!res || res.code !== 200) {
                    if (nextPage === 1) {
                        $('#ovList').html('<div class="ov-state"><i class="fa fa-exclamation-circle"></i>' +
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

                if (nextPage === 1) {
                    $('#ovList').empty();
                }

                if (!rows.length) {
                    if (nextPage === 1) {
                        $('#ovList').html('<div class="ov-state"><i class="fa fa-inbox"></i>还没有已发布的版本</div>');
                    }
                    $('#ovMoreWrap').prop('hidden', true);
                    return;
                }

                $('#ovList').append(rows.map(renderRow).join(''));
                $('#ovMoreWrap').prop('hidden', currentPage >= lastPage);
            }).fail(function () {
                if (seq !== requestSeq) return;
                $('#ovMore').prop('disabled', false);
                if (nextPage === 1) {
                    $('#ovList').html('<div class="ov-state"><i class="fa fa-exclamation-circle"></i>网络异常，请稍后重试</div>');
                } else {
                    layer.msg('网络异常');
                }
            });
        }

        $('#ovMore').on('click', function () {
            load(currentPage + 1);
        });

        function filenameOf(header) {
            if (!header) return '';

            var star = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(header);
            if (star) {
                try {
                    return decodeURIComponent(star[1].replace(/^"|"$/g, '').trim());
                } catch (e) {}
            }

            var plain = /filename="?([^";]+)"?/i.exec(header);
            return plain ? plain[1].trim() : '';
        }

        $(document).on('click', '.ov-item__dl', function () {
            var $btn = $(this);
            if ($btn.prop('disabled')) return;

            var id = String($btn.data('id') || '');
            var version = String($btn.data('version') || '');
            var url = '/admin/license.php?_download=version&id=' + encodeURIComponent(id) +
                      '&csrf_token=' + encodeURIComponent(csrfToken);

            $btn.prop('disabled', true).text('下载中…');

            fetch(url, { credentials: 'same-origin' })
                .then(function (res) {
                    if (!res.ok) {
                        return res.json().catch(function () { return null; }).then(function (json) {
                            throw new Error((json && json.msg) || '下载失败');
                        });
                    }

                    return res.blob().then(function (blob) {
                        var name = filenameOf(res.headers.get('Content-Disposition')) ||
                                   ('EMSHOP-' + version + '.zip');
                        var objectUrl = URL.createObjectURL(blob);
                        var link = document.createElement('a');

                        link.href = objectUrl;
                        link.download = name;
                        document.body.appendChild(link);
                        link.click();
                        link.remove();

                        setTimeout(function () { URL.revokeObjectURL(objectUrl); }, 1000);
                        layer.msg('已开始下载');
                    });
                })
                .catch(function (err) {
                    layer.msg(err && err.message ? err.message : '下载失败，请稍后重试');
                })
                .then(function () {
                    $btn.prop('disabled', false).text('下载源码');
                });
        });

        load(1);
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
