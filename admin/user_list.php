<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * 用户列表管理控制器。
 *
 * 管理 em_user 里的所有账号，**包含管理员账号**（管理员也能在前台登录并按普通用户
 * 使用前台功能，所以这里要能看见它们、能管理其它管理账号）。
 *
 * 唯一的例外是当前登录的这个账号自己：不能禁用、不能删除（防自锁，见 $currentAdminId）。
 */
adminRequireLogin();
$user = $adminUser;
// 当前登录管理员 id 必须单独存一份：下面的 case 里会执行 $user = $model->findById($id)
// 把 $user 覆盖成「被操作用户」，那时再用 $user['id'] 判断自己就完全错了。
$currentAdminId = (int) ($adminUser['id'] ?? 0);
$siteName = Config::get('sitename', 'EMSHOP');

require EM_ROOT . '/include/model/UserListModel.php';

// ============================================================
// POST 请求处理
// ============================================================
if (Request::isPost()) {
    try {
        $action = (string) Input::post('_action', '');

        if ($action !== 'list') {
            $csrf = (string) Input::post('csrf_token', '');
            if (!Csrf::validate($csrf)) {
                Response::error('请求已失效，请刷新页面后重试');
            }
        }

        switch ($action) {
            case 'list':
                $page = (int) Input::post('page', 1);
                $limit = (int) Input::post('limit', 15);
                $keyword = trim((string) Input::post('keyword', ''));
                $sortField = (string) Input::post('field', 'id');
                $sortOrder = strtolower((string) Input::post('order', 'desc'));

                if ($page < 1) $page = 1;
                if ($limit < 1 || $limit > 100) $limit = 15;

                $model = new UserListModel();
                $result = $model->getAll($page, $limit, $keyword, $sortField, $sortOrder);

                Response::success('', [
                    'data' => array_values($result['data']),
                    'total' => $result['total'],
                    'csrf_token' => Csrf::token(),
                ]);
                break;

            case 'create':
                $username = trim((string) Input::post('username', ''));
                if ($username === '') {
                    Response::error('用户名不能为空');
                }
                if (mb_strlen($username) > 50) {
                    Response::error('用户名最多50个字符');
                }
                if (!preg_match('/^[a-zA-Z0-9_\x{4e00}-\x{9fa5}]+$/u', $username)) {
                    Response::error('用户名只能包含字母、数字、下划线和中文字符');
                }

                $password = (string) Input::post('password', '');
                if (mb_strlen($password) < 6) {
                    Response::error('密码至少6个字符');
                }
                if (mb_strlen($password) > 50) {
                    Response::error('密码最多50个字符');
                }

                $email = trim((string) Input::post('email', ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Response::error('邮箱格式不正确');
                }

                $nickname = trim((string) Input::post('nickname', ''));
                $nicknameErr = DisplayName::validate($nickname, '昵称');
                if ($nicknameErr !== null) {
                    Response::error($nicknameErr);
                }

                $avatar = trim((string) Input::post('avatar', ''));

                $status = Input::post('status', '1');
                $status = $status === '1' ? 1 : 0;

                $model = new UserListModel();

                if ($model->existsUsername($username)) {
                    Response::error('该用户名已被占用');
                }
                if ($email !== '' && $model->existsEmail($email)) {
                    Response::error('该邮箱已被占用');
                }
                $mobile = trim((string) Input::post('mobile', ''));
                if ($mobile !== '' && $model->existsMobile($mobile)) {
                    Response::error('该手机号已被占用');
                }

                $hasher = new PasswordHash(8, true);
                $hashedPassword = $hasher->HashPassword($password);

                $userId = $model->create([
                    'username' => $username,
                    'password' => $hashedPassword,
                    'email' => $email,
                    'mobile' => $mobile,
                    'nickname' => $nickname,
                    'avatar' => $avatar,
                    'status' => $status,
                ]);

                if ($userId > 0) {
                    try {
                        UserExperienceService::applyRegisterBonus($userId);
                    } catch (Throwable $e) {
                        // 初始经验发放失败不阻断创建
                    }
                }

                $csrfToken = Csrf::refresh();
                Response::success('用户创建成功', ['csrf_token' => $csrfToken]);
                break;

            case 'update':
                $id = (int) Input::post('id', 0);
                if ($id <= 0) {
                    Response::error('无效的用户ID');
                }

                $model = new UserListModel();
                $existing = $model->findById($id);
                if ($existing === null) {
                    Response::error('用户不存在');
                }

                $email = trim((string) Input::post('email', ''));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Response::error('邮箱格式不正确');
                }

                $nickname = trim((string) Input::post('nickname', ''));
                $nicknameErr = DisplayName::validate($nickname, '昵称');
                if ($nicknameErr !== null) {
                    Response::error($nicknameErr);
                }

                $avatar = trim((string) Input::post('avatar', ''));

                $status = Input::post('status', '1');
                $status = $status === '1' ? 1 : 0;

                // 编辑弹窗的状态开关会随表单一起提交，所以这里也是「禁用自己」的一条路径，
                // 只拦 toggle 是不够的。
                if ($id === $currentAdminId && $status === 0) {
                    Response::error('不能禁用当前登录的账号');
                }

                if ($email !== '' && $model->existsEmail($email, $id)) {
                    Response::error('该邮箱已被其他用户占用');
                }
                $mobile = trim((string) Input::post('mobile', ''));
                if ($mobile !== '' && $model->existsMobile($mobile, $id)) {
                    Response::error('该手机号已被其他用户占用');
                }

                $updateData = [
                    'email' => $email,
                    'mobile' => $mobile,
                    'nickname' => $nickname,
                    'avatar' => $avatar,
                    'status' => $status,
                ];

                $password = (string) Input::post('password', '');
                if ($password !== '') {
                    if (mb_strlen($password) < 6) {
                        Response::error('密码至少6个字符');
                    }
                    if (mb_strlen($password) > 50) {
                        Response::error('密码最多50个字符');
                    }
                    $hasher = new PasswordHash(8, true);
                    $updateData['password'] = $hasher->HashPassword($password);
                }

                $model->update($id, $updateData);

                // 改的是自己：同步刷新当前会话（含密码指纹），避免下一次请求被踢下线
                if ($id === $currentAdminId) {
                    $freshUser = (new UserModel())->findById($id);
                    if ($freshUser !== null) {
                        $auth->refreshSession($freshUser);
                    }
                }

                $csrfToken = Csrf::refresh();
                Response::success('操作成功', ['csrf_token' => $csrfToken]);
                break;

            case 'toggle':
                $id = (int) Input::post('id', 0);
                if ($id <= 0) {
                    Response::error('无效的用户ID');
                }

                if ($id === $currentAdminId) {
                    Response::error('不能禁用当前登录的账号');
                }

                $model = new UserListModel();
                $user = $model->findById($id);
                if ($user === null) {
                    Response::error('用户不存在');
                }

                // 接住返回值：以前这里丢弃结果，被 role 过滤挡住时也会回「状态已更新」，
                // 前端开关回滚了但提示是成功的，排查起来很费劲。
                if (!$model->toggleStatus($id, $currentAdminId)) {
                    Response::error('状态更新失败，请刷新后重试');
                }

                $csrfToken = Csrf::refresh();
                Response::success('状态已更新', ['csrf_token' => $csrfToken]);
                break;

            case 'delete':
                $id = (int) Input::post('id', 0);
                if ($id <= 0) {
                    Response::error('无效的用户ID');
                }
                if ($id === $currentAdminId) {
                    Response::error('不能删除当前登录的账号');
                }

                $model = new UserListModel();
                if ($model->findById($id) === null) {
                    Response::error('用户不存在');
                }

                if (!$model->delete($id, $currentAdminId)) {
                    Response::error('删除失败，请刷新后重试');
                }

                $csrfToken = Csrf::refresh();
                Response::success('删除成功', ['csrf_token' => $csrfToken]);
                break;

            case 'batch_delete':
                $idsRaw = Input::post('ids', '');
                $ids = array_filter(array_map('intval', explode(',', $idsRaw)), function ($id) {
                    return $id > 0;
                });
                if ($ids === []) {
                    Response::error('请选择要删除的用户');
                }

                // 剔除自己：批量删除里混进当前登录账号时，删掉其余的更少意外（而不是整批拒绝）。
                $skippedSelf = in_array($currentAdminId, $ids, true);
                $ids = array_values(array_diff($ids, [$currentAdminId]));
                if ($ids === []) {
                    Response::error('不能删除当前登录的账号');
                }

                $model = new UserListModel();
                $deleted = $model->deleteBatch($ids, $currentAdminId);

                $csrfToken = Csrf::refresh();
                $msg = '已删除 ' . $deleted . ' 个用户';
                if ($skippedSelf) {
                    $msg .= '，已跳过当前登录账号';
                }
                Response::success($msg, ['csrf_token' => $csrfToken, 'deleted' => $deleted]);
                break;

            case 'image':
                if (empty($_FILES['file'])) {
                    Response::error('请选择图片文件');
                }
                $uploader = new UploadService();
                $result = $uploader->upload($_FILES['file'], ['jpg', 'jpeg', 'png', 'gif', 'webp'], 'avatar');
                $csrfToken = Csrf::refresh();
                Response::success('上传成功', [
                    'csrf_token' => $csrfToken,
                    'url' => $result['url'],
                ]);
                break;

            case 'open_merchant':
                require_once EM_ROOT . '/include/model/MerchantModel.php';
                require_once EM_ROOT . '/include/model/MerchantLevelModel.php';

                $userId = (int) Input::post('user_id', 0);
                if ($userId <= 0) {
                    Response::error('无效的用户ID');
                }

                $userModel = new UserListModel();
                $u = $userModel->findById($userId);
                if ($u === null) {
                    Response::error('用户不存在');
                }
                // 分站站长只给普通用户开。admin/merchant.php 的同类入口本来就明确拒绝非
                // role='user'，这里以前是靠「findById 对管理员返回 null」隐式挡住的；
                // findById 放开后必须显式拦，否则管理员账号会多出一条被开成分站站长的路径。
                if ((string) ($u['role'] ?? '') !== 'user') {
                    Response::error('管理员账号不能开通分站');
                }
                if ((int) ($u['merchant_id'] ?? 0) > 0) {
                    Response::error('该用户已开通商户分站');
                }

                $levelModel = new MerchantLevelModel();
                $levelId = (int) Input::post('level_id', 0);
                $merchantLevelRow = $levelId > 0 ? $levelModel->findById($levelId) : null;
                if ($merchantLevelRow === null) {
                    Response::error('请选择商户等级');
                }

                $name = trim((string) Input::post('name', ''));
                $nameErr = DisplayName::validate($name, '店铺名');
                if ($nameErr !== null) {
                    Response::error($nameErr);
                }

                $subdomain = strtolower(trim((string) Input::post('subdomain', '')));
                if ($subdomain === '') {
                    Response::error('请填写二级域名前缀');
                }
                if (!preg_match('/^[a-z0-9]([a-z0-9\-]{1,30})[a-z0-9]$/', $subdomain)) {
                    Response::error('二级域名格式不合法');
                }

                $customDomain = strtolower(trim((string) Input::post('custom_domain', '')));
                $customDomain = $customDomain !== '' ? $customDomain : null;
                if ($customDomain !== null) {
                    if (!preg_match('/^[a-z0-9]([a-z0-9\-\.]{1,199})$/', $customDomain)) {
                        Response::error('自定义域名格式不合法');
                    }
                    if ((int) ($merchantLevelRow['allow_custom_domain'] ?? 0) !== 1) {
                        Response::error('所选商户等级不允许绑定自定义域名');
                    }
                }

                $merchantModel = new MerchantModel();
                if ($merchantModel->existsSubdomain($subdomain)) {
                    Response::error('二级域名已被占用');
                }
                $domainErr = MerchantDomainGuard::subdomainError($subdomain);
                if ($domainErr !== '') {
                    Response::error($domainErr);
                }
                if ($customDomain !== null && $merchantModel->existsCustomDomain($customDomain)) {
                    Response::error('该自定义域名已被占用');
                }
                if ($customDomain !== null) {
                    $domainErr = MerchantDomainGuard::customDomainError($customDomain);
                    if ($domainErr !== '') {
                        Response::error($domainErr);
                    }
                }

                $openData = [
                    'user_id'    => $userId,
                    'parent_id'  => 0,
                    'level_id'   => $levelId,
                    'name'       => $name,
                    'subdomain'  => $subdomain,
                    'opened_via' => 'admin',
                    'status'     => 1,
                ];
                if ($customDomain !== null) {
                    $openData['custom_domain'] = $customDomain;
                }

                $merchantId = $merchantModel->openMerchant($openData);

                Response::success('商户分站开通成功', [
                    'merchant_id' => $merchantId,
                    'csrf_token'  => Csrf::refresh(),
                ]);
                break;

            case 'set_level':
                $userId = (int) Input::post('user_id', 0);
                if ($userId <= 0) {
                    Response::error('无效的用户ID');
                }

                $model = new UserListModel();
                $existing = $model->findById($userId);
                if ($existing === null) {
                    Response::error('用户不存在');
                }

                $levelId = (int) Input::post('level_id', 0);
                if ($levelId > 0) {
                    require_once EM_ROOT . '/include/model/UserLevelModel.php';
                    $lvRow = (new UserLevelModel())->findById($levelId);
                    if ($lvRow === null || (string) ($lvRow['enabled'] ?? 'y') !== 'y') {
                        Response::error('用户等级不存在或已被禁用');
                    }
                }

                $model->update($userId, ['level_id' => $levelId]);

                Response::success('用户等级已更新', ['csrf_token' => Csrf::refresh()]);
                break;

            // 余额调整
            case 'balance_adjust': {
                $userId = (int) Input::post('user_id', 0);
                $type = (string) Input::post('type', '');
                $amountStr = trim((string) Input::post('amount', ''));
                $remark = trim((string) Input::post('remark', ''));

                if ($userId <= 0) {
                    Response::error('用户不存在');
                }
                if (!in_array($type, ['increase', 'decrease'], true)) {
                    Response::error('请选择操作类型');
                }
                if ($remark === '') {
                    $remark = '客服操作';
                }

                // 金额转为数据库存储格式（×1000000）。
                // Money::parse 做严格的十进制白名单校验 —— 原先的 is_numeric + bcmul
                // 会放行 1e5 这类科学计数法字符串，让 bcmul 在 PHP 8 抛未捕获的
                // ValueError（被外层 catch 成内部错误提示），PHP 7.4 下则静默截断。
                try {
                    $amount = Money::parse($amountStr);
                } catch (InvalidArgumentException $e) {
                    Response::error('请输入有效的金额');
                }

                $balanceLog = new UserBalanceLogModel();
                $operatorId = (int) ($adminUser['id'] ?? 0);
                $operatorName = (string) ($adminUser['nickname'] ?? $adminUser['username'] ?? '管理员');

                if ($type === 'increase') {
                    $ok = $balanceLog->increase($userId, $amount, $remark, $operatorId, $operatorName);
                } else {
                    $ok = $balanceLog->decrease($userId, $amount, $remark, $operatorId, $operatorName);
                }

                if (!$ok) {
                    Response::error($type === 'decrease' ? '余额不足或操作失败' : '操作失败');
                }

                // 返回最新余额
                $freshUser = (new UserListModel())->findById($userId);
                $newBalance = $freshUser ? bcdiv((string) ($freshUser['money'] ?? 0), '1000000', 2) : '0.00';

                Response::success('操作成功', [
                    'balance'    => $newBalance,
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;
            }

            default:
                Response::error('未知操作');
        }
    } catch (RuntimeException $e) {
        Response::error($e->getMessage());
    } catch (Throwable $e) {
        Response::error($e->getMessage());
    }
}

// ============================================================
// 弹窗模式：渲染编辑弹窗
// ============================================================
$popupType = (string) Input::get('_popup', '');

// 余额调整弹窗
if ($popupType === 'balance') {
    $balanceUserId = (int) Input::get('id', 0);
    $model = new UserListModel();
    $balanceUser = $model->findById($balanceUserId);
    if (!$balanceUser) {
        exit('用户不存在');
    }
    $currentBalance = bcdiv((string) ($balanceUser['money'] ?? 0), '1000000', 2);
    $pageTitle = '余额调整';

    include __DIR__ . '/view/popup/user_balance.php';
    return;
}

// 修改用户等级弹窗
if ($popupType === 'user_level') {
    $levelUserId = (int) Input::get('id', 0);
    $model = new UserListModel();
    $levelUser = $model->findById($levelUserId);
    if ($levelUser === null) {
        exit('用户不存在');
    }
    require_once EM_ROOT . '/include/model/UserLevelModel.php';
    $allLevels = (new UserLevelModel())->getAll();
    $userLevels = array_values(array_filter($allLevels, static fn($l) => (string) ($l['enabled'] ?? 'y') === 'y'));
    $esc = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $pageTitle = '修改用户等级';

    include __DIR__ . '/view/popup/user_level_change.php';
    return;
}

// 为用户开通商户分站（从用户列表进入，已选定商户主）
if ($popupType === 'merchant_open') {
    $userId = (int) Input::get('user_id', 0);
    $model = new UserListModel();
    $presetUser = $model->findById($userId);
    if ($presetUser === null) {
        exit('用户不存在');
    }
    if ((int) ($presetUser['merchant_id'] ?? 0) > 0) {
        exit('该用户已开通商户');
    }
    require_once EM_ROOT . '/include/model/MerchantLevelModel.php';
    $levels = (new MerchantLevelModel())->getEnabledList();
    if ($levels === []) {
        exit('请先在「商户等级」中创建并启用至少一个等级');
    }
    $esc = fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $pageTitle = '开通商户分站';

    include __DIR__ . '/view/popup/user_merchant_open.php';
    return;
}

// 商户详情弹窗（按 user_id 找商户，用于用户列表"商户"列点击查看）
if ($popupType === 'merchant') {
    $userId = (int) Input::get('user_id', 0);
    require_once EM_ROOT . '/include/model/MerchantModel.php';
    $merchantDetail = (new MerchantModel())->findByUserId($userId);
    if (!$merchantDetail) {
        exit('该用户未开通商户');
    }
    // 附加 merchant_level / 用户基础信息
    $levelTable = Database::prefix() . 'merchant_level';
    $userTable = Database::prefix() . 'user';
    $merchantLevel = Database::fetchOne(
        'SELECT * FROM `' . $levelTable . '` WHERE `id` = ? LIMIT 1',
        [(int) $merchantDetail['level_id']]
    );
    $merchantOwner = Database::fetchOne(
        'SELECT `id`, `username`, `nickname`, `email`, `mobile`, `shop_balance` FROM `' . $userTable . '` WHERE `id` = ? LIMIT 1',
        [(int) $merchantDetail['user_id']]
    );
    // 店铺前台 URL（自定义域名 > 二级域名 > 空串）
    $storefrontUrl = MerchantContext::storefrontUrl($merchantDetail);
    $pageTitle = '商户详情';

    include __DIR__ . '/view/popup/user_merchant_detail.php';
    return;
}

// 用户编辑弹窗
if ($popupType === '1') {
    $editId = (int) Input::get('id', 0);
    $editUser = null;

    if ($editId > 0) {
        $model = new UserListModel();
        $editUser = $model->findById($editId);
    }

    $isEdit = $editUser !== null;
    $pageTitle = $isEdit ? '编辑用户' : '添加用户';

    $esc = function (string $str): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    };

    include __DIR__ . '/view/popup/user_edit.php';
    return;
}

// ============================================================
// 正常模式：渲染完整后台页面
// ============================================================
$csrfToken = Csrf::token();

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/user_list.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/user_list.php';
    require __DIR__ . '/index.php';
}
