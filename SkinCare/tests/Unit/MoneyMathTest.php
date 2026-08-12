<?php

namespace Tests\Unit;

use App\Exceptions\CheckoutConflictException;
use App\Support\MoneyMath;
use PHPUnit\Framework\TestCase;

class MoneyMathTest extends TestCase
{
    public function test_money_multiplication_and_addition_are_exact_for_integer_irr(): void
    {
        $this->assertSame(297_000_000, MoneyMath::multiply(3_000_000, 99));
        $this->assertSame(297_450_000, MoneyMath::add(297_000_000, 450_000));
    }

    public function test_money_multiplication_rejects_integer_overflow(): void
    {
        $this->expectException(CheckoutConflictException::class);

        MoneyMath::multiply(PHP_INT_MAX, 2);
    }

    public function test_money_addition_rejects_integer_overflow(): void
    {
        $this->expectException(CheckoutConflictException::class);

        MoneyMath::add(PHP_INT_MAX, 1);
    }
}
