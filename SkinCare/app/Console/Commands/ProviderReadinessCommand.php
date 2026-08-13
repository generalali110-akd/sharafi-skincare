<?php

namespace App\Console\Commands;

use App\Services\Operations\ProviderReadinessService;
use Illuminate\Console\Command;

class ProviderReadinessCommand extends Command
{
    protected $signature = 'ops:provider-readiness {--probe-smsir : Perform read-only SMS.ir credential and line probes} {--json : Emit machine-readable JSON only}';

    protected $description = 'Validate payment/SMS staging readiness without exposing provider secrets';

    public function handle(ProviderReadinessService $readiness): int
    {
        $result = $readiness->inspect((bool) $this->option('probe-smsir'));

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($result['checks'] as $check) {
            $prefix = $check['ok'] ? '[OK]' : '[FAIL]';
            $this->line($prefix.' '.$check['name'].': '.$check['message']);
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
