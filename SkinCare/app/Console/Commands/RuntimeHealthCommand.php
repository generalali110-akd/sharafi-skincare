<?php

namespace App\Console\Commands;

use App\Models\OutboxMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RuntimeHealthCommand extends Command
{
    protected $signature = 'ops:runtime-health {--json : Emit machine-readable JSON only}';

    protected $description = 'Check database, queue, outbox, and scheduler runtime health against production thresholds';

    public function handle(): int
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'queue' => $this->queueCheck(),
            'failed_jobs' => $this->failedJobsCheck(),
            'outbox_failed' => $this->failedOutboxCheck(),
            'outbox_age' => $this->outboxAgeCheck(),
            'scheduler' => $this->schedulerCheck(),
        ];

        $ok = collect($checks)->every(static fn (array $check): bool => (bool) ($check['ok'] ?? false));
        $payload = [
            'ok' => $ok,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($checks as $name => $check) {
                $status = ($check['ok'] ?? false) ? 'OK' : 'FAIL';
                $value = $check['value'] ?? $check['driver'] ?? $check['error'] ?? 'n/a';
                $this->line(sprintf('%-16s %-4s %s', $name, $status, (string) $value));
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function databaseCheck(): array
    {
        try {
            DB::select('select 1');

            return ['ok' => true, 'value' => 'reachable'];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'error' => class_basename($exception)];
        }
    }

    private function queueCheck(): array
    {
        $driver = (string) config('queue.default');
        if ($driver !== 'database') {
            return ['ok' => true, 'driver' => $driver, 'value' => 'external-driver'];
        }

        try {
            $count = DB::table((string) config('queue.connections.database.table', 'jobs'))->count();
            $max = max(0, (int) config('operations.health.max_queue_backlog', 100));

            return ['ok' => $count <= $max, 'value' => $count, 'max' => $max];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'error' => class_basename($exception)];
        }
    }

    private function failedJobsCheck(): array
    {
        try {
            $count = DB::table('failed_jobs')->count();
            $max = max(0, (int) config('operations.health.max_failed_jobs', 0));

            return ['ok' => $count <= $max, 'value' => $count, 'max' => $max];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'error' => class_basename($exception)];
        }
    }

    private function failedOutboxCheck(): array
    {
        try {
            $count = OutboxMessage::query()
                ->whereNull('processed_at')
                ->whereNotNull('failed_at')
                ->count();
            $max = max(0, (int) config('operations.health.max_failed_outbox', 0));

            return ['ok' => $count <= $max, 'value' => $count, 'max' => $max];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'error' => class_basename($exception)];
        }
    }

    private function outboxAgeCheck(): array
    {
        try {
            $oldest = OutboxMessage::query()
                ->whereNull('processed_at')
                ->whereNull('failed_at')
                ->where('available_at', '<=', now())
                ->oldest('created_at')
                ->first(['created_at']);
            $age = $oldest ? max(0, now()->getTimestamp() - $oldest->created_at->getTimestamp()) : 0;
            $max = max(60, (int) config('operations.health.max_outbox_pending_age_seconds', 900));

            return ['ok' => $age <= $max, 'value' => $age, 'max' => $max, 'unit' => 'seconds'];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'error' => class_basename($exception)];
        }
    }

    private function schedulerCheck(): array
    {
        try {
            $heartbeat = Cache::get('ops:scheduler:last_heartbeat_at');
            $staleAfter = max(60, (int) config('operations.health.scheduler_stale_seconds', 180));

            if (! is_int($heartbeat) && ! (is_string($heartbeat) && ctype_digit($heartbeat))) {
                return ['ok' => false, 'value' => 'missing', 'max' => $staleAfter, 'unit' => 'seconds'];
            }

            $age = max(0, now()->getTimestamp() - (int) $heartbeat);

            return ['ok' => $age <= $staleAfter, 'value' => $age, 'max' => $staleAfter, 'unit' => 'seconds'];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'error' => class_basename($exception)];
        }
    }
}
