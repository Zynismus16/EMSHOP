<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 商户后台 - 店铺设置
 *
 * 商户可改：
 *   - 店铺名 / Logo / 一句话介绍 / 详细介绍 / 备案号（任意改）
 *   - 二级域名 / 自定义域名（受商户等级 allow_subdomain / allow_custom_domain 限制）
 * 不可改：
 *   - slug（开通后固定）
 *   - 商户主 user_id
 *   - 等级 / 状态 / 上级商户（主站管理员权限）
 */
merchantRequireLogin();

$merchantId = (int) $currentMerchant['id'];

if (Request::isPost()) {
    try {
        if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效，请刷新页面后重试');
        }
        $action = (string) Input::post('_action', '');

        switch ($action) {
            case 'save_profile': {
                $name = trim((string) Input::post('name', ''));
                $nameErr = DisplayName::validate($name, '店铺名');
                if ($nameErr !== null) {
                    Response::error($nameErr);
                }

                // 默认加价率：表单按百分比录入（10 = 10%），落库万分位（1000）
                $markupPct = (float) Input::post('default_markup_rate', 10);
                if ($markupPct < 0)   $markupPct = 0;
                if ($markupPct > 1000) $markupPct = 1000; // 最高 1000%（保险用）
                $defaultMarkup = (int) round($markupPct * 100);

                // 公告显示位置：checkbox group → 逗号分隔字符串；仅允许白名单值
                $rawPositions = $_POST['announcement_positions'] ?? [];
                if (!is_array($rawPositions)) $rawPositions = [];
                $allowedPositions = ['home', 'goods_list'];
                $positions = array_values(array_intersect($allowedPositions, array_map('strval', $rawPositions)));

                $data = [
                    'name' => $name,
                    'logo' => trim((string) Input::post('logo', '')),
                    'slogan' => trim((string) Input::post('slogan', '')),
                    'description' => trim((string) Input::post('description', '')),
                    'icp' => trim((string) Input::post('icp', '')),
                    // 富文本，不 trim（trim 会破坏富文本里嵌入的换行 / 空白结构）
                    'announcement' => (string) Input::post('announcement', ''),
                    'announcement_positions' => implode(',', $positions),
                    'default_markup_rate' => $defaultMarkup,
                ];
                Database::update('merchant', $data, $merchantId);
                Response::success('已保存', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            case 'save_domain': {
                $allowSubdomain = (int) ($merchantLevel['allow_subdomain'] ?? 0) === 1;
                $allowCustom = (int) ($merchantLevel['allow_custom_domain'] ?? 0) === 1;

                $subdomain = strtolower(trim((string) Input::post('subdomain', '')));
                $customDomain = strtolower(trim((string) Input::post('custom_domain', '')));

                $data = [];

                // 二级域名
                if ($allowSubdomain) {
                    if ($subdomain !== '') {
                        if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,30}[a-z0-9])?$/', $subdomain)) {
                            Response::error('二级域名格式不合法（仅字母/数字/短横线）');
                        }
                        // 唯一性
                        $merchantModel = new MerchantModel();
                        if ($merchantModel->existsSubdomain($subdomain, $merchantId)) {
                            Response::error('二级域名已被占用');
                        }
                    }
                    $data['subdomain'] = $subdomain !== '' ? $subdomain : null;
                } else {
                    if ($subdomain !== '') {
                        Response::error('当前等级不允许二级域名');
                    }
                }

                // 自定义顶级域名
                if ($allowCustom) {
                    if ($customDomain !== '') {
                        if (!preg_match('/^[a-z0-9]([a-z0-9\-\.]{1,199})$/', $customDomain)) {
                            Response::error('自定义域名格式不合法');
                        }
                        $merchantModel = new MerchantModel();
                        if ($merchantModel->existsCustomDomain($customDomain, $merchantId)) {
                            Response::error('该域名已被占用');
                        }
                    }
                    $data['custom_domain'] = $customDomain !== '' ? $customDomain : null;
                } else {
                    if ($customDomain !== '') {
                        Response::error('当前等级不允许自定义顶级域名');
                    }
                }

                if ($data !== []) {
                    Database::update('merchant', $data, $merchantId);
                }
                Response::success('域名已保存', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            default:
                Response::error('未知操作');
        }
    } catch (RuntimeException $e) {
        Response::error($e->getMessage());
    } catch (Throwable $e) {
        Response::error($e->getMessage() ?: '系统繁忙，请稍后再试');
    }
}

// 主站根域名（二级域名拼接预览用）
$mainDomain = (string) (Config::get('main_domain') ?? '');

merchantRenderPage(__DIR__ . '/view/settings.php', [
    'mainDomain' => $mainDomain,
    'customDomainTip' => (string) (Config::get('merchant_custom_domain_tip') ?? ''),
]);
