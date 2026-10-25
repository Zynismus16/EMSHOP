<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * 正版授权 - 管理页
 *
 * 功能：
 *   - 显示当前站点授权状态
 *   - 粘贴激活码激活
 *   - 购买 VIP / SVIP / 至尊（跳转到授权服务器付款页）
 *   - 手动撤销当前授权（换码 / 迁站前用）
 *   - 手动触发"校验"（向服务器确认激活状态没被撤销）
 *   - 历史激活记录
 */
adminRequireLogin();
$user = $adminUser;
$siteName = Config::get('sitename', 'EMSHOP');

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
// 弹窗（渲染完整 HTML，layer.open type:2 加载），数据源都是 base-data：
//   ?_popup=agent    获取正版授权码 —— 只放购买渠道 buy_links
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

// ============================================================
// 正常视图
// ============================================================
// 进入授权页前先跟服务端核对一次当前域名的授权状态
// 服务端明确判定未激活 → 自动删除本地记录；网络异常 → 保守保留
LicenseService::revalidateCurrent();

$current = LicenseService::currentLicense();

// var_dump($current);

// 线路列表 + 当前索引
$lines = LicenseService::getAllLines();
$currentLineIndex = LicenseService::currentLineIndex();

$csrfToken = Csrf::token();

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/license.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/license.php';
    require __DIR__ . '/index.php';
}
