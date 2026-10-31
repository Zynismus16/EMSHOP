<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var bool $officialLoggedIn 是否已登录官方账号 */

$pageTitle = '安装记录';
$logged    = !empty($officialLoggedIn);

include __DIR__ . '/header.php';
?>

<div class="popup-inner oi-wrap">
<?php if (!$logged): ?>
    <div class="oi-state"><i class="fa fa-user-circle-o"></i>尚未登录官方账号</div>
<?php else: ?>
    <div class="oi-filter">
        <input type="text" class="form-group__input oi-keyword" id="oiKeyword"
               placeholder="域名 / 程序名 / IP / 管理员邮箱" autocomplete="off">
        <button type="button" class="popup-btn popup-btn--primary oi-btn" id="oiSearch">搜索</button>
        <button type="button" class="popup-btn popup-btn--default oi-btn" id="oiReset" hidden>重置</button>
    </div>

    <div class="oi-list" id="oiList">
        <div class="oi-state"><i class="fa fa-spinner fa-spin"></i>加载中…</div>
    </div>

    <div class="oi-more-wrap" id="oiMoreWrap" hidden>
        <button type="button" class="oi-more" id="oiMore">加载更多</button>
    </div>
<?php endif; ?>
</div>

<style>
.oi-wrap { padding: 16px; }

.oi-filter { display: flex; flex-wrap: wrap; gap: 8px; }
.oi-keyword { flex: 1 1 200px; min-width: 0; height: 34px; }
.oi-btn { height: 34px; line-height: 34px; padding: 0 13px; }
.oi-reset { border: 1px solid #e0e0e0; background: #fff; color: #333; }
.oi-reset:hover { background: #f5f5f5; }

.oi-list {
    margin-top: 10px;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 12px;
    overflow: hidden;
}
.oi-item { padding: 9px 12px; border-bottom: 1px solid #f1f3f7; }
.oi-item:last-child { border-bottom: 0; }
.oi-item:hover { background: #fafbff; }
.oi-item__top { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; }
.oi-item__time { font-size: 11.5px; color: #9ca3af; white-space: nowrap; }
.oi-item__name {
    flex: 1; min-width: 0;
    font-size: 13px; font-weight: 600; color: #111827;
    word-break: break-all;
}
.oi-item__name em { font-style: normal; font-weight: 400; font-size: 11.5px; color: #6b7280; }
.oi-item__reinstall {
    flex-shrink: 0;
    padding: 1px 6px; border-radius: 3px;
    font-size: 11px; line-height: 18px;
    background: #fffbeb; color: #b45309;
}
.oi-item__toggle {
    flex-shrink: 0;
    padding: 0; border: 0; background: none;
    font-size: 12px; color: #4f46e5; cursor: pointer;
}
.oi-item__toggle:hover { text-decoration: underline; }
.oi-item__meta {
    display: flex; flex-wrap: wrap; gap: 4px 12px;
    margin-top: 4px;
    font-size: 11.5px; color: #6b7280;
}
.oi-item__meta span { word-break: break-all; }
.oi-item__meta .is-mono { font-family: Menlo, Consolas, Monaco, monospace; }
.oi-item__detail {
    margin-top: 8px;
    padding-top: 8px;
    border-top: 1px dashed #eef0f4;
}
.oi-detail { margin: 0; }
.oi-detail > div { display: flex; gap: 8px; font-size: 11.5px; line-height: 1.7; }
.oi-detail dt { flex: 0 0 72px; color: #9ca3af; }
.oi-detail dd { margin: 0; min-width: 0; color: #374151; word-break: break-all; }

.oi-state {
    padding: 44px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
}
.oi-state i {
    display: block; margin-bottom: 8px;
    font-size: 26px; color: #d1d5db;
}

.oi-more-wrap { margin-top: 10px; }
.oi-more {
    width: 100%; height: 34px;
    border: 1px solid #e0e0e0; border-radius: 4px;
    background: #fff; color: #333;
    font-size: 13px; cursor: pointer;
    transition: background 0.15s ease;
}
.oi-more:hover { background: #f5f5f5; }
.oi-more:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 600px) {
    .oi-wrap { padding: 12px; }
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

        function projectLabel(p) {
            return p === 'tt' ? 'TTSHOP' : (p === 'em' ? 'EMSHOP' : (p || '—'));
        }

        function fullName(row) {
            var name = row.name || '—';
            var version = row.version ? ('v' + row.version) : '版本未知';
            return esc(projectLabel(row.project)) + ' · ' + esc(name) + ' <em>' + esc(version) + '</em>';
        }

        function detailHtml(row) {
            var site = String(row.site_url || '');
            var siteHtml = site === ''
                ? '—'
                : (/^https?:\/\//i.test(site)
                    ? '<a href="' + esc(site) + '" target="_blank" rel="noopener noreferrer">' + esc(site) + '</a>'
                    : esc(site));

            var rows = [
                ['安装时间', esc(row.created_at || '—')],
                ['程序', esc(projectLabel(row.project)) + (row.name ? ' · ' + esc(row.name) : '')],
                ['版本', row.version ? esc('v' + row.version) : '—'],
                ['安装域名', esc(row.domain || '—')],
                ['站点地址', siteHtml],
                ['客户端 IP', esc(row.ip || '—')],
                ['服务端 IP', esc(row.server_ip || '—')],
                ['管理员账号', esc(row.admin_username || '—')],
                ['管理员邮箱', esc(row.admin_email || '—')],
                ['PHP 版本', esc(row.php_version || '—')],
                ['数据库', esc(row.db_version || '—')],
                ['Web 服务器', esc(row.server_software || '—')],
                ['操作系统', esc(row.os || '—')],
                ['是否重装', row.is_reinstall ? '是（删了旧表重建）' : '否（全新安装）'],
                ['身份标识', esc(row.identity || '—（这次上报没带）')]
            ];

            return '<dl class="oi-detail">' + rows.map(function (item) {
                return '<div><dt>' + item[0] + '</dt><dd>' + item[1] + '</dd></div>';
            }).join('') + '</dl>';
        }

        function renderRow(row) {
            var admin = String(row.admin_username || '').trim();
            var email = String(row.admin_email || '').trim();
            var ip = String(row.ip || row.server_ip || '').trim();

            return '<div class="oi-item">' +
                       '<div class="oi-item__top">' +
                           '<span class="oi-item__time">' + esc(shortTime(row.created_at)) + '</span>' +
                           '<span class="oi-item__name">' + fullName(row) + '</span>' +
                           (row.is_reinstall ? '<span class="oi-item__reinstall">重装</span>' : '') +
                           '<button type="button" class="oi-item__toggle">详情</button>' +
                       '</div>' +
                       '<div class="oi-item__meta">' +
                           '<span class="is-mono">' + esc(row.domain || '—') + '</span>' +
                           '<span>' + esc(admin !== '' ? admin : (email !== '' ? email : '—')) + '</span>' +
                           '<span class="is-mono">' + esc(ip !== '' ? ip : '—') + '</span>' +
                       '</div>' +
                       '<div class="oi-item__detail" hidden>' + detailHtml(row) + '</div>' +
                   '</div>';
        }

        function load(nextPage, reset) {
            var seq = ++requestSeq;
            $('#oiMore').prop('disabled', true);

            post({
                _action: 'official_install_records',
                page: nextPage,
                per_page: 10,
                keyword: $.trim($('#oiKeyword').val())
            }).done(function (res) {
                if (seq !== requestSeq) return;
                $('#oiMore').prop('disabled', false);

                if (!res || res.code !== 200) {
                    if (nextPage === 1) {
                        $('#oiList').html('<div class="oi-state"><i class="fa fa-exclamation-circle"></i>' +
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

                if (reset) {
                    $('#oiList').empty();
                }

                if (!rows.length) {
                    if (nextPage === 1) {
                        $('#oiList').html('<div class="oi-state"><i class="fa fa-inbox"></i>还没有安装记录</div>');
                    }
                    $('#oiMoreWrap').prop('hidden', true);
                    return;
                }

                $('#oiList').append(rows.map(renderRow).join(''));
                $('#oiMoreWrap').prop('hidden', currentPage >= lastPage);
            }).fail(function () {
                if (seq !== requestSeq) return;
                $('#oiMore').prop('disabled', false);
                if (nextPage === 1) {
                    $('#oiList').html('<div class="oi-state"><i class="fa fa-exclamation-circle"></i>网络异常，请稍后重试</div>');
                } else {
                    layer.msg('网络异常');
                }
            });
        }

        function search() {
            $('#oiMoreWrap').prop('hidden', true);
            $('#oiList').html('<div class="oi-state"><i class="fa fa-spinner fa-spin"></i>加载中…</div>');
            load(1, true);
        }

        function refreshReset() {
            var active = $.trim($('#oiKeyword').val()) !== '';
            $('#oiReset').prop('hidden', !active);
        }

        $('#oiSearch').on('click', search);
        $('#oiKeyword').on('keydown', function (e) {
            if (e.key === 'Enter') search();
        });
        $('#oiKeyword').on('input', refreshReset);

        $('#oiReset').on('click', function () {
            $('#oiKeyword').val('');
            refreshReset();
            search();
        });

        $('#oiMore').on('click', function () {
            load(currentPage + 1, false);
        });

        $(document).on('click', '.oi-item__toggle', function () {
            var $item = $(this).closest('.oi-item');
            var $detail = $item.find('.oi-item__detail');
            var open = $detail.prop('hidden');

            $detail.prop('hidden', !open);
            $(this).text(open ? '收起' : '详情');
        });

        load(1, true);
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
