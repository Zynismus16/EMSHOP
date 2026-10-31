<?php
if (!defined('EM_ROOT')) {
    exit('Access Denied');
}
/** @var string $csrfToken */
/** @var string $initialTab login | register */

$pageTitle  = '登录官方账号';
$initialTab = ($initialTab ?? 'login') === 'register' ? 'register' : 'login';

include __DIR__ . '/header.php';
?>

<div class="popup-inner olg-wrap">
    <div class="olg-card">
        <div class="olg-hero">
            <span class="olg-hero__icon" id="olgHeroIcon"><i class="fa fa-user-circle-o"></i></span>
            <span class="olg-hero__text">
                <span class="olg-hero__title" id="olgHeroTitle">登录官方账号</span>
                <span class="olg-hero__sub" id="olgHeroSub">管理授权、订单与服务</span>
            </span>
        </div>

        <div class="olg-tabs" role="tablist">
            <button type="button" class="olg-tab is-active" data-tab="login" role="tab" aria-selected="true">登录</button>
            <button type="button" class="olg-tab" data-tab="register" role="tab" aria-selected="false">注册</button>
        </div>

        <div class="olg-panel is-active" data-panel="login">
            <div class="olg-sub is-active" data-mode="password">
                <div class="form-group">
                    <label class="form-group__label" for="olgAccount">账号</label>
                    <div class="olg-field">
                        <i class="fa fa-user"></i>
                        <input type="text" class="form-group__input" id="olgAccount"
                               placeholder="邮箱或用户名" maxlength="120" autocomplete="username" spellcheck="false">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-group__label" for="olgPassword">密码</label>
                    <div class="olg-field">
                        <i class="fa fa-lock"></i>
                        <input type="password" class="form-group__input" id="olgPassword"
                               placeholder="登录密码" maxlength="64" autocomplete="current-password">
                    </div>
                </div>
                <button type="button" class="olg-submit" id="olgLogin">登录</button>
                <div class="olg-switch">
                    <a href="javascript:void(0);" data-mode="code">使用邮箱验证码登录</a>
                </div>
            </div>

            <div class="olg-sub" data-mode="code" hidden>
                <div class="form-group">
                    <label class="form-group__label" for="olgLoginEmail">邮箱</label>
                    <div class="olg-field">
                        <i class="fa fa-envelope-o"></i>
                        <input type="email" class="form-group__input" id="olgLoginEmail"
                               placeholder="邮箱" maxlength="120" autocomplete="email" spellcheck="false">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-group__label" for="olgLoginCode">验证码</label>
                    <div class="olg-code-row">
                        <div class="olg-field">
                            <i class="fa fa-shield"></i>
                            <input type="text" class="form-group__input" id="olgLoginCode"
                                   placeholder="6 位邮箱验证码" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                        </div>
                        <button type="button" class="olg-code-btn" id="olgLoginCodeBtn">获取验证码</button>
                    </div>
                </div>
                <button type="button" class="olg-submit" id="olgLoginCodeSubmit">登录</button>
                <div class="olg-switch">
                    <a href="javascript:void(0);" data-mode="password">使用账号密码登录</a>
                </div>
            </div>

            <div class="form-group__error" id="olgLoginError"></div>
        </div>

        <div class="olg-panel" data-panel="register" hidden>
            <div class="form-group">
                <label class="form-group__label" for="olgRegEmail">邮箱</label>
                <div class="olg-field">
                    <i class="fa fa-envelope-o"></i>
                    <input type="email" class="form-group__input" id="olgRegEmail"
                           placeholder="邮箱" maxlength="120" autocomplete="email" spellcheck="false">
                </div>
            </div>
            <div class="form-group">
                <label class="form-group__label" for="olgRegCode">验证码</label>
                <div class="olg-code-row">
                    <div class="olg-field">
                        <i class="fa fa-shield"></i>
                        <input type="text" class="form-group__input" id="olgRegCode"
                               placeholder="6 位邮箱验证码" maxlength="6" inputmode="numeric" autocomplete="one-time-code">
                    </div>
                    <button type="button" class="olg-code-btn" id="olgRegCodeBtn">获取验证码</button>
                </div>
            </div>
            <div class="form-group">
                <label class="form-group__label" for="olgRegPassword">密码</label>
                <div class="olg-field">
                    <i class="fa fa-lock"></i>
                    <input type="password" class="form-group__input" id="olgRegPassword"
                           placeholder="6-16 位密码" maxlength="16" autocomplete="new-password">
                </div>
            </div>
            <div class="form-group">
                <label class="form-group__label" for="olgRegConfirm">确认密码</label>
                <div class="olg-field">
                    <i class="fa fa-lock"></i>
                    <input type="password" class="form-group__input" id="olgRegConfirm"
                           placeholder="再输一次密码" maxlength="16" autocomplete="new-password">
                </div>
            </div>
            <button type="button" class="olg-submit" id="olgRegister">注册并登录</button>

            <div class="form-group__error" id="olgRegError"></div>
        </div>
    </div>
</div>

<style>
.olg-wrap { padding: 16px; }

.olg-card {
    padding: 18px 20px;
    background: #fff;
    border: 1px solid #eef0f4;
    border-radius: 14px;
    box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}

.olg-hero {
    display: flex; align-items: center; gap: 11px;
    margin-bottom: 16px;
}
.olg-hero__icon {
    width: 40px; height: 40px;
    flex-shrink: 0;
    border-radius: 12px;
    background: #eef2ff; color: #4f46e5;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
}
.olg-hero__text { min-width: 0; }
.olg-hero__title {
    display: block;
    font-size: 15px; font-weight: 600; color: #111827;
}
.olg-hero__sub {
    display: block;
    margin-top: 2px;
    font-size: 12px; color: #9ca3af;
}

.olg-tabs {
    display: flex; gap: 3px;
    padding: 3px;
    margin-bottom: 16px;
    background: #f1f5f9;
    border-radius: 8px;
}
.olg-tab {
    flex: 1; height: 32px;
    border: 0; border-radius: 6px;
    background: transparent;
    color: #64748b;
    font-size: 13px; cursor: pointer;
    transition: background 0.15s ease, color 0.15s ease, box-shadow 0.15s ease;
}
.olg-tab:hover { color: #4f46e5; }
.olg-tab.is-active {
    background: #fff;
    color: #4f46e5;
    font-weight: 600;
    box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12);
}

.olg-wrap .form-group { margin-bottom: 12px; }
.olg-wrap .form-group__label {
    margin-bottom: 5px;
    font-size: 12px; color: #6b7280;
}

.olg-field { position: relative; }
.olg-field > i {
    position: absolute; left: 11px; top: 50%;
    transform: translateY(-50%);
    font-size: 13px; color: #9ca3af;
    pointer-events: none;
}
.olg-field .form-group__input { height: 36px; padding-left: 33px; }

.olg-code-row { display: flex; gap: 8px; align-items: stretch; }
.olg-code-row .olg-field { flex: 1; min-width: 0; }
.olg-code-btn {
    flex-shrink: 0;
    height: 36px; padding: 0 10px;
    border: 1px solid #c7d2fe; border-radius: 6px;
    background: #eef2ff; color: #4f46e5;
    font-size: 12px; cursor: pointer;
    transition: background 0.15s ease;
}
.olg-code-btn:hover { background: #e0e7ff; }
.olg-code-btn:disabled { opacity: 0.6; cursor: not-allowed; }

.olg-submit {
    width: 100%; height: 38px;
    border: 0; border-radius: 8px;
    background: #4f46e5; color: #fff;
    font-size: 13.5px; cursor: pointer;
    transition: background 0.15s ease;
}
.olg-submit:hover { background: #4338ca; }
.olg-submit:disabled { background: #a5b4fc; cursor: not-allowed; }

.olg-switch { margin-top: 10px; text-align: center; font-size: 12px; }
.olg-switch a { color: #4f46e5; text-decoration: none; }
.olg-switch a:hover { text-decoration: underline; }

@media (max-width: 600px) {
    .olg-wrap { padding: 12px; }
    .olg-card { padding: 16px 14px; }
}
</style>

<script>
$(function () {
    layui.use(['layer'], function () {
        var layer = layui.layer;
        var csrfToken = <?= json_encode($csrfToken) ?>;
        var initialTab = <?= json_encode($initialTab) ?>;

        var timers = {};

        var HERO = {
            login: {
                icon: 'fa fa-user-circle-o',
                title: '登录官方账号',
                sub: '管理授权、订单与服务'
            },
            register: {
                icon: 'fa fa-user-plus',
                title: '注册官方账号',
                sub: '邮箱验证后创建，注册成功自动登录'
            }
        };

        function setHero(tab) {
            var hero = HERO[tab] || HERO.login;
            $('#olgHeroIcon i').attr('class', hero.icon);
            $('#olgHeroTitle').text(hero.title);
            $('#olgHeroSub').text(hero.sub);
        }

        /* 把内容高度告诉父层，让登录框高度跟着内容走（切换登录方式 / 注册会变高变矮） */
        function syncHeight() {
            try {
                var card = document.querySelector('.olg-card');
                if (!card) return;

                var wrap = document.querySelector('.olg-wrap');
                var style = wrap ? window.getComputedStyle(wrap) : null;
                var pad = style
                    ? (parseFloat(style.paddingTop) || 0) + (parseFloat(style.paddingBottom) || 0)
                    : 32;
                var height = Math.ceil(card.getBoundingClientRect().height + pad);

                if (window.parent && window.parent !== window &&
                    typeof window.parent.__officialLoginResize === 'function') {
                    window.parent.__officialLoginResize(height);
                }
            } catch (e) {}
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

        function notifyAndClose() {
            try {
                if (window.parent && window.parent !== window &&
                    typeof window.parent.__officialLoginDone === 'function') {
                    window.parent.__officialLoginDone();
                }
            } catch (e) {}

            closeSelf();
        }

        function errorBox(name) {
            return name === 'register' ? $('#olgRegError') : $('#olgLoginError');
        }

        function showError(name, msg) {
            errorBox(name).text(msg).addClass('is-visible');
            setTimeout(syncHeight, 30);
        }

        function clearError(name) {
            errorBox(name).removeClass('is-visible').text('');
            setTimeout(syncHeight, 30);
        }

        /* ---------- 选项卡 ---------- */
        $('.olg-tab').on('click', function () {
            var tab = String($(this).data('tab') || 'login');

            $('.olg-tab').removeClass('is-active').attr('aria-selected', 'false');
            $(this).addClass('is-active').attr('aria-selected', 'true');

            $('.olg-panel').removeClass('is-active').prop('hidden', true);
            $('.olg-panel[data-panel="' + tab + '"]').addClass('is-active').prop('hidden', false);

            setHero(tab);
            clearError(tab);
            setTimeout(syncHeight, 30);

            setTimeout(function () {
                $('.olg-panel[data-panel="' + tab + '"] input').first().trigger('focus');
            }, 20);
        });

        $('.olg-switch a').on('click', function () {
            var mode = String($(this).data('mode') || 'password');

            $('.olg-sub').removeClass('is-active').prop('hidden', true);
            $('.olg-sub[data-mode="' + mode + '"]').addClass('is-active').prop('hidden', false);
            clearError('login');
            setTimeout(syncHeight, 30);

            if (mode === 'password') {
                $('#olgAccount').trigger('focus');
            } else {
                $('#olgLoginEmail').trigger('focus');
            }
        });

        /* ---------- 发送验证码 ---------- */
        function sendCode(email, scene, $btn, name) {
            email = $.trim(email);
            if (email === '') {
                showError(name, '请先填邮箱');
                return;
            }
            if ($btn.prop('disabled')) return;

            $btn.prop('disabled', true).text('发送中…');

            post({ _action: 'official_email_code', email: email, scene: scene }).done(function (res) {
                if (!res || res.code !== 200) {
                    $btn.prop('disabled', false).text('获取验证码');
                    showError(name, (res && res.msg) || '发送失败');
                    return;
                }

                layer.msg('验证码已发送');

                var left = 60;
                $btn.text(left + 's');
                timers[name] = setInterval(function () {
                    left -= 1;
                    if (left <= 0) {
                        clearInterval(timers[name]);
                        delete timers[name];
                        $btn.prop('disabled', false).text('获取验证码');
                    } else {
                        $btn.text(left + 's');
                    }
                }, 1000);
            }).fail(function () {
                $btn.prop('disabled', false).text('获取验证码');
                showError(name, '网络异常，请稍后重试');
            });
        }

        $('#olgLoginCodeBtn').on('click', function () {
            sendCode($('#olgLoginEmail').val(), 'login', $(this), 'login');
        });

        $('#olgRegCodeBtn').on('click', function () {
            sendCode($('#olgRegEmail').val(), 'register', $(this), 'register');
        });

        /* ---------- 登录 / 注册 ---------- */
        function submitLoginPassword() {
            var $btn = $('#olgLogin');
            if ($btn.prop('disabled')) return;

            var account = $.trim(String($('#olgAccount').val() || ''));
            var password = String($('#olgPassword').val() || '');

            clearError('login');
            if (account === '') { showError('login', '请输入账号'); return; }
            if (password === '') { showError('login', '请输入密码'); return; }

            $btn.prop('disabled', true).text('登录中…');

            post({ _action: 'official_login', account: account, password: password }).done(function (res) {
                if (!res || res.code !== 200) {
                    $btn.prop('disabled', false).text('登录');
                    showError('login', (res && res.msg) || '登录失败');
                    return;
                }
                notifyAndClose();
            }).fail(function () {
                $btn.prop('disabled', false).text('登录');
                showError('login', '网络异常，请稍后重试');
            });
        }

        function submitLoginCode() {
            var $btn = $('#olgLoginCodeSubmit');
            if ($btn.prop('disabled')) return;

            var email = $.trim(String($('#olgLoginEmail').val() || ''));
            var code = $.trim(String($('#olgLoginCode').val() || ''));

            clearError('login');
            if (email === '') { showError('login', '请输入邮箱'); return; }
            if (code === '') { showError('login', '请输入验证码'); return; }

            $btn.prop('disabled', true).text('登录中…');

            post({ _action: 'official_login_code', email: email, code: code }).done(function (res) {
                if (!res || res.code !== 200) {
                    $btn.prop('disabled', false).text('登录');
                    showError('login', (res && res.msg) || '登录失败');
                    return;
                }
                notifyAndClose();
            }).fail(function () {
                $btn.prop('disabled', false).text('登录');
                showError('login', '网络异常，请稍后重试');
            });
        }

        function submitRegister() {
            var $btn = $('#olgRegister');
            if ($btn.prop('disabled')) return;

            var email = $.trim(String($('#olgRegEmail').val() || ''));
            var code = $.trim(String($('#olgRegCode').val() || ''));
            var password = String($('#olgRegPassword').val() || '');
            var confirm = String($('#olgRegConfirm').val() || '');

            clearError('register');
            if (email === '') { showError('register', '请输入邮箱'); return; }
            if (code === '') { showError('register', '请输入验证码'); return; }
            if (password.length < 6 || password.length > 16) {
                showError('register', '密码长度需为 6-16 位');
                return;
            }
            if (password !== confirm) { showError('register', '两次输入的密码不一致'); return; }

            $btn.prop('disabled', true).text('注册中…');

            post({
                _action: 'official_register',
                email: email,
                code: code,
                password: password,
                password_confirmation: confirm
            }).done(function (res) {
                if (!res || res.code !== 200) {
                    $btn.prop('disabled', false).text('注册并登录');
                    showError('register', (res && res.msg) || '注册失败');
                    return;
                }
                notifyAndClose();
            }).fail(function () {
                $btn.prop('disabled', false).text('注册并登录');
                showError('register', '网络异常，请稍后重试');
            });
        }

        $('#olgLogin').on('click', submitLoginPassword);
        $('#olgLoginCodeSubmit').on('click', submitLoginCode);
        $('#olgRegister').on('click', submitRegister);

        $('#olgAccount, #olgPassword').on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submitLoginPassword(); }
        });
        $('#olgLoginEmail, #olgLoginCode').on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submitLoginCode(); }
        });
        $('#olgRegEmail, #olgRegCode, #olgRegPassword, #olgRegConfirm').on('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submitRegister(); }
        });

        if (initialTab === 'register') {
            $('.olg-tab[data-tab="register"]').trigger('click');
        } else {
            setHero('login');
            $('#olgAccount').trigger('focus');
        }

        setTimeout(syncHeight, 50);
        setTimeout(syncHeight, 300);
        $(window).on('resize', syncHeight);
    });
});
</script>

<?php include __DIR__ . '/footer.php'; ?>
