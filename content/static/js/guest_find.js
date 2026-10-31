/**
 * 游客查单模式公共 JS 组件。
 *
 * 使用方式：
 *   GuestFind.init({
 *     currencySymbol: '¥',
 *     onSubmitOrder: function(postData, callback) { ... },
 *     onQuerySuccess: function(orders) { ... },
 *   });
 *
 * 页面需在 DOM 中提供以下元素：
 *   #guestFindToggleBtn     - 切换按钮
 *   #guestFindContactSection - 联系方式查单区域
 *   #guestFindPasswordSection - 订单密码查单区域
 *   #tabToken / #tabContact / #tabPassword - 选项卡按钮
 *   #panelToken / #panelContact / #panelPassword - 选项卡面板
 *   #findOrderResults / #resultsList / #resultsCount - 结果区域
 *
 * 联系方式表单（#guestFindContactSection 内部）：
 *   #guestFindContactOrder  - 订单编号输入
 *   #guestFindContactQuery - 联系方式输入
 *   #guestFindContactType  - 联系方式类型 hidden input
 *
 * 订单密码表单（#guestFindPasswordSection 内部）：
 *   #guestFindPasswordOrder - 订单编号输入
 *   #guestFindPasswordQuery - 订单密码输入
 */
var GuestFind = (function () {
    var opts = {};
    var $doc = $(document);
    var global = typeof window !== 'undefined' ? window : this;

    // 订单状态映射
    var STATUS_MAP = {
        'pending':    ['待付款',    '#f59e0b'],
        'paid':      ['已付款',    '#1890ff'],
        'delivering': ['发货中',   '#1890ff'],
        'delivered': ['已发货',    '#52c41a'],
        'completed': ['已完成',    '#52c41a'],
        'cancelled': ['已取消',    '#999'],
        'expired':   ['已过期',    '#999'],
        'refunding': ['退款中',    '#faad14'],
        'refunded':  ['已退款',    '#52c41a'],
        'failed':    ['失败',      '#f5222d'],
    };

    function escHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // 渲染查询结果
    // options.autoScroll = false 时不滚动到结果区（用于自动加载浏览器订单，避免进页就被滚动）
    function renderResults(orders, options) {
        options = options || {};
        if (!orders || orders.length === 0) {
            layer.msg('未找到订单');
            return;
        }

        $('#resultsCount').text(orders.length);
        var html = '';
        for (var i = 0; i < orders.length; i++) {
            var order = orders[i];
            var statusInfo = STATUS_MAP[order.status] || [order.status, '#999'];
            var awaitingAsync = order.status === 'paid' || order.status === 'delivering';
            var statusCls = 'find-order-status' + (awaitingAsync ? ' find-order-status--awaiting' : '');

            html += '<div class="find-order-item">';
            html += '<div class="find-order-item-header">';
            html += '<span class="find-order-no">订单号：' + escHtml(order.order_no) + '</span>';
            html += '<span class="' + statusCls + '" style="background:' + statusInfo[1] + '20;color:' + statusInfo[1] + ';">';
            if (awaitingAsync) {
                html += '<i class="fa fa-spinner fa-spin find-order-status__spin" aria-hidden="true"></i> ';
            }
            html += statusInfo[0] + '</span>';
            html += '</div>';

            if (order.order_goods && order.order_goods.length > 0) {
                for (var j = 0; j < order.order_goods.length; j++) {
                    var g = order.order_goods[j];
                    html += '<div class="find-order-goods">';
                    if (g.cover_image) {
                        html += '<img src="' + escHtml(g.cover_image) + '" class="find-order-goods-img" alt="">';
                    }
                    html += '<div class="find-order-goods-info">';
                    html += '<div class="find-order-goods-name">' + escHtml(g.goods_title) + '</div>';
                    if (g.spec_name) {
                        html += '<div class="find-order-goods-spec">' + escHtml(g.spec_name) + '</div>';
                    }
                    html += '</div>';
                    html += '<div class="find-order-goods-price">' + opts.currencySymbol + parseFloat(g.price / 1000000).toFixed(2) + ' × ' + g.quantity + '</div>';
                    html += '</div>';
                }
            }

            html += '<div class="find-order-item-footer">';
            html += '<span class="find-order-time">下单时间：' + escHtml(order.created_at || '') + '</span>';
            html += '<span class="find-order-total">实付：<strong style="color:#fa5252;">' + opts.currencySymbol + order.pay_amount_display + '</strong></span>';
            html += '</div>';

            // 页面级回调，可自定义操作按钮
            if (opts.onRenderActions) {
                html += '<div class="find-order-actions">';
                html += opts.onRenderActions(order);
                html += '</div>';
            } else {
                html += '<div class="find-order-actions">';
                // 查单页是游客场景，详情走同一个 find_order.php（独立壳，无侧边栏），
                // 不跳 /user/order_detail.php（那里走个人中心 index_public 壳，视觉上带左侧菜单区域）
                // data-pjax：查单页启用了 PJAX，详情在 #foContent 内渲染，不整页刷新
                html += '<a href="/user/find_order.php?order_no=' + encodeURIComponent(order.order_no) + '" data-pjax class="find-order-action-btn">查看详情</a>';
                html += '</div>';
            }

            html += '</div>';
        }

        $('#resultsList').html(html);
        $('#findOrderResults').show();
        if (options.autoScroll !== false) {
            $('html,body').animate({ scrollTop: $('#findOrderResults').offset().top - 20 }, 300);
        }

        if (global.EMSOrderPoll && options.meta && options.meta.pending_delivery_hash
            && options.meta.pending_delivery_hash !== 'empty'
            && options.meta.poll_order_nos && options.meta.poll_order_nos.length) {
            var csrfTok = (typeof global.findOrderCsrfToken === 'string' && global.findOrderCsrfToken)
                ? global.findOrderCsrfToken : '';
            if (csrfTok) {
                global.EMSOrderPoll.stopFindSnapshot();
                global.EMSOrderPoll.startFindSnapshot({
                    orderNos: options.meta.poll_order_nos,
                    csrfToken: csrfTok,
                    initialHash: options.meta.pending_delivery_hash
                });
            }
        }

        // 页面级回调
        if (opts.onQuerySuccess) {
            opts.onQuerySuccess(orders);
        }
    }

    // 提交查单请求
    function submitFind(mode, data, $btn) {
        // beforeSubmit：页面级钩子，可注入 captcha 等额外字段，
        // 返回 false 表示前端校验未通过、终止提交（如未填验证码）
        if (opts.beforeSubmit) {
            var modified = opts.beforeSubmit(data, mode);
            if (modified === false) return;
            if (modified && typeof modified === 'object') data = modified;
        }

        var origHtml = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 查询中...');

        $.post('/user/find_order.php', data, function (res) {
            $btn.prop('disabled', false).html(origHtml);
            if (res.code === 200) {
                if (opts.onSubmitOrder) {
                    opts.onSubmitOrder(res.data, function (data, ropt) {
                        renderResults(data, ropt || { meta: res.meta });
                    }, res.meta);
                } else {
                    renderResults(res.data, { meta: res.meta });
                }
            } else {
                // onError：让页面有机会在错误时刷新 captcha 等 UI 状态
                if (opts.onError) opts.onError(res, mode);
                layer.msg(res.msg || '查询失败');
            }
        }, 'json').fail(function () {
            $btn.prop('disabled', false).html(origHtml);
            if (opts.onError) opts.onError({code: 0, msg: '网络错误'}, mode);
            layer.msg('网络错误');
        });
    }

    function initEvents() {
        // 切换选项卡：根据 data-mode 找到对应面板显示，隐藏其余
        // 支持的 mode：token / credentials / orderno
        var panelMap = {
            token: '#panelToken',
            credentials: '#panelCredentials',
            orderno: '#panelOrderNo'
        };
        // 单页 tab 切换（仅 button.find-order-tab 形式生效）。
        // 查单独立页改成 a[data-pjax] 后由 PJAX 接管整个 #foContent 替换，
        // 这里要主动跳过带 data-pjax 的链接，避免在 PJAX 加载途中错误地 hide 掉旧 panel。
        $doc.on('click.findGuestFind', '.find-order-tab', function () {
            if ($(this).is('[data-pjax]')) return;
            var mode = $(this).data('mode');
            $('.find-order-tab').removeClass('active');
            $(this).addClass('active');
            $('.find-order-panel').hide();
            if (panelMap[mode]) $(panelMap[mode]).show();
            $('#findOrderResults').hide();
        });

        // tab2：凭据查单（无订单号）：后台开启的联系方式/订单密码均为必填项，
        // 两项都开启时都要填；前端不做必填校验，让后端统一返回错误消息
        $doc.on('click.findGuestFind', '#credentialsSubmitBtn', function () {
            submitFind('credentials', {
                mode: 'credentials',
                contact_query: ($('#credContactQuery').val() || '').trim(),
                order_password: ($('#credPasswordQuery').val() || '').trim()
            }, $(this));
        });

        // tab3：仅订单编号查单（无需凭据）
        $doc.on('click.findGuestFind', '#orderNoSubmitBtn', function () {
            submitFind('orderno', {
                mode: 'orderno',
                order_no: ($('#orderNoInput').val() || '').trim()
            }, $(this));
        });
    }

    return {
        /**
         * 初始化组件。
         * @param {object} options
         * @param {string} options.currencySymbol 货币符号
         * @param {function} [options.onQuerySuccess] 查询成功回调 (orders) => void
         * @param {function} [options.onSubmitOrder] 自定义提交处理 (orders, renderFn) => void
         * @param {function} [options.onRenderActions] 自定义操作按钮 (order) => string HTML
         * @param {function} [options.onInit] 初始化完成回调 () => void
         */
        init: function (options) {
            opts = options || {};

            // 解绑旧事件，防止 PJAX 后重复绑定
            $doc.off('.findGuestFind');

            initEvents();

            if (opts.onInit) {
                opts.onInit();
            }
        },

        /**
         * 获取 guestFindData 从表单。
         * 调用时机：在提交订单前，收集当前可见的查单表单数据。
         *
         * 下单页只有查单凭据字段（联系方式/密码），没有订单号字段；
         * 订单号字段仅在查单页存在，collectData 仅在对应元素存在时才加入。
         * @returns {object}
         */
        collectData: function () {
            var data = {};
            var $contactSection = $('#guestFindContactSection');
            var $passwordSection = $('#guestFindPasswordSection');

            if ($contactSection.length && !$contactSection.is(':hidden')) {
                var cQuery = ($('#guestFindContactQuery').val() || '').trim();
                var cType = $('#guestFindContactType').val() || 'any';
                data.guest_find_contact_query = cQuery;
                data.guest_find_contact_type = cType;
                var $cOrder = $('#guestFindContactOrder');
                if ($cOrder.length) {
                    data.guest_find_contact_order = ($cOrder.val() || '').trim();
                }
            }

            if ($passwordSection.length && !$passwordSection.is(':hidden')) {
                var pQuery = ($('#guestFindPasswordQuery').val() || '').trim();
                data.guest_find_password_query = pQuery;
                var $pOrder = $('#guestFindPasswordOrder');
                if ($pOrder.length) {
                    data.guest_find_password_order = ($pOrder.val() || '').trim();
                }
            }

            return data;
        },

        /**
         * 验证查单表单（返回错误信息或空字符串）。
         * 下单页只验证查单凭据本身；订单号仅在查单页出现时才校验。
         * @returns {string}
         */
        validate: function () {
            var $contactSection = $('#guestFindContactSection');
            var $passwordSection = $('#guestFindPasswordSection');

            if ($contactSection.length && !$contactSection.is(':hidden')) {
                var $cOrder = $('#guestFindContactOrder');
                if ($cOrder.length && !($cOrder.val() || '').trim()) return '请输入订单编号';
                var cQuery = ($('#guestFindContactQuery').val() || '').trim();
                var cType = ($('#guestFindContactType').val() || 'any').toLowerCase();
                if (!cQuery) {
                    if (cType === 'phone') return '请输入手机号码';
                    if (cType === 'email') return '请输入邮箱地址';
                    if (cType === 'qq') return '请输入QQ号码';
                    return '请输入联系方式';
                }
                if (cType === 'phone' && !/^1[3-9]\d{9}$/.test(cQuery)) {
                    return '请输入正确的手机号码';
                }
                if (cType === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(cQuery)) {
                    return '请输入正确的邮箱地址';
                }
                if (cType === 'qq' && !/^[1-9]\d{4,10}$/.test(cQuery)) {
                    return '请输入正确的QQ号码';
                }
            }

            if ($passwordSection.length && !$passwordSection.is(':hidden')) {
                var $pOrder = $('#guestFindPasswordOrder');
                if ($pOrder.length && !($pOrder.val() || '').trim()) return '请输入订单编号';
                if (!($('#guestFindPasswordQuery').val() || '').trim()) return '请输入订单密码';
            }

            return '';
        },

        /**
         * 直接渲染订单结果（供外部调用）。
         */
        renderResults: renderResults,
    };
})();
