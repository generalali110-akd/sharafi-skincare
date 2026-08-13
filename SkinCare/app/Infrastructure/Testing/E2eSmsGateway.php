<?php

namespace App\Infrastructure\Testing;

use App\Contracts\SmsGateway;
use RuntimeException;

final class E2eSmsGateway implements SmsGateway
{
    public function sendOtp(string $mobile, string $code, int $ttlSeconds): void
    {
        $directory = storage_path('framework/e2e');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create E2E OTP directory.');
        }

        @chmod($directory, 0700);
        $path = $directory.'/otp-'.hash('sha256', $mobile).'.json';
        $temporaryPath = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $payload = json_encode([
            'mobile_hash' => hash('sha256', $mobile),
            'code' => $code,
            'expires_at' => now()->addSeconds($ttlSeconds)->toISOString(),
        ], JSON_THROW_ON_ERROR);

        if (file_put_contents($temporaryPath, $payload, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write E2E OTP file.');
        }

        @chmod($temporaryPath, 0600);
        if (! rename($temporaryPath, $path)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Unable to publish E2E OTP file.');
        }
        @chmod($path, 0600);
    }

    public function sendMessage(string $mobile, string $message, string $idempotencyKey): void
    {
        // Transactional notification transport is intentionally a no-op in browser E2E.
    }
}
