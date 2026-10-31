<?php

declare(strict_types=1);

require __DIR__ . '/global.php';

/**
 * 在线升级控制器。
 *
 * 所有动作均为 POST AJAX，由前端升级向导按 preflight → download → extract
 *   → apply → migrate → finalize 的顺序调度。
 *
 * 每步独立返回 JSON，后端不记状态——让前端持有 zip_path / extract_path。
 * 不备份旧文件、不长期保留升级包（解压后删除 zip，finalize 清空 cache）。
 *
 * 动作（_action）：
 *   preflight   —— 预检环境
 *   download    —— 下载升级包（临时 cache，解压后删除）
 *   extract     —— 解压（返回 extract_path）
 *   apply       —— 覆盖文件（失败时仅删除本次新增文件）
 *   migrate     —— 跑 install/migrations 新增 SQL
 *   finalize    —— 成功收尾，清临时文件
 *   rollback    —— 删除本次升级新增的文件（无法还原已覆盖文件）
 */
adminRequireLogin();

// 所有动作必须 POST + CSRF
if (!Request::isPost()) {
    Response::error('仅支持 POST');
}

// 每个动作都是整站级写操作（下载升级包 / 覆盖站点文件 / 跑迁移 / 回滚），
// 必须校验 CSRF —— 缺了它可被跨站驱动整条升级流水线。
if (!Csrf::validate((string) Input::post('csrf_token', ''))) {
    Response::error('请求已失效，请刷新页面后重试');
}

$action = (string) Input::post('_action', '');

try {
    switch ($action) {
        // ------------------------------------------------------------
        case 'preflight': {
            $version     = (string) Input::post('version', '');
            $minFrom     = (string) Input::post('min_from_version', '');
            $packageSize = (int) Input::post('package_size', 0);
            $res = UpdateService::preflight($version, $minFrom, $packageSize);
            // preflight 就算有 errors 也用 200 返回（让前端一次性拿到清单展示），
            // 只有真正的异常才走 400
            Response::success('', $res + ['csrf_token' => Csrf::token()]);
        }

        // ------------------------------------------------------------
        case 'download': {
            $url    = (string) Input::post('package_url', '');
            $sha256 = (string) Input::post('package_sha256', '');
            if ($url === '') Response::error('升级包 URL 不能为空');

            $res = UpdateService::download($url, $sha256);
            if (!$res['ok']) Response::error($res['error'] ?? '下载失败', $res);
            Response::success('下载完成', $res);
        }

        // ------------------------------------------------------------
        case 'extract': {
            $zipPath = (string) Input::post('zip_path', '');
            if ($zipPath === '') Response::error('缺少 zip_path 参数');

            $res = UpdateService::extract($zipPath);
            if (!$res['ok']) Response::error($res['error'] ?? '解压失败', $res);
            Response::success('解压完成', $res);
        }

        // ------------------------------------------------------------
        case 'apply': {
            $extractPath = (string) Input::post('extract_path', '');
            if ($extractPath === '') {
                Response::error('缺少 extract_path 参数');
            }
            $res = UpdateService::apply($extractPath);
            if (!$res['ok']) Response::error($res['error'] ?? '应用失败', $res);
            Response::success('文件覆盖完成', $res);
        }

        // ------------------------------------------------------------
        case 'migrate': {
            $res = UpdateService::migrate();
            if (!$res['ok']) {
                // 迁移失败不自动回滚 —— 返回详情让前端弹窗问用户
                Response::error($res['error'] ?? '数据库迁移失败', $res);
            }
            Response::success('数据库迁移完成', $res);
        }

        // ------------------------------------------------------------
        case 'finalize': {
            $res = UpdateService::finalize();
            // 每次系统升级完成后 bump pending 版本，触发任务服务检测并重载 worker（新旧 key 双写）。
            CliServer::bumpPendingFileVersion();
            Response::success('升级完成', $res + ['csrf_token' => Csrf::refresh()]);
        }

        // ------------------------------------------------------------
        case 'rollback': {
            $res = UpdateService::rollback();
            Response::success('回滚完成', $res + ['csrf_token' => Csrf::refresh()]);
        }

        // ------------------------------------------------------------
        default:
            Response::error('未知动作：' . $action);
    }
} catch (RuntimeException $e) {
    Response::error($e->getMessage());
} catch (Throwable $e) {
    Response::error('升级异常：' . $e->getMessage());
}
