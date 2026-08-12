<?php

namespace App\Support;

use App\Exceptions\CheckoutConflictException;

final class MoneyMath
{
    public static function multiply(int $amount, int $quantity): int
    {
        if ($amount < 0 || $quantity < 0) {
            throw new CheckoutConflictException('مقدار مالی معتبر نیست.');
        }
        if ($amount !== 0 && $quantity > intdiv(PHP_INT_MAX, $amount)) {
            throw new CheckoutConflictException('مبلغ محاسبه‌شده از محدوده مجاز بیشتر است.');
        }

        return $amount * $quantity;
    }

    public static function add(int ...$amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            if ($amount < 0 || $total > PHP_INT_MAX - $amount) {
                throw new CheckoutConflictException('مبلغ محاسبه‌شده از محدوده مجاز بیشتر است.');
            }
            $total += $amount;
        }

        return $total;
    }
}
