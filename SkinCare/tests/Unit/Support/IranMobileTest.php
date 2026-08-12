<?php

namespace Tests\Unit\Support;

use App\Support\IranMobile;
use PHPUnit\Framework\TestCase;

class IranMobileTest extends TestCase
{
    public function test_it_normalizes_persian_and_arabic_digits(): void
    {
        $this->assertSame('09121234567', IranMobile::normalize('۰۹۱۲-۱۲۳ ۴۵۶۷'));
        $this->assertSame('09121234567', IranMobile::normalize('٠٩١٢١٢٣٤٥٦٧'));
    }

    public function test_it_validates_iranian_mobile_format(): void
    {
        $this->assertTrue(IranMobile::isValid('09121234567'));
        $this->assertFalse(IranMobile::isValid('9121234567'));
        $this->assertFalse(IranMobile::isValid('0912123456'));
    }
}
