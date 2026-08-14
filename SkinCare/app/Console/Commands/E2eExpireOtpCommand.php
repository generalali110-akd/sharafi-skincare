<?php

namespace App\Console\Commands;

use App\Models\OtpChallenge;
use App\Support\IranMobile;
use Illuminate\Console\Command;

final class E2eExpireOtpCommand extends Command
{
    protected $signature = 'e2e:expire-otp {mobile}';

    protected $description = 'Expire the latest active OTP challenge for browser E2E tests';

    public function handle(): int
    {
        if (! app()->environment('testing')) {
            $this->error('This command is restricted to the testing environment.');

            return self::FAILURE;
        }

        $mobile = IranMobile::normalize((string) $this->argument('mobile'));
        if (! IranMobile::isValid($mobile)) {
            $this->error('Invalid mobile.');

            return self::FAILURE;
        }

        $challenge = OtpChallenge::query()
            ->where('mobile', $mobile)
            ->where('purpose', 'auth')
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if (! $challenge) {
            $this->error('Active OTP challenge not found.');

            return self::FAILURE;
        }

        $challenge->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->info('expired');

        return self::SUCCESS;
    }
}
