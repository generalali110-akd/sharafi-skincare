<?php

namespace Tests\Fakes;

use App\Contracts\SmsGateway;

class FakeSmsGateway implements SmsGateway
{
    public ?string $mobile = null;
    public ?string $code = null;
    public ?int $ttlSeconds = null;

    public function sendOtp(string $mobile, string $code, int $ttlSeconds): void
    {
        $this->mobile = $mobile;
        $this->code = $code;
        $this->ttlSeconds = $ttlSeconds;
    }
}
