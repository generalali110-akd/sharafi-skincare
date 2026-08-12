<?php

namespace App\Services\Auth;

use LogicException;

final class OtpCodeHasher
{
    public function hash(string $code): string
    {
        $pepper = (string) config('sms.otp.pepper');

        if (strlen($pepper) < 32) {
            throw new LogicException('OTP_PEPPER must be configured with at least 32 characters.');
        }

        return hash_hmac('sha256', $code, $pepper);
    }

    public function verify(string $code, string $hash): bool
    {
        return hash_equals($hash, $this->hash($code));
    }
}
