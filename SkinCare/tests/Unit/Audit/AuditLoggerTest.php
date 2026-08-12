<?php

namespace Tests\Unit\Audit;

use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_keys_are_redacted_recursively(): void
    {
        $log = (new AuditLogger())->record(
            actor: null,
            action: 'security.test',
            changes: [
                'name' => 'safe',
                'nested' => [
                    'token' => 'secret-token',
                    'otp_code' => '123456',
                ],
            ],
        );

        $this->assertSame('safe', $log->changes['name']);
        $this->assertSame('[REDACTED]', $log->changes['nested']['token']);
        $this->assertSame('[REDACTED]', $log->changes['nested']['otp_code']);
    }
}
