<?php

namespace Tests\Fakes;

use App\Contracts\SmsGateway;

class FakeSmsGateway implements SmsGateway
{
    public ?string $mobile = null;

    public ?string $code = null;

    public ?int $ttlSeconds = null;

    public array $messages = [];

    public function sendOtp(string $mobile, string $code, int $ttlSeconds): void
    {
        $this->mobile = $mobile;
        $this->code = $code;
        $this->ttlSeconds = $ttlSeconds;
    }

    public function sendMessage(string $mobile, string $message, string $idempotencyKey): void
    {
        $this->messages[] = [
            'mobile' => $mobile,
            'message' => $message,
            'idempotency_key' => $idempotencyKey,
        ];
    }
}
