(function ($) {
    'use strict';

    /**
     * 全局 HTML 转义。
     * layer 的 title / msg / confirm / open 内容按 HTML 渲染，任何拼进去的
     * 数据库字段都必须先过这里，避免后台存储型 XSS。
     */
    window.emEsc = function (v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    /** 
     * 高亮当前菜单项
     * 根据 URL 匹配侧栏菜单，设置 is-active 状态并展开对应父级
     * @param {string} url - 当前页面 URL
     */
    function setActiveMenu(url) {
        // 命中策略（避免"多项同 path 全亮"问题）：
        //   1. 先按 path + query 精确匹配（解决 /admin/commission.php?tab=log 与 ?tab=withdraw 互不干扰）
        //   2. 没命中再退到只按 path 匹配（解决 /admin/settings.php?action=xxx 共用一项菜单）
        //   3. path 匹配也只取第一项命中，杜绝同 path 不同 query 的多项被同时高亮
        var a = document.createElement('a');
        a.href = url;
        var targetPath = a.pathname.split('#')[0];
        var targetSearch = a.search || '';
        $('.admin-menu-item').removeClass('is-active');

        var $matched = null;

        // 第一遍：path + query 严格匹配
        $('.admin-menu-item').each(function () {
            var href = $(this).attr('href');
            if (!href || href.indexOf('javascript:') === 0) return;
            a.href = href;
            var matchPath = a.pathname.split('#')[0];
            var matchSearch = a.search || '';
            if (matchPath === targetPath && matchSearch === targetSearch) {
                $matched = $(this);
                return false;
            }
        });

        // 第二遍：path 匹配（仅当严格匹配没命中），命中即停
        if (!$matched) {
            $('.admin-menu-item').each(function () {
                var href = $(this).attr('href');
                if (!href || href.indexOf('javascript:') === 0) return;
                a.href = href;
                var matchPath = a.pathname.split('#')[0];
                if (matchPath === targetPath) {
                    $matched = $(this);
                    return false;
                }
            });
        }

        var $matchedBody = null;
        if ($matched) {
            $matched.addClass('is-active');
            $matchedBody = $matched.closest('.admin-menu-group__body');
        }

        // 清除所有父级菜单高亮
        $('.admin-menu-group__header').removeClass('is-active');

        // 收起所有不相关的展开菜单，展开匹配项所在的菜单组
        $('.admin-menu-group__header.is-open').each(function () {
            var $body = $(this).next('.admin-menu-group__body');
            if (!$matchedBody || !$matchedBody.length || $body[0] !== $matchedBody[0]) {
                $(this).removeClass('is-open');
                $body.stop(true).slideUp(300, function () {
                    $(this).removeClass('is-open');
                });
            }
        });

        if ($matchedBody && $matchedBody.length) {
            $matchedBody.stop(true).slideDown(300).addClass('is-open');
            $matchedBody.prev('.admin-menu-group__header').addClass('is-open is-active');
        }
    }

    /**
     * 菜单展开/折叠（手风琴 — 同级只展开一个）
     * 使用 slideUp/slideDown 实现平滑动画
     */
    function bindMenuToggle() {
        $(document).off('click.admin-menu').on('click.admin-menu', '.admin-menu-group__header', function () {
            var $header = $(this);
            var $body = $header.next('.admin-menu-group__body');
            var $parentGroup = $header.closest('.admin-menu-group');

            if ($header.hasClass('is-open')) {
                // 折叠当前菜单
                $header.removeClass('is-open');
                $body.stop(true).slideUp(300, function () {
                    $(this).removeClass('is-open');
                });
            } else {
                // 关闭同级其他已展开的菜单（手风琴效果）
                $parentGroup.siblings('.admin-menu-group').each(function () {
                    var $otherHeader = $(this).children('.admin-menu-group__header.is-open');
                    var $otherBody = $(this).children('.admin-menu-group__body.is-open');
                    if ($otherHeader.length) {
                        $otherHeader.removeClass('is-open');
                        $otherBody.stop(true).slideUp(300, function () {
                            $(this).removeClass('is-open');
                        });
                    }
                });

                // 展开当前菜单
                $header.addClass('is-open');
                $body.stop(true).slideDown(300).addClass('is-open');
            }
        });
    }

    /**
     * 移动端收起侧栏与遮罩
     */
    function closeMobileSidebar() {
        if ($(window).width() > 1024) {
            return;
        }
        $('#adminSidebar').removeClass('is-open');
        $('#adminOverlay').removeClass('is-visible');
    }

    /**
     * 侧栏展开/折叠 + localStorage 持久化
     */
    function bindSidebarToggle() {
        $(document).off('click.admin-toggle').on('click.admin-toggle', '#adminSidebarToggle', function () {
            var $sidebar = $('#adminSidebar');
            var isMobile = $(window).width() <= 1024;

            if (isMobile) {
                // 移动端：滑出/隐藏侧栏 + 遮罩
                $sidebar.toggleClass('is-open');
                $('#adminOverlay').toggleClass('is-visible');
                // 清除 PC 折叠状态，不影响手机布局
                $sidebar.removeClass('is-collapsed');
                $(document.body).removeClass('sidebar-pre-collapsed');
            } else {
                // 桌面端：折叠/展开侧栏宽度
                var collapsed = !$sidebar.hasClass('is-collapsed');
                $sidebar.toggleClass('is-collapsed');
                document.body.classList.toggle('sidebar-pre-collapsed', collapsed);
                // cookie 供服务端 PHP 读取，刷新页面时无需 JS 恢复
                document.cookie = 'admin_sidebar_collapsed=' + (collapsed ? '1' : '0') + '; path=/';
            }
        });

        // 点击遮罩关闭侧栏
        $(document).off('click.admin-overlay').on('click.admin-overlay', '#adminOverlay', function () {
            closeMobileSidebar();
        });
    }

    /**
     * 用户下拉菜单
     */
    function bindUserMenu() {
        $(document).off('click.admin-user-menu').on('click.admin-user-menu', '.admin-user-menu__trigger', function (e) {
            e.stopPropagation();
            $('#adminUserMenu').toggleClass('is-open');
        });

        $(document).on('click.admin-user-close', function (e) {
            if (!$(e.target).closest('#adminUserMenu').length) {
                $('#adminUserMenu').removeClass('is-open');
            }
        });
    }

    /**
     * 图片预览（通用）
     * 支持 .admin-img-field > img，placeholder 图片不响应点击
     */
    function bindImagePreview() {
        $(document).on('click', '.admin-img-field > img', function () {
            var src = $(this).attr('src');
            var placeholder = $(this).closest('.admin-img-field').data('placeholder') || '';
            if (!src || src === placeholder) return;
            if (typeof layer !== 'undefined') {
                layer.photos({photos: {title: '', id: 0, start: 0, data: [{alt: '', pid: 0, src: src}]}, anim: 5});
            }
        });
    }

    /**
     * 清除缓存
     */
    function bindClearCache() {
        $(document).off('click.admin-clear-cache').on('click.admin-clear-cache', '#adminClearCache, #adminDropdownClearCache, #mobileDropdownClearCache', function () {
            var csrfToken = window.adminCsrfToken || '';
            $.ajax({
                url: '/admin/index.php',
                type: 'POST',
                dataType: 'json',
                data: {csrf_token: csrfToken, _action: 'clear_cache'},
                success: function (res) {
                    if (res.code === 200) {
                        EmToast.ok(res.msg || '缓存已清空');
                    } else {
                        EmToast.err(res.msg || '操作失败');
                    }
                },
                error: function () {
                    EmToast.err('网络异常');
                }
            });
        });
    }

    /**
     * 语言切换功能
     */
    function bindLangSwitch() {
        // 点击触发器打开/关闭下拉菜单
        $(document).off('click.admin-lang-switch').on('click.admin-lang-switch', '.admin-lang-switch__trigger', function (e) {
            e.stopPropagation();
            $('#adminLangSwitch').toggleClass('is-open');
            // 关闭用户菜单
            $('#adminUserMenu').removeClass('is-open');
        });

        // 移动端用户菜单中的语言切换触发器
        $(document).off('click.admin-mobile-lang').on('click.admin-mobile-lang', '.admin-user-menu__lang > .admin-dropdown-link--lang', function (e) {
            e.stopPropagation();
            var $container = $(this).parent('.admin-user-menu__lang');
            $container.toggleClass('is-open');
        });

        // 点击外部关闭两个语言下拉
        $(document).on('click.admin-lang-close', function (e) {
            if (!$(e.target).closest('#adminLangSwitch').length) {
                $('#adminLangSwitch').removeClass('is-open');
            }
            if (!$(e.target).closest('.admin-user-menu__lang').length) {
                $('.admin-user-menu__lang').removeClass('is-open');
            }
        });

        // 选择语言（通用，桌面和移动端共用）
        $(document).on('click', '.admin-lang-option', function () {
            var langCode = $(this).data('code');
            var langName = $(this).data('name');

            // 设置cookie，有效期30天
            setCookie('admin_lang', langCode, 30);

            // 更新显示（桌面端）
            $('#currentLangName').text(langName);
            // 更新显示（移动端）
            $('#mobileLangName').text(langName);

            // 同步两个下拉的高亮状态
            $('.admin-lang-option').removeClass('is-active');
            $(this).addClass('is-active');
            var $options = $(this).closest('.admin-lang-dropdown').find('.admin-lang-option');
            $options.removeClass('is-active');
            $options.filter('[data-code="' + langCode + '"]').addClass('is-active');

            // 关闭下拉菜单
            $('#adminLangSwitch').removeClass('is-open');
            $('.admin-user-menu__lang').removeClass('is-open');

            // 提示并刷新页面
            if (typeof layui !== 'undefined' && layui.layer) {
                layui.layer.msg('语言已切换，页面将刷新', {icon: 1, time: 1000}, function() {
                    window.location.reload();
                });
            } else {
                alert('语言已切换，页面将刷新');
                window.location.reload();
            }
        });
    }
    
    /**
     * 获取 cookie
     */
    function getCookie(name) {
        var value = '; ' + document.cookie;
        var parts = value.split('; ' + name + '=');
        if (parts.length === 2) return parts.pop().split(';').shift();
        return '';
    }
    
    /**
     * 设置 cookie
     */
    function setCookie(name, value, days) {
        var expires = '';
        if (days) {
            var date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = '; expires=' + date.toUTCString();
        }
        document.cookie = name + '=' + (value || '') + expires + '; path=/';
    }

    /**
     * Pjax 局部页面加载
     */
    function initPjax() {
        if (typeof $.pjax !== 'function') {
            return;
        }

        $(document).pjax('.admin-menu-item[data-pjax], .admin-dropdown-link[data-pjax], .em-tabs__item[data-pjax], .admin-sidebar__site-name[data-pjax]', '#adminContent', {
            fragment: '#adminContent',
            timeout: 8000,
            scrollTo: false
        });

        // 移动端：点击左侧菜单跳转后自动收起侧栏
        $(document).on('pjax:click', function (event, options) {
            var link = (options && options.target) || event.target;
            if ($(link).closest('.admin-sidebar').length) {
                closeMobileSidebar();
            }
        });

        // Pjax loading 效果；切换前销毁内容区 TinyMCE，避免重复实例/孤儿编辑器
        $(document).on('pjax:send', function () {
            window._emTinymcePjaxGen = (window._emTinymcePjaxGen || 0) + 1;
            if (typeof window.emTinymceDestroyIn === 'function') {
                window.emTinymceDestroyIn('#adminContent');
            }
            $('#adminContent').addClass('is-loading');
        });

        $(document).on('pjax:complete', function () {
            $('#adminContent').removeClass('is-loading');
        });

        $(document).on('pjax:success', function (event, data, status, xhr, options) {
            setActiveMenu(options.url);
            closeMobileSidebar();
            // 更新工具栏标题
            var $page = $('#adminContent .admin-page__title');
            if ($page.length) {
                $('.admin-toolbar__page-title').text($page.text());
            }
            // 关闭用户下拉菜单
            $('#adminUserMenu').removeClass('is-open');
            // 重新渲染 layui 表单组件（switch 等）
            if (typeof layui !== 'undefined' && layui.form) {
                layui.form.render();
            }
            // PJAX 注入后重算 TinyMCE 尺寸
            setTimeout(function () {
                if (typeof window.emTinymceResize === 'function') {
                    window.emTinymceResize();
                }
            }, 80);
            // 设置页选项卡高亮：基于当前 URL 参数（只影响带 data-tab 的 em-tabs，
            // 避免误清掉其它页面里 em-tabs 的激活态，如 goods 列表的 data-sale）
            var urlParams = new URLSearchParams(window.location.search);
            var currentTab = urlParams.get('action') || 'base';
            $('.em-tabs__item[data-tab]').removeClass('is-active');
            var $activeSettingsTab = $('.em-tabs__item[data-tab="' + currentTab + '"]').addClass('is-active');
            $('.admin-settings__form-wrap').hide();
            $('#tab-' + currentTab).show();
            // 设置页横向 tab：切换后滚到当前项，避免激活项在可视区外
            if ($activeSettingsTab.length && $activeSettingsTab[0].scrollIntoView) {
                $activeSettingsTab[0].scrollIntoView({ inline: 'center', block: 'nearest' });
            }
        });
    }

    /**
     * 初始化：页面加载完成后初始化所有菜单的展开/折叠状态
     * 已展开的 .admin-menu-group__body.is-open 设置 display:block
     */
    function initMenuState() {
        // 已有 is-open 类的菜单体设置为可见
        $('.admin-menu-group__body.is-open').css('display', 'block');
        // 未展开的菜单体确保隐藏
        $('.admin-menu-group__body').not('.is-open').css('display', 'none');
    }

    /**
     * 更新全局 CSRF Token
     * 弹窗保存成功后调用此方法同步父窗口的 CSRF token
     * @param {string} token - 新的 CSRF token
     */
    function updateCsrf(token) {
        if (!token) return;
        window.adminCsrfToken = token;
        // 更新页面上所有隐藏的 csrf_token 字段
        $('input[name="csrf_token"]').val(token);
        // 通知所有 layer iframe 弹窗更新 token
        try {
            $('.layui-layer iframe').each(function () {
                try {
                    var win = this.contentWindow;
                    if (win && typeof win.updateCsrf === 'function') {
                        win.updateCsrf(token);
                    }
                } catch (e) {}
            });
        } catch (e) {}
    }
    window.updateCsrf = updateCsrf;

    /**
     * 打开媒体库弹窗（admin/view/popup/media.php）
     * 选中后由弹窗内「确定」回调 onPick(url)；提示用宿主窗口的 layer.msg。
     *
     * @param {function(string):void} onPick 选中回调
     * @param {Object} [opts] 传给 layer.open 的额外选项（可覆盖 area 等）
     * @param {Window} [host=window] 在哪个窗口打开（嵌套弹窗场景传 parent）
     * @returns {*} layer index
     */
    function emOpenMediaPicker(onPick, opts, host) {
        host = host || window;
        var layerApi = (host.layui && host.layui.layer) ? host.layui.layer : host.layer;
        if (!layerApi) {
            return;
        }
        host.__emMediaPickCallback = function (url) {
            host.__emMediaPickCallback = null;
            if (typeof onPick === 'function') {
                onPick(url);
            }
        };
        var w = host.innerWidth || 800;
        var h = host.innerHeight || 600;
        var mediaUrl = (host.TEMPLATE_MEDIA_URL || window.TEMPLATE_MEDIA_URL || '/admin/media.php');
        if (typeof mediaUrl === 'string' && mediaUrl.indexOf('?') !== -1) {
            mediaUrl = mediaUrl.split('?')[0];
        }
        var conf = {
            type: 2,
            title: '选择图片',
            skin: 'admin-modal',
            maxmin: true,
            area: [w >= 800 ? '700px' : '95%', h >= 500 ? '500px' : '80%'],
            shadeClose: false,
            content: mediaUrl
        };
        if (opts) {
            for (var k in opts) {
                if (Object.prototype.hasOwnProperty.call(opts, k)) {
                    conf[k] = opts[k];
                }
            }
        }
        return layerApi.open(conf);
    }
    window.emOpenMediaPicker = emOpenMediaPicker;

    /**
     * 列表页搜索表单：统一处理回车 / 移动端软键盘「搜索」键，避免焦点跳到 layui 分页跳转框。
     * - form.em-quick-search / form.em-filter__form / form.em-list-search
     * - data-em-search-btn="#searchBtnId" 时提交后触发对应按钮 click（搜索钮请用 type="button"）
     * - 无 data-em-search-btn 时触发 form 上的 em:search 自定义事件
     */
    function bindListSearchForms() {
        $(document).on('submit.emListSearch', 'form.em-quick-search, form.em-filter__form, form.em-list-search', function (e) {
            e.preventDefault();
            var $form = $(this);
            $form.find('input[type="search"], input[data-em-search-input]').first().blur();
            var btnSel = $form.attr('data-em-search-btn');
            if (btnSel) {
                $(btnSel).trigger('click');
            } else {
                $form.trigger('em:search');
            }
        });

        $(document).on('keydown.emListSearch', 'form.em-quick-search input, form.em-filter__form input[type="search"], form.em-list-search input[type="search"], form.em-list-search input[data-em-search-input]', function (e) {
            if (e.key !== 'Enter' && e.which !== 13) return;
            if (e.originalEvent && e.originalEvent.isComposing) e.preventDefault();
        });
    }

    $(function () {
        initMenuState();
        bindMenuToggle();
        bindSidebarToggle();
        bindUserMenu();
        bindLangSwitch();
        bindClearCache();
        bindImagePreview();
        bindListSearchForms();
        initPjax();

        // 手机端加载时，清除服务端渲染的 PC 折叠状态
        if ($(window).width() <= 1024) {
            $('#adminSidebar').removeClass('is-collapsed');
            $(document.body).removeClass('sidebar-pre-collapsed');
        }

        setActiveMenu(window.location.href);
        // 若无匹配（落在 /admin/index.php 首页），手动高亮控制台
        if (!$('.admin-menu-item.is-active').length) {
            var $dashboard = $('.admin-sidebar__body > .admin-menu-item[href*="home"]');
            if ($dashboard.length) {
                $dashboard.addClass('is-active');
            }
        }
    });
})(jQuery);
