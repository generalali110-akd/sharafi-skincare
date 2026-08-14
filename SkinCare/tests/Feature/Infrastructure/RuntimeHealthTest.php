<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RuntimeHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_health_passes_after_scheduler_heartbeat(): void
    {
        $this->artisan('ops:scheduler-heartbeat')->assertExitCode(0);

        $this->artisan('ops:runtime-health --json')
            ->expectsOutputToContain('"ok":true')
            ->assertExitCode(0);
    }

    public function test_runtime_health_fails_when_scheduler_heartbeat_is_missing(): void
    {
        Cache::forget('ops:scheduler:last_heartbeat_at');

        $this->artisan('ops:runtime-health --json')
            ->expectsOutputToContain('"scheduler":{"ok":false')
            ->assertExitCode(1);
    }
}
