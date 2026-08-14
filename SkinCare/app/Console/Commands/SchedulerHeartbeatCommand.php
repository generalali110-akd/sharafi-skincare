<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'ops:scheduler-heartbeat';

    protected $description = 'Record a heartbeat proving the Laravel scheduler is running';

    public function handle(): int
    {
        Cache::put('ops:scheduler:last_heartbeat_at', now()->getTimestamp(), now()->addMinutes(10));

        return self::SUCCESS;
    }
}
