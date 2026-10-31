<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var bool $officialLoggedIn 是否已登录官方账号 */

$pageTitle = '广告位管理';
$logged    = !empty($officialLoggedIn);

include __DIR__ . '/header.php';
?>

<div class="popup-inner os-wrap">
<?php if (!$logged): ?>
    <div class="os-state"><i class="fa fa-user-circle-o"></i>尚未登录官方账号</div>
<?php else: ?>
    <div class="os-toolbar">
        <input type="text" class="form-group__input os-keyword" id="osKeyword"
               placeholder="搜索名称或标签" autocomplete="off">
        <select class="form-group__input os-select" id="osState">
            <option value="">全部状态</option>
            <option value="on">生效中</option>
            <option value="expired">已过期</option>
            <option value="off">已停用</option>
        </select>
        <button type="button" class="popup-btn popup-btn--primary os-btn" id="osSearch">搜索</button>
        <button type="button" class="popup-btn popup-btn--default os-btn" id="osReset" hidden>重置</button>
        <button type="button" class="popup-btn popup-btn--primary os-btn os-create" id="osCreate">新建广告位</button>
    </div>

    <div class="os-list" id="osList">
        <div class="os-state"><i class="fa fa-spinner fa-spin"></i>加载中…</div>
    </div>

    <div class="os-more-wrap" id="osMoreWrap" hidden>
        <button type="button" class="os-more" id="osMore">加载更多</button>
    </div>
<?php endif; ?>
</div>

<style>
.os-wrap { padding: 16px; }

.os-toolbar { display: flex; flex-wrap: wrap; gap: 8px; }
.os-keyword { flex: 1 1 160px; min-width: 0; height: 34px; }
.os-select { flex: 0 0 106px; width: 106px; height: 34px; padding: 0 6px; }
.os-btn { height: 34px; line-height: 34px; padding: 0 13px; }
.os-create { margin-left: auto; }
.os-reset { border: 1px solid #e0e0e0; background: #fff; color: #333; }
.os-reset:hover { background: #f5f5f5; }

.os-list {
    margin-top: 10px;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 12px;
    overflow: hidden;
}
.os-item { padding: 9px 12px; border-bottom: 1px solid #f1f3f7; }
.os-item:last-child { border-bottom: 0; }
.os-item:hover { background: #fafbff; }
.os-item__top { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px; }
.os-item__title { font-size: 13px; font-weight: 600; color: #111827; word-break: break-all; }
.os-item__label {
    flex-shrink: 0;
    padding: 0 6px; border-radius: 3px;
    font-size: 11px; line-height: 18px;
}
.os-item__state {
    flex-shrink: 0;
    margin-left: auto;
    padding: 1px 6px; border-radius: 3px;
    font-size: 11px; line-height: 18px;
    background: #f4f5f3; color: #6b7280;
}
.os-item__state.is-on { background: #ecfdf5; color: #059669; }
.os-item__state.is-expired { background: #f4f5f3; color: #9ca3af; }
.os-item__state.is-off { background: #fff1f2; color: #e11d48; }
.os-item__meta {
    display: flex; flex-wrap: wrap; gap: 4px 12px;
    margin-top: 4px;
    font-size: 11.5px; color: #6b7280;
}
.os-item__link {
    display: inline-block; max-width: 260px;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.os-item__excerpt {
    margin-top: 4px;
    font-size: 12px; color: #9ca3af; line-height: 1.5;
    word-break: break-all;
}
.os-item__actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 6px; }

.os-link {
    padding: 0; border: 0; background: none;
    font-size: 12px; color: #4f46e5; cursor: pointer;
}
.os-link:hover { text-decoration: underline; }
.os-link.is-danger { color: #dc2626; }
.os-link:disabled { color: #c4c9d4; cursor: not-allowed; text-decoration: none; }

.os-state {
    padding: 44px 12px;
    text-align: center;
    font-size: 13px; color: #9ca3af;
}
.os-state i {
    display: block; margin-bottom: 8px;
    font-size: 26px; color: #d1d5db;
}

.os-more-wrap { margin-top: 10px; }
.os-more {
    width: 100%; height: 34px;
    border: 1px solid #e0e0e0; border-radius: 4px;
    background: #fff; color: #333;
    font-size: 13px; cursor: pointer;
    transition: background 0.15s ease;
}
.os-more:hover { background: #f5f5f5; }
.os-more:disabled { opacity: 0.6; cursor: not-allowed; }

@media (max-width: 600px) {
    .os-wrap { padding: 12px; }
    .os-select { flex: 1 1 96px; width: auto; }
    .os-create { margin-left: 0; }
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
        var busy = false;

        function topLayer() {
            try {
                if (window.top && window.top !== window && window.top.layui && window.top.layui.layer) {
                    return window.top.layui.layer;
                }
            } catch (e) {}
            return layer;
        }

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

        function pad(n) { return n < 10 ? ('0' + n) : String(n); }

        function today() {
            var d = new Date();
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
        }

        function daysLeft(value) {
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
            if (!m) return 0;

            var target = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
            var now = new Date();
            var start = new Date(now.getFullYear(), now.getMonth(), now.getDate());

            return Math.round((target.getTime() - start.getTime()) / 86400000);
        }

        function slotState(row) {
            if (Number(row.status) !== 1) return 'off';
            return String(row.expires_on || '') < today() ? 'expired' : 'on';
        }

        function stateText(state) {
            return ({ on: '生效中', expired: '已过期', off: '已停用' })[state] || '未知';
        }

        function daysText(row, state) {
            var left = daysLeft(row.expires_on);

            if (state === 'off') return '已停用';
            if (left < 0) return (-left) + ' 天前过期';
            if (left === 0) return '今天到期';
            return '还剩 ' + left + ' 天';
        }

        function labelStyle(color) {
            var c = String(color || '').trim();
            if (!/^#[0-9a-fA-F]{6}$/.test(c)) return '';
            return 'color:' + c + ';background:' + c + '1a;border:1px solid ' + c + '33;';
        }

        function renderRow(row) {
            var st = slotState(row);
            var id = String(row.id || '');

            return '<div class="os-item" data-id="' + esc(id) + '">' +
                       '<div class="os-item__top">' +
                           '<span class="os-item__title">' + esc(row.title || '') + '</span>' +
                           (row.label
                               ? '<span class="os-item__label" style="' + labelStyle(row.label_color) + '">' + esc(row.label) + '</span>'
                               : '') +
                           '<span class="os-item__state is-' + st + '">' + esc(stateText(st)) + '</span>' +
                       '</div>' +
                       '<div class="os-item__meta">' +
                           '<span>到期 ' + esc(row.expires_on || '—') + '</span>' +
                           '<span>' + esc(daysText(row, st)) + '</span>' +
                           (row.link_url
                               ? '<span class="os-item__link" title="' + esc(row.link_url) + '">' + esc(row.link_url) + '</span>'
                               : '') +
                       '</div>' +
                       (row.excerpt
                           ? '<div class="os-item__excerpt">' + esc(row.excerpt) + '</div>'
                           : '') +
                       '<div class="os-item__actions">' +
                           '<button type="button" class="os-link" data-act="edit" data-id="' + esc(id) + '">编辑</button>' +
                           (Number(row.status) === 1
                               ? '<button type="button" class="os-link" data-act="off" data-id="' + esc(id) + '">停用</button>'
                               : '<button type="button" class="os-link" data-act="on" data-id="' + esc(id) + '">启用</button>') +
                           '<button type="button" class="os-link" data-act="up" data-id="' + esc(id) + '">上移</button>' +
                           '<button type="button" class="os-link" data-act="down" data-id="' + esc(id) + '">下移</button>' +
                           '<button type="button" class="os-link is-danger" data-act="delete" data-id="' + esc(id) + '">删除</button>' +
                       '</div>' +
                   '</div>';
        }

        function load(nextPage, reset) {
            var seq = ++requestSeq;
            $('#osMore').prop('disabled', true);

            post({
                _action: 'official_ad_slots',
                page: nextPage,
                per_page: 10,
                keyword: $.trim($('#osKeyword').val()),
                state: $('#osState').val()
            }).done(function (res) {
                if (seq !== requestSeq) return;
                $('#osMore').prop('disabled', false);

                if (!res || res.code !== 200) {
                    if (nextPage === 1) {
                        $('#osList').html('<div class="os-state"><i class="fa fa-exclamation-circle"></i>' +
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
                    $('#osList').empty();
                }

                if (!rows.length) {
                    if (nextPage === 1) {
                        $('#osList').html('<div class="os-state"><i class="fa fa-inbox"></i>还没有广告位</div>');
                    }
                    $('#osMoreWrap').prop('hidden', true);
                    return;
                }

                $('#osList').append(rows.map(renderRow).join(''));
                $('#osMoreWrap').prop('hidden', currentPage >= lastPage);
            }).fail(function () {
                if (seq !== requestSeq) return;
                $('#osMore').prop('disabled', false);
                if (nextPage === 1) {
                    $('#osList').html('<div class="os-state"><i class="fa fa-exclamation-circle"></i>网络异常，请稍后重试</div>');
                } else {
                    layer.msg('网络异常');
                }
            });
        }

        function search() {
            $('#osMoreWrap').prop('hidden', true);
            $('#osList').html('<div class="os-state"><i class="fa fa-spinner fa-spin"></i>加载中…</div>');
            load(1, true);
        }

        function refreshReset() {
            var active = $.trim($('#osKeyword').val()) !== '' || $('#osState').val() !== '';
            $('#osReset').prop('hidden', !active);
        }

        function openSlotForm(id) {
            var L = topLayer();
            var win = window;
            var doc = document;

            try {
                if (window.top && window.top !== window && window.top.document) {
                    win = window.top;
                    doc = window.top.document;
                }
            } catch (e) {}

            var vw = win.innerWidth || doc.documentElement.clientWidth;
            var vh = win.innerHeight || doc.documentElement.clientHeight;
            var width = Math.max(300, Math.min(560, vw - 24));
            var height = Math.max(320, Math.min(620, vh - 40));
            var url = '/admin/license.php?_popup=ad_slot_form' + (id ? ('&id=' + encodeURIComponent(id)) : '');

            /* 表单保存成功后由 iframe 回调这里：刷新列表、提示一次 */
            var afterSave = function () {
                try { delete win.__osAfterSave; } catch (e) {}
                try { delete window.__osAfterSave; } catch (e) {}
                layer.msg('已保存');
                search();
            };

            try { delete win.__osAfterSave; } catch (e) {}
            win.__osAfterSave = afterSave;
            window.__osAfterSave = afterSave;

            L.open({
                type: 2,
                title: id ? '编辑广告位' : '新建广告位',
                skin: 'admin-modal',
                area: [width + 'px', height + 'px'],
                shadeClose: true,
                content: url
            });
        }

        function setStatus(id, status) {
            if (busy) return;
            busy = true;

            post({ _action: 'official_ad_slot_status', id: id, status: status }).done(function (res) {
                if (!res || res.code !== 200) {
                    layer.msg((res && res.msg) || '操作失败');
                    return;
                }
                layer.msg(status === 1 ? '已启用' : '已停用');
                search();
            }).fail(function () {
                layer.msg('网络异常');
            }).always(function () {
                busy = false;
            });
        }

        function move(id, direction) {
            if (busy) return;
            busy = true;

            post({ _action: 'official_ad_slot_move', id: id, direction: direction }).done(function (res) {
                if (!res || res.code !== 200) {
                    layer.msg((res && res.msg) || '操作失败');
                    return;
                }
                layer.msg('顺序已调整');
                search();
            }).fail(function () {
                layer.msg('网络异常');
            }).always(function () {
                busy = false;
            });
        }

        function remove(id, title) {
            if (busy) return;

            var L = topLayer();
            L.confirm('删掉「' + title + '」之后查不回来。只想让它停止生效的话，用「停用」或者改到期日。', {
                skin: 'admin-modal'
            }, function (idx) {
                L.close(idx);

                busy = true;

                post({ _action: 'official_ad_slot_delete', id: id }).done(function (res) {
                    if (!res || res.code !== 200) {
                        layer.msg((res && res.msg) || '删除失败');
                        return;
                    }
                    layer.msg('已删除');
                    search();
                }).fail(function () {
                    layer.msg('网络异常');
                }).always(function () {
                    busy = false;
                });
            });
        }

        $('#osCreate').on('click', function () {
            openSlotForm(null);
        });

        $('#osSearch').on('click', search);
        $('#osKeyword').on('keydown', function (e) {
            if (e.key === 'Enter') search();
        });
        $('#osState').on('change', search);
        $('#osKeyword').on('input', refreshReset);
        $('#osState').on('change', refreshReset);

        $('#osReset').on('click', function () {
            $('#osKeyword').val('');
            $('#osState').val('');
            refreshReset();
            search();
        });

        $('#osMore').on('click', function () {
            load(currentPage + 1, false);
        });

        $(document).on('click', '.os-item [data-act]', function () {
            var act = String($(this).data('act') || '');
            var id = String($(this).data('id') || '');
            var $item = $(this).closest('.os-item');
            var title = $item.find('.os-item__title').text();

            if (act === 'edit') {
                openSlotForm(id);
            } else if (act === 'on' || act === 'off') {
                setStatus(id, act === 'on' ? 1 : 0);
            } else if (act === 'up' || act === 'down') {
                move(id, act);
            } else if (act === 'delete') {
                remove(id, title);
            }
        });

        load(1, true);
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
