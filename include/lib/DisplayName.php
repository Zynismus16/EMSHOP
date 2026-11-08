<?php

declare(strict_types=1);

/**
 * 昵称 / 店铺名等展示名称的输入校验。
 *
 * 只做「拒绝」，不改写用户输入；输出侧仍必须 htmlspecialchars / emEsc。
 */
final class DisplayName
{
    /**
     * @return string|null 错误信息；通过返回 null
     */
    public static function validate(string $value, string $label = '名称', int $max = 100): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return $label . '不能为空';
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            return $label . '包含非法字符';
        }
        if (mb_strlen($value) > $max) {
            return $label . '最多 ' . $max . ' 个字符';
        }
        if (preg_match('/[<>]/u', $value)) {
            return $label . '不能包含 < 或 >';
        }
        if (preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            return $label . '包含非法控制字符';
        }

        return null;
    }
}
