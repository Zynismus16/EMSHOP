<?php

declare(strict_types=1);

require dirname(__DIR__) . '/init.php';

/**
 * 后台公共文件。
 *
 * 后台其它页面都可以直接引入本文件，统一获得初始化、登录校验、当前管理员信息等能力，
 * 避免每个后台页面重复写相同的认证代码。
 */
$auth = new AuthService();

/**
 * 当前已登录管理员信息。
 *
 * @var array<string, mixed>|null $adminUser
 */
$adminUser = null;

/**
 * 执行后台登录校验。
 */
function adminRequireLogin(): void
{
    global $auth, $adminUser;

    if (!$auth->check()) {
        Response::redirect(adminSignUrl());
    }

    $adminUser = $auth->user();

    // 老站 config.php 里没有安装时生成的 auth.secret：在这里补齐并写回，
    // 保证后续 CSRF 校验统一走带密钥的 cookie 兜底。必须放在页面自己的
    // CSRF 校验之前执行，否则第一次请求仍会落到旧的无密钥路径。
    CsrfSecret::ensure();

    // 当前请求作用域：主站后台固定 'main'
    // TemplateStorage / Storage / PluginModel 等"按 scope 存取"的组件会读这个全局
    $GLOBALS['__em_current_scope'] = 'main';

    // 演示模式：登录放行，写操作一律拦掉
    adminDemoGuard();
}

/**
 * 演示模式守卫：后台禁止一切写操作，只放行只读请求。
 *
 * 放在 adminRequireLogin() 里收口：后台除 sign.php（登录）之外的所有页面第一件事
 * 都是调 adminRequireLogin()，而它们自己的 POST/GET 处理都在其后，所以这里拦下就
 * 没有漏网的写入口（登录不经过本函数，演示账号照常能登）。
 *
 * 判据是"请求是否带 csrf_token"（GET 或 POST）：后台所有写入口（保存/删除/上下架/
 * 批量/上传/设置/升级）都必须过 CSRF 校验，而只读接口（layui list、summary、stats、
 * trend、search_users 等）不带 token。
 *
 * 不能直接拦 Request::isPost()——layui 表格的列表接口本身就是 POST（goods.php?_action=list），
 * 拦了整页表格都出不来；反过来 goods.php?_action=toggle_sale 这类写操作走的是 GET 链接，
 * 只拦 POST 又会漏。
 */
function adminDemoGuard(): void
{
    if (!defined('EM_DEMO_MODE') || !EM_DEMO_MODE) {
        return;
    }

    $token = trim((string) Input::get('csrf_token', ''));
    if ($token === '') {
        $token = trim((string) Input::post('csrf_token', ''));
    }
    if ($token === '') {
        return; // 不带 token：只读请求
    }

    // 少数只读接口会带 token（详情/弹窗类），逐个放行
    $readOnlyActions = [
        'view',            // system_log.php 查看日志详情
        'list',            // popup/merchant_open.php 商户选择器
        'get_plugin_form', // goods_edit.php 商品类型插件的配置表单
    ];
    $action = (string) Input::get('_action', '');
    if ($action === '') {
        $action = (string) Input::post('_action', '');
    }
    if (in_array(trim($action), $readOnlyActions, true)) {
        return;
    }

    Response::error('铁铁，演示模式下无权限执行该操作哦~');
}

/**
 * 构造登录页 URL。
 *
 * @param bool $includeEntryKey 为 true 时附带安全入口 ?s=（仅用于已登录场景如退出登录）；
 *                              默认 false，避免未登录跳转把密钥写进 Location 泄露。
 */
function adminSignUrl(bool $includeEntryKey = false): string
{
    if (!$includeEntryKey) {
        return '/admin/sign.php';
    }
    $key = trim((string) Config::get('admin_entry_key', ''));
    return $key === '' ? '/admin/sign.php' : '/admin/sign.php?s=' . urlencode($key);
}

/**
 * 安全入口守卫：用于 sign.php 等未登录也能访问的后台入口页。
 *
 * 行为：
 *   - 未配置 admin_entry_key 时：放行
 *   - 已配置时：从 GET.s（POST 表单也兼容）读 key，不匹配则输出 403 提示页并 exit
 *
 * 安全关键点：提示页 HTML 里**绝不**回显用户传入或配置里的 key，避免侧信道泄露。
 */
function adminEnforceEntryKey(): void
{
    $configKey = trim((string) Config::get('admin_entry_key', ''));
    if ($configKey === '') return;

    $provided = trim((string) Input::get('s', ''));
    if ($provided === '' && Request::isPost()) {
        $provided = trim((string) Input::post('s', ''));
    }
    if (hash_equals($configKey, $provided)) return;

    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="zh-CN"><head><meta charset="UTF-8"><title>访问受限</title>
<style>
body{margin:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;}
.box{max-width:420px;padding:40px 36px;background:#fff;border-radius:14px;box-shadow:0 10px 30px rgba(15,23,42,.08);text-align:center;}
.ico{width:64px;height:64px;margin:0 auto 18px;border-radius:16px;background:linear-gradient(135deg,#fef3c7,#fde68a);display:flex;align-items:center;justify-content:center;color:#d97706;font-size:28px;}
h1{font-size:18px;color:#111827;margin:0 0 8px;}
p{font-size:13px;color:#6b7280;line-height:1.7;margin:0;}
</style></head><body>
<div class="box">
    <div class="ico">🛡</div>
    <h1>请使用正确的入口登录面板</h1>
    <p>此后台已启用安全入口保护。<br>请通过设置的安全地址访问，不要直接访问此页面。</p>
</div></body></html><?php
    exit;
}

// ================================================================
// 翻译系统
// ================================================================


/**
 * 当前后台语言的翻译映射表。
 * key = translate (翻译键), value = content (翻译内容)
 *
 * @var array<string, string>
 */
$adminTranslations = [];

/**
 * 加载后台翻译数据。
 */
function loadAdminTranslations(): void
{
    global $adminTranslations;

    $langCode = $_COOKIE['admin_lang'] ?? '';

    $langModel = new LanguageModel();
    $lang = null;

    if ($langCode !== '') {
        $lang = $langModel->findByCode($langCode);
    }
    
    if ($lang === null) {
        $lang = $langModel->getDefault();
    }

    if ($lang === null) {
        return;
    }

    $translator = new LangModel();
    $rows = $translator->getByLangId((int) $lang['id']);

    foreach ($rows as $row) {
        $key = trim((string) ($row['translate'] ?? ''));
        $val = trim((string) ($row['content'] ?? ''));
        if ($key !== '' && $val !== '') {
            $adminTranslations[$key] = $val;
        }
    }
}

/**
 * 获取翻译。
 * 传入原文，找到则返回译文，找不到返回原文。
 *
 * @param string $text 原文（也是翻译键）
 */
function t(string $text): string
{
    global $adminTranslations;
    return $adminTranslations[$text] ?? $text;
}

// 加载翻译（后台所有页面都会用到）
loadAdminTranslations();

