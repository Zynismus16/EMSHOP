<?php

declare(strict_types=1);

/**
 * 用户数据模型。
 *
 * 当前模型只处理后台管理员登录相关查询，前台用户登录后续单独扩展。
 */
final class UserModel
{
    /**
     * @var string
     */
    private $table;

    public function __construct()
    {
        $this->table = Database::prefix() . 'user';
    }

    /**
     * 按账号、邮箱或手机号查找可登录后台的管理员。
     *
     * 注意：这里只允许 role=admin，避免管理员与前台用户登录逻辑混用。
     *
     * @return array<string, mixed>|null
     */
    public function findAdminByAccount(string $account): ?array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `status` = :status AND `role` = :role AND (`username` = :username OR `email` = :email OR `mobile` = :mobile) LIMIT 1',
            $this->table
        );

        return Database::fetchOne($sql, [
            'status' => 1,
            'role' => 'admin',
            'username' => $account,
            'email' => $account,
            'mobile' => $account,
        ]);
    }

    /**
     * 通过记住登录 token 查找管理员。
     *
     * @return array<string, mixed>|null
     */
    public function findAdminByRememberToken(string $token): ?array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `status` = :status AND `role` = :role AND `remember_token` = :remember_token LIMIT 1',
            $this->table
        );

        return Database::fetchOne($sql, [
            'status' => 1,
            'role' => 'admin',
            'remember_token' => $token,
        ]);
    }

    /**
     * 按 ID 查找仍然有效的管理员（用于会话实时校验）。
     *
     * 账号被禁用、删除或降权后返回 null，调用方据此让旧会话立即失效。
     *
     * @return array<string, mixed>|null
     */
    public function findAdminById(int $userId): ?array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `id` = :id AND `status` = :status AND `role` = :role LIMIT 1',
            $this->table
        );

        return Database::fetchOne($sql, [
            'id' => $userId,
            'status' => 1,
            'role' => 'admin',
        ]);
    }

    /**
     * 更新管理员最近登录信息。
     */
    public function updateLoginMeta(int $userId, string $ip, ?string $rememberToken): void
    {
        $sql = sprintf(
            'UPDATE `%s` SET `last_login_ip` = :last_login_ip, `last_login_at` = NOW(), `remember_token` = :remember_token, `updated_at` = NOW() WHERE `id` = :id LIMIT 1',
            $this->table
        );

        Database::execute($sql, [
            'id' => $userId,
            'last_login_ip' => $ip,
            'remember_token' => $rememberToken,
        ]);
    }

    /**
     * 作废该用户的「记住我」令牌。
     *
     * 退出登录时必须调用：浏览器 cookie 只是令牌的副本，库里那一份不清掉，
     * 任何拿到过该值的人（例如从备份、日志或共享设备里抄到）在用户退出后
     * 依然能直接登进来，最长可达 remember_days_checked 天。
     */
    public function clearRememberToken(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $sql = sprintf(
            'UPDATE `%s` SET `remember_token` = NULL, `updated_at` = NOW() WHERE `id` = :id LIMIT 1',
            $this->table
        );

        Database::execute($sql, ['id' => $userId]);
    }

    /**
     * 按 ID 查找用户。
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $userId): ?array
    {
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `id` = :id LIMIT 1',
            $this->table
        );

        return Database::fetchOne($sql, ['id' => $userId]);
    }

    /**
     * 更新用户基本资料（昵称、邮箱、头像、账号）。
     *
     * @param array<string, string> $data 允许的字段：nickname, email, avatar, username
     */
    public function updateProfile(int $userId, array $data): void
    {
        $allowed = ['nickname', 'email', 'avatar', 'username', 'mobile'];
        $sets = [];
        $params = ['id' => $userId];

        foreach ($data as $field => $value) {
            if (in_array($field, $allowed, true)) {
                $sets[] = sprintf('`%s` = :%s', $field, $field);
                $params[$field] = $value;
            }
        }

        if ($sets === []) {
            return;
        }

        $sets[] = '`updated_at` = NOW()';

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `id` = :id LIMIT 1',
            $this->table,
            implode(', ', $sets)
        );

        Database::execute($sql, $params);
    }

    /**
     * 更新用户密码。
     *
     * 同时吊销「记住我」令牌：否则旧令牌在库里仍然有效（最长可达
     * remember_days_checked 天），拿到过该 cookie 的人改密后照样能登进来。
     */
    public function updatePassword(int $userId, string $hashedPassword): void
    {
        $sql = sprintf(
            'UPDATE `%s` SET `password` = :password, `remember_token` = NULL, `updated_at` = NOW() WHERE `id` = :id LIMIT 1',
            $this->table
        );

        Database::execute($sql, [
            'id' => $userId,
            'password' => $hashedPassword,
        ]);
    }

    /**
     * 检查邮箱是否已被其他用户占用。
     */
    public function isEmailTaken(string $email, int $excludeUserId): bool
    {
        $sql = sprintf(
            'SELECT `id` FROM `%s` WHERE `email` = :email AND `id` != :id LIMIT 1',
            $this->table
        );

        return Database::fetchOne($sql, ['email' => $email, 'id' => $excludeUserId]) !== null;
    }

    /**
     * 检查手机号是否已被其他用户占用。
     */
    public function isMobileTaken(string $mobile, int $excludeUserId): bool
    {
        if ($mobile === '') {
            return false;
        }
        $sql = sprintf(
            'SELECT `id` FROM `%s` WHERE `mobile` = :mobile AND `id` != :id LIMIT 1',
            $this->table
        );

        return Database::fetchOne($sql, ['mobile' => $mobile, 'id' => $excludeUserId]) !== null;
    }

    /**
     * 检查账号是否已被其他用户占用。
     */
    public function isUsernameTaken(string $username, int $excludeUserId): bool
    {
        $sql = sprintf(
            'SELECT `id` FROM `%s` WHERE `username` = :username AND `id` != :id LIMIT 1',
            $this->table
        );

        return Database::fetchOne($sql, ['username' => $username, 'id' => $excludeUserId]) !== null;
    }
}
