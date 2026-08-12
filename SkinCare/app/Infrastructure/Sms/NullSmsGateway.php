<?php

namespace App\Infrastructure\Sms;

use App\Contracts\SmsGateway;
use App\Exceptions\SmsDeliveryException;

class NullSmsGateway implements SmsGateway
{
    public function sendOtp(string $mobile, string $code, int $ttlSeconds): void
    {
        throw new SmsDeliveryException('No SMS provider is configured.');
    }
}
