<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class AuditLogger
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'secret',
        'otp',
        'otp_code',
        'code_hash',
        'authorization',
    ];

    public function record(
        ?User $actor,
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
        array $metadata = [],
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_user_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 1000) : null,
            'changes' => $this->sanitize($changes),
            'metadata' => $this->sanitize($metadata),
        ]);
    }

    private function sanitize(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(mb_strtolower($key), self::SENSITIVE_KEYS, true)) {
                $payload[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->sanitize($value);
            }
        }

        return $payload;
    }
}
