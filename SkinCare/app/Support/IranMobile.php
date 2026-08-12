<?php

namespace App\Support;

final class IranMobile
{
    public static function normalize(string $value): string
    {
        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return preg_replace('/[\s\-()]+/', '', trim($value)) ?? '';
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match('/^09\d{9}$/', self::normalize($value));
    }

    public static function mask(string $value): string
    {
        $mobile = self::normalize($value);

        return strlen($mobile) === 11
            ? substr($mobile, 0, 4).'***'.substr($mobile, -4)
            : '***********';
    }
}
