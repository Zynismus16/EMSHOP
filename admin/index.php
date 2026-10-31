<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 后台首页入口。
 *
 * 负责渲染后台框架，并承载 Pjax 内容区。
 */
if ((string) Input::get('action', '') === 'logout') {
    // 先判定本次请求是否真的持有登录态：未登录者访问本地址时，跳转**不能**带 ?s= 密钥，
    // 否则任何人一个 GET 就能从 Location 头里读走安全入口。
    $wasLoggedIn = $auth->check();
    $auth->logout();
    // 退出后带上安全入口参数，方便再次登录；未登录误入后台时仍不带密钥
    Response::redirect(adminSignUrl($wasLoggedIn));
}

// 清除缓存
if ((string) Input::post('_action', '') === 'clear_cache') {
    adminRequireLogin();
    // 此前只把 token 读进 $csrf 却从未校验，等于该接口没有 CSRF 防护
    if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
        Response::error('请求已失效，请刷新页面后重试');
    }

    Cache::clear();
    Response::success('缓存已清空');
}

// 免责协议签署：记录协议版本、签署管理员、时间与 IP
if ((string) Input::get('_action', '') === 'disclaimer_accept') {
    adminRequireLogin();
    if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
        Response::error('请求已失效，请刷新页面后重试');
    }
    if ((string) Input::post('agree', '0') !== '1') {
        Response::error('请先阅读并同意免责协议');
    }

    $saved = DisclaimerService::accept(
        (int) $adminUser['id'],
        (string) $adminUser['username'],
        RateLimit::clientIp()
    );
    if (!$saved) {
        Response::error('签署记录保存失败，请稍后重试');
    }
    Response::success('已签署');
}

// 插件动作分发：允许插件通过钩子处理自定义后台 action，避免在核心代码中写插件逻辑
$action = (string) Input::get('_action', '');
if ($action !== '' && $action !== 'clear_cache') {
    adminRequireLogin();
    // 触发钩子 admin_plugin_action_{action}，由插件自行处理并 exit
    doAction('admin_plugin_action_' . $action);
}

adminRequireLogin();
$user = $adminUser;

// 刷新 session，确保获取最新头像等资料
$userModel = new UserModel();
$freshUser = $userModel->findById((int) $user['id']);
if ($freshUser) {
    $auth->refreshSession($freshUser);
    $user = $freshUser;
}

$siteName = Config::get('sitename', 'EMSHOP');

// 升级包根目录 `.server` 空文件：存在则提示管理员硬重启任务服务（主进程启动后由 server 入口删除）。
//
// 必须叠加宿主心跳：这个文件**只**由 CliServerManager::start() 删除，纯 FPM 站根本没有
// 常驻进程需要重启，文件却会永久残留 —— 于是每次整页加载都弹一次「需要重启任务服务」，
// 而用户照做也无从照做（他没跑过 php server start）。没有进程在跑时就不该提醒。
//
// 判据用宿主心跳（有进程在跑）而不是首页那张卡的 CAPABILITY_DELIVERY（有发货消费者）：
// 管家活着、发货 worker 卡死时卡片显示的是 FPM，但那恰恰是最该硬重启的场景。
$emServerHardRestartPending = is_file(EM_ROOT . '/.server') && WorkerHeartbeat::hostAlive();

// 获取语言列表供顶部导航渲染
$langModel = new LanguageModel();
$languages = $langModel->getEnabled();

// 子页面可设置 $adminContentView 指定内容区视图，默认为控制台首页
if (empty($adminContentView)) {
    $adminContentView = __DIR__ . '/view/home.php';
    // 默认进入控制台时跟服务端核对一次授权状态（其他子页面由各自 controller 触发）
    LicenseService::revalidateCurrent();
}

$viewFile = __DIR__ . '/view/index.php';
require $viewFile;
