<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class E2ePaymentModeCommand extends Command
{
    protected $signature = 'e2e:payment-mode {mode : success|unavailable_once|unknown_once}';

    protected $description = 'Set the testing-only E2E payment initiation mode';

    public function handle(): int
    {
        if (! app()->environment('testing')) {
            $this->error('This command is restricted to the testing environment.');

            return self::FAILURE;
        }

        $mode = trim((string) $this->argument('mode'));
        if (! in_array($mode, ['success', 'unavailable_once', 'unknown_once'], true)) {
            $this->error('Unsupported E2E payment mode.');

            return self::FAILURE;
        }

        $directory = storage_path('framework/e2e');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->error('Unable to create E2E state directory.');

            return self::FAILURE;
        }

        $path = $directory.'/payment-mode.json';
        $tmp = $path.'.tmp-'.getmypid();
        $payload = json_encode(['mode' => $mode], JSON_UNESCAPED_SLASHES);

        if ($payload === false || file_put_contents($tmp, $payload, LOCK_EX) === false || ! rename($tmp, $path)) {
            @unlink($tmp);
            $this->error('Unable to write E2E payment mode state.');

            return self::FAILURE;
        }

        @chmod($path, 0600);
        $this->info($mode);

        return self::SUCCESS;
    }
}
