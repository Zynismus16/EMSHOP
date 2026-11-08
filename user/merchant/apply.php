<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/global.php';
require_once EM_ROOT . '/include/model/MerchantModel.php';
require_once EM_ROOT . '/include/model/MerchantLevelModel.php';
require_once EM_ROOT . '/include/model/UserBalanceLogModel.php';

/**
 * 开通商户申请页。
 *
 * 流程：
 *   - 必须登录（未登录重定向到登录页）
 *   - 如已开通 → 跳到商户后台首页
 *   - 未开通：展示允许自助开通的等级（price>0 且 is_enabled=1）
 *     · 用户选等级 + 店铺名，后端扣消费余额后创建商户
 *   - 总开关 merchant_enable_self_open 关闭时，只允许"提示+联系管理员"
 */
userRequireLogin();

// 禁止在分站（商户站）内开通新分站：避免商户之间形成层级关系（传销风险）。
// POST 返回 JSON 错误；GET 跳到主站的 apply 页（Host 改为主域名）
if (MerchantContext::currentId() > 0) {
    if (Request::isPost()) {
        Response::error('请在主站开通分站，不可在分站内开通');
    }
    $mainDomain = (string) (Config::get('main_domain') ?? '');
    if ($mainDomain !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        header('Location: ' . $scheme . '://' . $mainDomain . '/user/merchant/apply.php');
    } else {
        header('Location: /user/home.php');
    }
    exit;
}

$siteName = (string) (Config::get('sitename') ?? 'EMSHOP');
$selfOpenEnabled = in_array(
    strtolower((string) (Config::get('merchant_enable_self_open') ?? '0')),
    ['1', 'y', 'yes', 'true', 'on'],
    true
);

$merchantModel = new MerchantModel();
$existing = $merchantModel->findByUserId((int) $frontUser['id']);
if ($existing !== null) {
    // 已开通：跳回商户后台
    if (Request::isPjax() || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        Response::success('已开通分站', ['redirect' => '/user/merchant/home.php']);
    }
    header('Location: /user/merchant/home.php');
    exit;
}

// POST：提交申请
if (Request::isPost()) {
    try {
        if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
            Response::error('请求已失效，请刷新页面后重试');
        }
        if (!$selfOpenEnabled) {
            Response::error('暂未开放自助开通，请联系管理员');
        }

        $levelId = (int) Input::post('level_id', 0);
        $levelModel = new MerchantLevelModel();
        $level = $levelModel->findById($levelId);
        if ($level === null || (int) $level['is_enabled'] !== 1) {
            Response::error('请选择有效的分站等级');
        }
        $price = (int) $level['price'];
        if ($price <= 0) {
            Response::error('该等级暂不支持自助开通');
        }

        $name = trim((string) Input::post('name', ''));
        $nameErr = DisplayName::validate($name, '分站名称');
        if ($nameErr !== null) {
            Response::error($nameErr);
        }
        $userId = (int) $frontUser['id'];

        // 余额校验 + 扣款（走事务）
        $balanceLogModel = new UserBalanceLogModel();

        Database::begin();
        try {
            $userTable = Database::prefix() . 'user';
            $row = Database::fetchOne(
                'SELECT `money` FROM `' . $userTable . '` WHERE `id` = ? FOR UPDATE',
                [$userId]
            );
            if ($row === null) {
                throw new RuntimeException('用户不存在');
            }
            $before = (int) $row['money'];
            $after = $before - $price;
            if ($after < 0) {
                throw new RuntimeException('账户余额不足，请先充值');
            }

            Database::execute(
                'UPDATE `' . $userTable . '` SET `money` = ? WHERE `id` = ?',
                [$after, $userId]
            );

            // 余额变动记录（直接写表，因为 UserBalanceLogModel::decrease 自带事务）
            $logTable = Database::prefix() . 'user_balance_log';
            Database::insert('user_balance_log', [
                'user_id' => $userId,
                'type' => 'decrease',
                'amount' => $price,
                'before_balance' => $before,
                'after_balance' => $after,
                'remark' => '开通分站：' . $level['name'] . '（' . $name . '）',
                'operator_id' => $userId,
                'operator_name' => $frontUser['nickname'] ?: $frontUser['username'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);

            // 创建商户（openMerchant 内部自带事务，但我们在外层已开 —— 改为直接 insert 以共用事务）
            // 分站不支持再开分站（规避传销风险），parent_id 恒为 0
            $merchantId = Database::insert('merchant', [
                'user_id' => $userId,
                'parent_id' => 0,
                'level_id' => $levelId,
                'name' => $name,
                'opened_via' => 'self',
                'status' => 1,
                'opened_at' => date('Y-m-d H:i:s'),
            ]);
            Database::update('user', ['merchant_id' => $merchantId], $userId);

            Database::commit();

            // 刷 session
            $frontUser['money'] = $after;
            $frontUser['merchant_id'] = $merchantId;
            $_SESSION['em_front_user'] = $frontUser;

            Response::success('开通成功', [
                'merchant_id' => $merchantId,
                'redirect' => '/user/merchant/home.php',
                'csrf_token' => Csrf::refresh(),
            ]);
        } catch (Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    } catch (RuntimeException $e) {
        Response::error($e->getMessage());
    } catch (Throwable $e) {
        Response::error($e->getMessage() ?: '开通失败，请稍后再试');
    }
}

// GET：展示申请页
$levelModel = new MerchantLevelModel();
$allLevels = $levelModel->getEnabledList();
// 只展示 price > 0 的等级（price=0 意为不允许自助开通）
$levels = array_values(array_filter($allLevels, static fn($l) => (int) $l['price'] > 0));
$csrfToken = Csrf::token();

// 账户余额 / 等级价按访客当前展示币种换算（view 里 $currencySymbol . $priceYuan 形式拼接）
$displayMoney = Currency::displayAmount((int) ($frontUser['money'] ?? 0), null, false);
$currencySymbol = Currency::visitorSymbol();

// 站点 logo（页头按 site_logo_type=image/text 二选一渲染）
$siteLogoType = (string) (Config::get('site_logo_type') ?? 'text');
$siteLogo     = (string) (Config::get('site_logo') ?? '');

// 上级商户归因已移除（分站不支持层级关系）
$inviterMerchant = null;

include __DIR__ . '/view/apply.php';
