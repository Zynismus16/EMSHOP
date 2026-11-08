/**
 * 商品详情页 / 下单相关的**核心接口层**（所有主题共用）。
 *
 * 为什么在核心目录：这里是与后端接口契约绑定的部分 ——
 *   - 下单        ?c=order&a=create
 *   - 券校验      ?c=coupon&a=check
 *   - 我的可用券  ?c=coupon&a=mine
 * 以及「下单成功后去哪」的分支（支付跳转 / 游客去查单页 / 登录去订单详情）。
 *
 * 主题侧只负责界面：按钮态、弹层、提示文案、以及用各主题自己的 class 拼的 HTML
 * （见 content/template/{主题}/main.js）。
 *
 * 依赖：jQuery
 */
var GoodsOrder = (function () {
    var ORDER_DETAIL_URL = '/user/order_detail.php?order_no=';
    var GUEST_FIND_URL   = '/user/find_order.php';

    /** 取当前会话的 CSRF token：优先页面注入，其次读 cookie（token.hmac 的第一段）。 */
    function readCsrfToken() {
        if (window.EMSHOP_CSRF_TOKEN) {
            return window.EMSHOP_CSRF_TOKEN;
        }
        var m = document.cookie.match(/(?:^|;\s*)em_csrf_token=([^;]+)/);
        if (!m) {
            return '';
        }
        try {
            return decodeURIComponent(m[1]).split('.')[0] || '';
        } catch (e) {
            return '';
        }
    }

    return {
        /**
         * 取当前用户「未使用、未过期、未失效」的券（下单页选券弹层用）。
         *
         * @param {function(Array)} onOk   成功回调，参数为券数组
         * @param {function(Object)} onFail 失败回调，参数为 {code, msg}
         */
        myCoupons: function (onOk, onFail) {
            $.get('?c=coupon&a=mine', function (res) {
                if (res.code !== 200) {
                    if (onFail) onFail(res);
                    return;
                }
                onOk((res.data && res.data.coupons) || []);
            }, 'json').fail(function () {
                if (onFail) onFail({ code: 0, msg: '网络异常' });
            });
        },

        /**
         * 校验券码 + 拿折扣预估。
         *
         * @param {Object} payload {code, goods_amount, goods_items}
         * @param {function(Object)} onOk   成功回调，参数为 res.data（含 discount / coupon）
         * @param {function(Object)} onFail 失败回调，参数为 {code, msg}
         */
        checkCoupon: function (payload, onOk, onFail) {
            $.post('?c=coupon&a=check', payload, function (res) {
                if (res.code !== 200) {
                    if (onFail) onFail(res);
                    return;
                }
                onOk(res.data || {});
            }, 'json').fail(function () {
                if (onFail) onFail({ code: 0, msg: '网络异常' });
            });
        },

        /**
         * 提交订单。成功后去哪由这里决定（与后端返回契约绑定），界面动作交回调。
         *
         * @param {Object} postData 订单数据（主题侧收集：商品/规格/数量/支付方式/券/查单/地址）
         * @param {Object} options  {isGuest: 是否游客}
         * @param {Object} callbacks {
         *     onFinish: 请求结束（成功或失败）时回调，主题用它恢复按钮态
         *     onPaid:   已支付（余额 / 0 元单）时回调，主题用它提示"支付成功"
         *     onError:  失败时回调，参数为 {code, msg}
         * }
         */
        create: function (postData, options, callbacks) {
            options = options || {};
            callbacks = callbacks || {};

            postData = postData || {};
            if (!postData.csrf_token) {
                var csrfToken = readCsrfToken();
                if (csrfToken) {
                    postData.csrf_token = csrfToken;
                }
            }

            $.post('?c=order&a=create', postData, function (res) {
                if (callbacks.onFinish) callbacks.onFinish();

                if (res.code !== 200) {
                    if (callbacks.onError) callbacks.onError(res);
                    return;
                }

                var data = res.data || {};
                // 未支付成功时的落地页：游客去查单页，登录用户去订单详情
                var fallbackUrl = options.isGuest
                    ? GUEST_FIND_URL
                    : (ORDER_DETAIL_URL + encodeURIComponent(data.order_no || ''));

                if (data.paid) {
                    if (callbacks.onPaid) callbacks.onPaid(data);
                    location.href = fallbackUrl;
                } else if (data.pay_url) {
                    location.href = data.pay_url;
                } else {
                    location.href = fallbackUrl;
                }
            }, 'json').fail(function () {
                if (callbacks.onFinish) callbacks.onFinish();
                if (callbacks.onError) callbacks.onError({ code: 0, msg: '网络异常' });
            });
        }
    };
})();
