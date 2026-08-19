<?php

namespace App\Support;

final class IranMobile
{
    public static function normalize(string $value): string
    {
        $value = PersianArabicDigits::normalize($value);

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
