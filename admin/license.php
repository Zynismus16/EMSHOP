<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * 正版授权 - 接口 / 弹窗入口（独立页面已下线）
 *
 * 这个文件现在只承担两件事：
 *   - 处理授权相关的 POST 动作（激活 / 解绑 / 校验 / 购买 / 线路切换 / 官方账号登录注册等）
 *   - 渲染 ?_popup= 弹窗（agent 购买渠道 / contact 联系客服 / account 官方账号管理）
 *
 * 激活、获取激活码、官方账号管理已经改成后台首页与应用商店里的弹窗；
 * 直接访问 /admin/license.php 会跳回后台首页。
 */
adminRequireLogin();

if (Request::isPost()) {
    try {
        // 本分支的动作全是写操作（激活 / 解绑 / 重新校验 / 保存别名 / 购买 / 切换线路 /
        // 代理配置），都会改动授权状态，必须校验 CSRF。
        // 前端（admin/view/license.php 的 post() 包装、admin/view/index.php 的线路切换）
        // 都已经在请求里带上 csrf_token。
        if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $action = (string) Input::post('_action', '');

        switch ($action) {
            case 'activate':
                $code = trim((string) Input::post('license_code', ''));
                $result = LicenseService::activate($code);
                Response::success('激活成功：' . $result['level_label'], [
                    'level' => $result['level'],
                    'level_label' => $result['level_label'],
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            case 'unbind':
                LicenseService::unbind();
                Response::success('已解绑主授权域名', ['csrf_token' => Csrf::refresh()]);
                break;

            case 'revalidate':
                LicenseService::revalidateCurrent();
                Response::success('已完成校验', ['csrf_token' => Csrf::refresh()]);
                break;

            case 'save_aliases':
                $raw = (string) Input::post('aliases', '');
                $saved = LicenseService::saveAliasHosts($raw);
                Response::success('已保存', [
                    'aliases'    => $saved,
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            case 'buy':
                $level = (string) Input::post('level', 'vip');
                if (!in_array($level, ['vip', 'svip', 'supreme'], true)) {
                    Response::error('无效的授权等级');
                }
                $url = LicenseService::getBuyUrl(
                    $level,
                    (string) ($adminUser['email'] ?? '')
                );
                Response::success('', ['url' => $url]);
                break;

            case 'switch_line':
                $idx = (int) Input::post('index', 0);
                LicenseService::switchLine($idx);
                $lines = LicenseService::getAllLines();
                Response::success('已切换到：' . ($lines[$idx]['name'] ?? ''), [
                    'current_index' => $idx,
                    'current_name' => $lines[$idx]['name'] ?? '',
                    'current_url' => $lines[$idx]['url'] ?? '',
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            // 官方账号：登录官方站前台账号（与激活码无关），只存 token 与展示信息
            case 'official_login':
                $account  = trim((string) Input::post('account', ''));
                $password = (string) Input::post('password', '');
                $profile  = LicenseService::loginOfficialAccount($account, $password);
                Response::success('登录成功', [
                    'user'       => $profile,
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            // 验证码登录：邮箱 + 邮箱验证码（免密码）
            case 'official_login_code':
                $profile = LicenseService::loginOfficialAccountByCode(
                    (string) Input::post('email', ''),
                    (string) Input::post('code', '')
                );
                Response::success('登录成功', [
                    'user'       => $profile,
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            case 'official_logout':
                LicenseService::logoutOfficialAccount();
                Response::success('已退出官方账号', ['csrf_token' => Csrf::refresh()]);
                break;

            // 官方账号验证码：scene=register（注册）/ login（验证码登录）
            case 'official_email_code':
                LicenseService::sendOfficialEmailCode(
                    (string) Input::post('email', ''),
                    (string) Input::post('scene', 'register')
                );
                Response::success('验证码已发送，请查收邮箱', ['csrf_token' => Csrf::refresh()]);
                break;

            case 'official_register':
                $profile = LicenseService::registerOfficialAccount(
                    (string) Input::post('email', ''),
                    (string) Input::post('password', ''),
                    (string) Input::post('password_confirmation', '')
                );
                Response::success('注册成功', [
                    'user'       => $profile,
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            // 购买渠道 + 联系方式：给应用商店的「获取正版激活码」弹窗用（与首页同一个数据源）
            case 'agent_links':
                $base = LicenseService::fetchBaseData();
                Response::success('', [
                    'buy_links'     => $base['buy_links'] ?? [],
                    'used_fallback' => !empty($base['used_fallback']),
                    'contact'       => $base['contact'] ?? [],
                    'csrf_token'    => Csrf::refresh(),
                ]);
                break;

            // 官方账号名下的激活码列表：实时向官方站 /api/my/licenses 拉取
            case 'official_licenses':
                Response::success('', [
                    'licenses'   => LicenseService::officialAccountLicenses(),
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            // 官方账号个人资料：实时向官方站 /api/auth/me 拉取（只读，不轮换 CSRF）
            case 'official_profile':
                Response::success('', [
                    'user' => LicenseService::officialAccountProfile(),
                ]);
                break;

            // 官方账号概况：资料 + 激活码数量一次拿齐（宫格页只发这一个请求）
            case 'official_overview':
                $overview = LicenseService::officialAccountOverview();
                Response::success('', [
                    'user'           => $overview['user'] ?? [],
                    'licenses_count' => (int) ($overview['licenses_count'] ?? 0),
                ]);
                break;

            // 余额变动明细（只读，余额和列表同一次返回）
            case 'official_balance_logs':
                Response::success('', LicenseService::officialAccountBalanceLogs(
                    (int) Input::post('page', 1),
                    (int) Input::post('per_page', 10)
                ));
                break;

            // 添加激活码：绑定到当前官方账号（已被其他账号绑定的由上游拒绝）
            case 'official_add_license':
                $result = LicenseService::officialAccountBindLicense((string) Input::post('code', ''));
                Response::success('绑定成功', [
                    'license'    => $result['license'] ?? [],
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            // 移除授权：清空上游该激活码的域名，并清掉本机对应的授权状态
            case 'official_remove_license':
                $result = LicenseService::officialAccountRemoveLicense((string) Input::post('code', ''));
                Response::success('已移除授权', [
                    'license'       => $result['license'] ?? [],
                    'local_cleared' => !empty($result['local_cleared']),
                    'csrf_token'    => Csrf::refresh(),
                ]);
                break;

            // 重置授权码：换新码（旧码立即失效），本机在用的码同步更新
            case 'official_reset_license':
                $result = LicenseService::officialAccountResetLicense((string) Input::post('code', ''));
                Response::success('已重置授权码', [
                    'license'       => $result['license'] ?? [],
                    'local_updated' => !empty($result['local_updated']),
                    'csrf_token'    => Csrf::refresh(),
                ]);
                break;

            // 代理配置：实时向官方站 /api/my/config 拉取，本站不保存
            case 'official_agent_config':
                Response::success('', [
                    'config' => LicenseService::officialAccountAgentConfig(),
                ]);
                break;

            // 激活码仓库：列表 + 价格表 + 余额（代理专属，角色由上游校验）
            case 'official_em_codes':
                Response::success('', LicenseService::officialAccountEmCodes(
                    (int) Input::post('page', 1),
                    (int) Input::post('per_page', 10),
                    (string) Input::post('usage', 'unused')
                ));
                break;

            // 生成激活码：按档位单价从上游账号余额扣费
            case 'official_em_codes_generate':
                $generated = LicenseService::officialAccountGenerateEmCodes(
                    (string) Input::post('type', ''),
                    (int) Input::post('count', 1)
                );
                Response::success('已生成激活码', [
                    'codes'      => $generated['codes'],
                    'cost'       => $generated['cost'],
                    'balance'    => $generated['balance'],
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            // 代理版本安装包：只列 EMSHOP 已发布版本
            case 'official_versions':
                Response::success('', LicenseService::officialAccountVersions(
                    (int) Input::post('page', 1),
                    (int) Input::post('per_page', 10)
                ));
                break;

            // 代理订单记录（只读，归属由上游按 token 过滤）
            case 'official_orders':
                Response::success('', LicenseService::officialAccountOrders([
                    'page'     => (int) Input::post('page', 1),
                    'per_page' => (int) Input::post('per_page', 10),
                    'keyword'  => (string) Input::post('keyword', ''),
                    /* 只做 EMSHOP：上游这一页支持 em / tt，这里固定 em */
                    'project'  => 'em',
                    'state'    => (string) Input::post('state', ''),
                ]));
                break;

            // 我的广告位（只做 EMSHOP）
            case 'official_ad_slots':
                Response::success('', LicenseService::officialAccountAdSlots([
                    'page'     => (int) Input::post('page', 1),
                    'per_page' => (int) Input::post('per_page', 10),
                    'keyword'  => (string) Input::post('keyword', ''),
                    'state'    => (string) Input::post('state', ''),
                ]));
                break;

            case 'official_ad_slot_detail':
                Response::success('', [
                    'slot' => LicenseService::officialAccountAdSlotDetail((int) Input::post('id', 0)),
                ]);
                break;

            case 'official_ad_slot_save':
                $slotId = (int) Input::post('id', 0);
                $savedSlot = LicenseService::officialAccountAdSlotSave($slotId > 0 ? $slotId : null, [
                    'title'       => (string) Input::post('title', ''),
                    'label'       => (string) Input::post('label', ''),
                    'label_color' => (string) Input::post('label_color', ''),
                    'content'     => (string) Input::post('content', ''),
                    'link_url'    => (string) Input::post('link_url', ''),
                    'expires_on'  => (string) Input::post('expires_on', ''),
                    'status'      => (int) Input::post('status', 1),
                ]);

                Response::success('已保存', [
                    'slot'       => $savedSlot,
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            case 'official_ad_slot_status':
                LicenseService::officialAccountAdSlotStatus(
                    (int) Input::post('id', 0),
                    (int) Input::post('status', 1)
                );
                Response::success('已更新', ['csrf_token' => Csrf::refresh()]);
                break;

            case 'official_ad_slot_delete':
                LicenseService::officialAccountAdSlotDelete((int) Input::post('id', 0));
                Response::success('已删除', ['csrf_token' => Csrf::refresh()]);
                break;

            case 'official_ad_slot_move':
                LicenseService::officialAccountAdSlotMove(
                    (int) Input::post('id', 0),
                    (string) Input::post('direction', '')
                );
                Response::success('顺序已调整', ['csrf_token' => Csrf::refresh()]);
                break;

            // 安装记录（只读，归属由上游按 token 过滤）
            case 'official_install_records':
                Response::success('', LicenseService::officialAccountInstallRecords([
                    'page'     => (int) Input::post('page', 1),
                    'per_page' => (int) Input::post('per_page', 10),
                    'keyword'  => (string) Input::post('keyword', ''),
                    /* 只做 EMSHOP */
                    'project'  => 'em',
                ]));
                break;

            // 代理配置保存：同步写上游，必须校验登录密码
            case 'official_agent_config_save':
                $saved = LicenseService::officialAccountSaveAgentConfig([
                    'identity'          => (string) Input::post('identity', ''),
                    'qq_service'        => (string) Input::post('qq_service', ''),
                    'qq_group'          => (string) Input::post('qq_group', ''),
                    'wechat_service'    => (string) Input::post('wechat_service', ''),
                    'wechat_qr'         => (string) Input::post('wechat_qr', ''),
                    'tg_service'        => (string) Input::post('tg_service', ''),
                    'tg_group_url'      => (string) Input::post('tg_group_url', ''),
                    'em_buy_links'      => (string) Input::post('em_buy_links', ''),
                    'em_download_links' => (string) Input::post('em_download_links', ''),
                ], (string) Input::post('password', ''));

                Response::success('已保存', [
                    'config'     => $saved,
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            // 只读：后台线路列表展开时并发测一遍各线路延迟（不轮换 CSRF）
            case 'line_ping':
                Response::success('', [
                    'lines'         => LicenseClient::measureLatency(),
                    'current_index' => LicenseService::currentLineIndex(),
                ]);
                break;

            default:
                Response::error('未知操作');
        }
    } catch (RuntimeException $e) {
        Response::error($e->getMessage());
    } catch (Throwable $e) {
        Response::error($e->getMessage() ?: '系统繁忙');
    }
}

// ============================================================
// 版本源码包下载（GET）：本地把上游的 zip 代理下来，token 不出服务端。
// 下载不能带请求体，CSRF 令牌放查询串里校验。
// ============================================================
if ((string) Input::get('_download', '') === 'version') {
    if (!Csrf::validate((string) Input::get('csrf_token', ''))) {
        Response::error('请求已失效，请刷新页面后重试');
    }

    try {
        $source = LicenseService::officialAccountVersionSource((int) Input::get('id', 0));
    } catch (Throwable $e) {
        Response::error($e->getMessage() ?: '下载失败');
    }

    $body = (string) ($source['body'] ?? '');
    $disposition = str_replace(["", "
"], '', (string) ($source['disposition'] ?? ''));

    if ($disposition === '') {
        $disposition = 'attachment; filename="EMSHOP-source.zip"';
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: ' . $disposition);
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: no-store');
    echo $body;
    return;
}

// ============================================================
// 弹窗（渲染完整 HTML，layer.open type:2 加载），数据源都是 base-data：
//   ?_popup=agent    获取正版激活码 —— 只放购买渠道 buy_links
//   ?_popup=contact  联系客服       —— 只放联系方式 contact
// 两者内容刻意不重叠：授权页上这是两个语义不同的按钮，早先共用一份内容时
// 「联系客服」点开看到的是购买链接，属于用错内容。
// ============================================================
$popupMode = (string) Input::get('_popup', '');
if ($popupMode === 'agent' || $popupMode === 'contact') {
    $agent = null;
    $agentError = null;
    try {
        $agent = LicenseService::fetchBaseData();
    } catch (Throwable $e) {
        $agentError = $e->getMessage() ?: '获取授权信息失败';
    }
    include __DIR__ . '/view/popup/agent_config.php';
    return;
}

// 官方账号管理（大 iframe）：账号概览 + 功能宫格（个人资料 / 退出登录在卡片里）
if ($popupMode === 'account') {
    $officialAccount = LicenseService::officialAccount();
    $csrfToken       = Csrf::token();
    include __DIR__ . '/view/popup/official_account.php';
    return;
}

// 官方账号登录 / 注册（应用商店等需要账号身份的入口自动打开）
if ($popupMode === 'official_login') {
    $csrfToken   = Csrf::token();
    $initialTab  = (string) Input::get('tab', 'login');
    include __DIR__ . '/view/popup/official_login.php';
    return;
}

// 官方账号 · 我的激活码（宫格入口打开的功能窗口）
if ($popupMode === 'licenses') {
    $csrfToken        = Csrf::token();
    $officialLoggedIn = LicenseService::officialAccount() !== [];
    include __DIR__ . '/view/popup/official_licenses.php';
    return;
}

// 官方账号 · 余额变动明细（宫格「账户余额」打开的功能窗口）
if ($popupMode === 'balance_logs') {
    $csrfToken        = Csrf::token();
    $officialLoggedIn = LicenseService::officialAccount() !== [];
    include __DIR__ . '/view/popup/official_balance_logs.php';
    return;
}

// 官方账号 · 添加激活码（宫格「添加激活码」打开的输入窗口）
if ($popupMode === 'add_license') {
    $csrfToken        = Csrf::token();
    $officialLoggedIn = LicenseService::officialAccount() !== [];
    include __DIR__ . '/view/popup/official_add_license.php';
    return;
}

// 官方账号 · 代理配置（代理 / 管理账号才显示这张卡）
if ($popupMode === 'agent_settings') {
    $csrfToken        = Csrf::token();
    $officialLoggedIn = LicenseService::officialAccount() !== [];
    include __DIR__ . '/view/popup/official_agent_config.php';
    return;
}

// 官方账号 · 激活码仓库（代理 / 管理账号）
if ($popupMode === 'code_warehouse') {
    $csrfToken        = Csrf::token();
    $officialLoggedIn = LicenseService::officialAccount() !== [];
    include __DIR__ . '/view/popup/official_code_warehouse.php';
    return;
}

// 官方账号 · 版本安装包（代理 / 管理账号，只列 EMSHOP）
if ($popupMode === 'versions') {
    $csrfToken        = Csrf::token();
    $officialLoggedIn = LicenseService::officialAccount() !== [];
    include __DIR__ . '/view/popup/official_versions.php';
    return;
}

// 官方账号 · 用户订单记录（代理 / 管理账号）
if ($popupMode === 'orders') {
    $csrfToken        = Csrf::token();
    $officialLoggedIn = LicenseService::officialAccount() !== [];
    include __DIR__ . '/view/popup/official_orders.php';
    return;
}

// 官方账号 · 广告位管理（代理 / 管理账号，只做 EMSHOP）
if ($popupMode === 'ad_slots') {
    $csrfToken        = Csrf::token();
    $officialLoggedIn = LicenseService::officialAccount() !== [];
    include __DIR__ . '/view/popup/official_ad_slots.php';
    return;
}

// 官方账号 · 广告位表单（新建 / 编辑，独立 iframe 弹窗）
if ($popupMode === 'ad_slot_form') {
    $csrfToken        = Csrf::token();
    $officialLoggedIn = LicenseService::officialAccount() !== [];
    $slotId           = (int) Input::get('id', 0);
    include __DIR__ . '/view/popup/official_ad_slot_form.php';
    return;
}

// 官方账号 · 安装记录（代理 / 管理账号）
if ($popupMode === 'install_records') {
    $csrfToken        = Csrf::token();
    $officialLoggedIn = LicenseService::officialAccount() !== [];
    include __DIR__ . '/view/popup/official_install_records.php';
    return;
}

// ============================================================
// 独立页面已下线
// ============================================================
// 激活、获取激活码、官方账号管理都改成了后台首页 / 应用商店里的弹窗，
// 这个入口只保留 POST 动作和 ?_popup= 弹窗（上面），直接访问就回首页。
Response::redirect('/admin/home.php');
