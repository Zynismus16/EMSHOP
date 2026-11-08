<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * 商户（分站）管理。
 *
 * 页面动作：
 *   list         列表
 *   search_user  按 username/email 搜索候选商户主（open 弹窗用）
 *   open         手动开通商户
 *   update       编辑已有商户
 *   toggle       启/禁
 *   own_pay      审核独立收款开关
 *   delete       软删
 */
adminRequireLogin();
$user = $adminUser;
$siteName = Config::get('sitename', 'EMSHOP');

require EM_ROOT . '/include/model/MerchantModel.php';
require EM_ROOT . '/include/model/MerchantLevelModel.php';

if (Request::isPost()) {
    try {
        $action = (string) Input::post('_action', '');

        if ($action !== 'list' && $action !== 'search_user') {
            if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
                Response::error('请求已失效，请刷新页面后重试');
            }
        }

        $model = new MerchantModel();
        $levelModel = new MerchantLevelModel();

        switch ($action) {
            case 'list':
                $filter = [
                    'keyword'   => (string) Input::post('keyword', ''),
                    'status'    => Input::post('status', ''),
                    'level_id'  => (int) Input::post('level_id', 0),
                    'page'      => (int) Input::post('page', 1),
                    'page_size' => (int) Input::post('limit', 20),
                ];
                $result = $model->paginate($filter);
                // 店铺余额转成元显示
                foreach ($result['data'] as &$row) {
                    $row['shop_balance_view'] = number_format(((int) ($row['user_shop_balance'] ?? 0)) / 1000000, 2, '.', '');
                }
                unset($row);
                Response::success('', [
                    'data' => $result['data'],
                    'total' => $result['total'],
                    'csrf_token' => Csrf::token(),
                ]);
                break;

            case 'search_user':
                $kw = trim((string) Input::post('keyword', ''));
                if ($kw === '') {
                    Response::success('', ['data' => []]);
                }
                $userTable = Database::prefix() . 'user';
                $sql = 'SELECT `id`, `username`, `nickname`, `email`, `merchant_id`
                          FROM `' . $userTable . '`
                         WHERE (`username` LIKE ? OR `email` LIKE ? OR `nickname` LIKE ?)
                           AND `role` = \'user\'
                         ORDER BY `id` DESC LIMIT 20';
                $rows = Database::query($sql, ['%' . $kw . '%', '%' . $kw . '%', '%' . $kw . '%']);
                Response::success('', ['data' => $rows, 'csrf_token' => Csrf::token()]);
                break;

            case 'level_options':
                $levels = $levelModel->getEnabledList();
                Response::success('', ['data' => $levels]);
                break;

            case 'open':
                $userId = (int) Input::post('user_id', 0);
                if ($userId <= 0) {
                    Response::error('请选择要开通的用户');
                }
                $userTable = Database::prefix() . 'user';
                $u = Database::fetchOne('SELECT `id`, `role` FROM `' . $userTable . '` WHERE `id` = ? LIMIT 1', [$userId]);
                if ($u === null) {
                    Response::error('用户不存在');
                }
                if (($u['role'] ?? '') !== 'user') {
                    Response::error('只能为普通用户开通商户');
                }

                $levelId = (int) Input::post('level_id', 0);
                if ($levelId <= 0 || $levelModel->findById($levelId) === null) {
                    Response::error('请选择商户等级');
                }

                $name = trim((string) Input::post('name', ''));
                $nameErr = DisplayName::validate($name, '店铺名');
                if ($nameErr !== null) {
                    Response::error($nameErr);
                }

                $parentId = (int) Input::post('parent_id', 0);
                if ($parentId > 0 && $model->findById($parentId) === null) {
                    Response::error('上级商户不存在');
                }

                $merchantId = $model->openMerchant([
                    'user_id'    => $userId,
                    'parent_id'  => $parentId,
                    'level_id'   => $levelId,
                    'name'       => $name,
                    'opened_via' => 'admin',
                    'status'     => 1,
                ]);

                Response::success('商户开通成功', [
                    'id' => $merchantId,
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            case 'update':
                $id = (int) Input::post('id', 0);
                $existing = $model->findById($id);
                if ($existing === null) {
                    Response::error('商户不存在');
                }

                $data = [
                    'name' => trim((string) Input::post('name', '')),
                    'logo' => trim((string) Input::post('logo', '')),
                    'slogan' => trim((string) Input::post('slogan', '')),
                    'description' => trim((string) Input::post('description', '')),
                    'icp' => trim((string) Input::post('icp', '')),
                    'level_id' => (int) Input::post('level_id', (int) $existing['level_id']),
                    'subdomain' => strtolower(trim((string) Input::post('subdomain', ''))) ?: null,
                    'custom_domain' => strtolower(trim((string) Input::post('custom_domain', ''))) ?: null,
                ];

                $nameErr = DisplayName::validate($data['name'], '店铺名');
                if ($nameErr !== null) {
                    Response::error($nameErr);
                }
                $merchantLevelRow = $data['level_id'] > 0 ? $levelModel->findById($data['level_id']) : null;
                if ($merchantLevelRow === null) {
                    Response::error('请选择商户等级');
                }
                if ($data['subdomain'] !== null) {
                    if (!preg_match('/^[a-z0-9]([a-z0-9\-]{1,30})[a-z0-9]$/', $data['subdomain'])) {
                        Response::error('二级域名格式不合法');
                    }
                    if ($model->existsSubdomain($data['subdomain'], $id)) {
                        Response::error('二级域名已被占用');
                    }
                    $domainErr = MerchantDomainGuard::subdomainError($data['subdomain'], $id);
                    if ($domainErr !== '') {
                        Response::error($domainErr);
                    }
                }
                if ($data['custom_domain'] !== null) {
                    if (!preg_match('/^[a-z0-9]([a-z0-9\-\.]{1,199})$/', $data['custom_domain'])) {
                        Response::error('自定义域名格式不合法');
                    }
                    if ($model->existsCustomDomain($data['custom_domain'], $id)) {
                        Response::error('该域名已被占用');
                    }
                    $domainErr = MerchantDomainGuard::customDomainError($data['custom_domain'], $id);
                    if ($domainErr !== '') {
                        Response::error($domainErr);
                    }
                    if ((int) ($merchantLevelRow['allow_custom_domain'] ?? 0) !== 1) {
                        Response::error('当前商户等级不允许绑定自定义域名');
                    }
                }
                $model->updateMerchant($id, $data);
                Response::success('更新成功', ['csrf_token' => Csrf::refresh()]);
                break;

            case 'toggle':
                $id = (int) Input::post('id', 0);
                $m = $model->findById($id);
                if ($m === null) {
                    Response::error('商户不存在');
                }
                $newStatus = ((int) $m['status']) === 1 ? 0 : 1;
                $model->setStatus($id, $newStatus);
                Response::success('状态已更新', [
                    'status' => $newStatus,
                    'csrf_token' => Csrf::refresh(),
                ]);
                break;

            case 'delete':
                $id = (int) Input::post('id', 0);
                if ($model->findById($id) === null) {
                    Response::error('商户不存在');
                }
                $model->softDelete($id);
                Response::success('删除成功', ['csrf_token' => Csrf::refresh()]);
                break;

            default:
                Response::error('未知操作');
        }
    } catch (RuntimeException $e) {
        Response::error($e->getMessage());
    } catch (InvalidArgumentException $e) {
        Response::error($e->getMessage());
    } catch (Throwable $e) {
        Response::error($e->getMessage() ?: '系统繁忙，请稍后再试');
    }
}

// ============================================================
// 弹窗模式：open / edit
// ============================================================
$popup = Input::get('_popup', '');
if ($popup !== '') {
    $csrfToken = Csrf::token();
    $levelModel = new MerchantLevelModel();
    $levels = $levelModel->getEnabledList();

    $esc = function (string $str): string {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    };

    if ($popup === 'open') {
        $pageTitle = '开通商户';
        include __DIR__ . '/view/popup/merchant_open.php';
        return;
    }
    if ($popup === 'edit') {
        $editId = (int) Input::get('id', 0);
        $merchantModel = new MerchantModel();
        $editRow = $editId > 0 ? $merchantModel->findById($editId) : null;
        if ($editRow === null) {
            echo '<div style="padding:30px;text-align:center;color:#999">商户不存在</div>';
            return;
        }
        $pageTitle = '编辑商户：' . $editRow['name'];
        include __DIR__ . '/view/popup/merchant_edit.php';
        return;
    }
}

// ============================================================
// 正常模式
// ============================================================
$csrfToken = Csrf::token();
$levels = (new MerchantLevelModel())->getEnabledList();

if (Request::isPjax()) {
    echo '<div id="adminContent" class="admin-content">';
    include __DIR__ . '/view/merchant.php';
    echo '</div>';
} else {
    $adminContentView = __DIR__ . '/view/merchant.php';
    require __DIR__ . '/index.php';
}
