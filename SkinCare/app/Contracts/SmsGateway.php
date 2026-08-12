<?php

namespace App\Contracts;

interface SmsGateway
{
    public function sendOtp(string $mobile, string $code, int $ttlSeconds): void;
}
