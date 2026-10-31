<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
?>
<style>
.appstore-login-link {
    color: #4f46e5;
    text-decoration: underline;
    cursor: pointer;
}
.appstore-login-link:hover { color: #4338ca; }
</style>

<script>
$(function () {
    if (typeof layui === 'undefined') return;

    layui.use(['layer'], function () {
        var layer = layui.layer;

        function openLogin() {
            var winW = window.innerWidth || document.documentElement.clientWidth;
            var winH = window.innerHeight || document.documentElement.clientHeight;
            var width = Math.max(320, Math.min(440, winW - 24));
            var height = Math.max(360, Math.min(520, winH - 40));

            window.__officialLoginDone = function () {
                window.location.reload();
            };

            var index = layer.open({
                type: 2,
                title: '登录官方账号',
                skin: 'admin-modal',
                area: [width + 'px', height + 'px'],
                shadeClose: true,
                content: '/admin/license.php?_popup=official_login',
                end: function () {
                    try { delete window.__officialLoginResize; } catch (e) {}
                }
            });

            window.__officialLoginResize = function (contentHeight) {
                try {
                    var $layero = $('#layui-layer' + index);
                    if (!$layero.length) return;

                    var titleH = $layero.find('.layui-layer-title').outerHeight() || 42;
                    var maxH = window.innerHeight - 40;
                    var h = Math.min(Math.max(320, contentHeight + titleH), maxH);
                    var contentH = Math.max(200, h - titleH);
                    var top = Math.max(10, (window.innerHeight - h) / 2);

                    $layero.css({ height: h + 'px', top: top + 'px' });
                    $layero.children('.layui-layer-content').css('height', contentH + 'px');
                    $layero.find('iframe').css('height', contentH + 'px');
                } catch (e) {}
            };
        }

        $(document).on('click', '.appstore-login-link', function (e) {
            e.preventDefault();
            openLogin();
        });

        setTimeout(openLogin, 200);
    });
});
</script>
