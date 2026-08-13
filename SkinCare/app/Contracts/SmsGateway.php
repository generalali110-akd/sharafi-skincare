<?php

namespace App\Contracts;

interface SmsGateway
{
    public function sendOtp(string $mobile, string $code, int $ttlSeconds): void;

    public function sendMessage(string $mobile, string $message, string $idempotencyKey): void;
}
