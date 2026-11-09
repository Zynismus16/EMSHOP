<?php

declare(strict_types=1);

require_once __DIR__ . '/global.php';

/**
 * 用户中心 - 收货地址。
 *
 * 核心通用数据（见 UserAddressModel）。本页面负责：
 *   - GET  渲染地址列表
 *   - POST save         新增或编辑（id>0 走 update）
 *   - POST delete       删除
 *   - POST set_default  设默认
 */
userRequireLogin();

$siteName = Config::get('sitename', 'EMSHOP');
$csrfToken = Csrf::token();
$displayMoney = '0.00';
if (!empty($frontUser['money'])) {
    $displayMoney = bcdiv((string) $frontUser['money'], '1000000', 2);
}
$userId = (int) $frontUser['id'];

// POST 分发
if (Request::isPost()) {
    try {
        $csrf = (string) Input::post('csrf_token', '');
        if (!Csrf::validate($csrf)) {
            Response::error('请求已失效，请刷新页面后重试');
        }

        $action = (string) Input::post('action', '');

        switch ($action) {
            case 'save': {
                $id        = (int) Input::post('id', 0);
                $recipient = trim((string) Input::post('recipient', ''));
                $mobile    = trim((string) Input::post('mobile', ''));
                $province  = trim((string) Input::post('province', ''));
                $city      = trim((string) Input::post('city', ''));
                $district  = trim((string) Input::post('district', ''));
                $detail    = trim((string) Input::post('detail', ''));
                $isDefault = Input::post('is_default', '') !== '' ? 1 : 0;

                // —— 校验 —— 所有长度/格式异常都走 Response::error 立即终止
                if ($recipient === '' || mb_strlen($recipient) > 50) {
                    Response::error('收件人姓名不能为空且长度 ≤ 50');
                }
                if (!preg_match('/^1\d{10}$/', $mobile)) {
                    Response::error('请输入 11 位有效手机号');
                }
                if ($province === '' || $city === '' || $district === '') {
                    Response::error('请完整选择省 / 市 / 区');
                }
                if ($detail === '' || mb_strlen($detail) > 255) {
                    Response::error('详细地址不能为空且长度 ≤ 255');
                }

                $payload = [
                    'recipient'  => $recipient,
                    'mobile'     => $mobile,
                    'province'   => $province,
                    'city'       => $city,
                    'district'   => $district,
                    'detail'     => $detail,
                    'is_default' => $isDefault,
                ];

                if ($id > 0) {
                    // 编辑：Model 层 owner 校验内置，非自己的 id 不会命中
                    if (UserAddressModel::findById($id, $userId) === null) {
                        Response::error('地址不存在或无权操作');
                    }
                    UserAddressModel::update($id, $userId, $payload);
                    Response::success('已保存', ['csrf_token' => Csrf::refresh()]);
                } else {
                    // 新建：先卡数量上限，防止刷接口造大量脏数据
                    if (UserAddressModel::countByUserId($userId) >= UserAddressModel::MAX_PER_USER) {
                        Response::error('每个账户最多只能保存 ' . UserAddressModel::MAX_PER_USER . ' 个地址');
                    }
                    UserAddressModel::create($userId, $payload);
                    Response::success('已添加', ['csrf_token' => Csrf::refresh()]);
                }
                break;
            }

            case 'delete': {
                $id = (int) Input::post('id', 0);
                if ($id <= 0) Response::error('参数错误');
                $ok = UserAddressModel::delete($id, $userId);
                if (!$ok) Response::error('地址不存在或无权操作');
                Response::success('已删除', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            case 'set_default': {
                $id = (int) Input::post('id', 0);
                if ($id <= 0) Response::error('参数错误');
                $ok = UserAddressModel::setDefault($id, $userId);
                if (!$ok) Response::error('地址不存在或无权操作');
                Response::success('已设为默认', ['csrf_token' => Csrf::refresh()]);
                break;
            }

            default:
                Response::error('未知操作');
        }
    } catch (Throwable $e) {
        Response::error('操作失败：' . $e->getMessage());
    }
}

// GET 渲染
$addressList = UserAddressModel::listByUserId($userId);

if (Request::isPjax()) {
    echo '<div id="userContent" class="uc-content">';
    include userViewFile('address');
    echo '</div>';
} else {
    $userContentView = userViewFile('address');
    require __DIR__ . '/index.php';
}
