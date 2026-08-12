<?php

namespace App\Actions\Auth;

use App\Contracts\SmsGateway;
use App\Exceptions\SmsDeliveryException;
use App\Models\OtpChallenge;
use App\Services\Auth\OtpCodeHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

final class RequestOtpAction
{
    public function __construct(
        private readonly SmsGateway $smsGateway,
        private readonly OtpCodeHasher $hasher,
    ) {}

    public function execute(string $mobile, ?string $name, ?string $ipAddress): OtpChallenge
    {
        $ttl = max(60, (int) config('sms.otp.ttl_seconds', 120));
        $resend = max(30, (int) config('sms.otp.resend_seconds', 45));
        $maxAttempts = max(3, (int) config('sms.otp.max_attempts', 5));

        $this->enforceRateLimits($mobile, $ipAddress);

        $latest = OtpChallenge::query()
            ->where('mobile', $mobile)
            ->where('purpose', 'auth')
            ->whereNull('consumed_at')
            ->latest('created_at')
            ->first();

        if ($latest && $latest->created_at->greaterThan(now()->subSeconds($resend))) {
            $retryAfter = max(1, (int) now()->diffInSeconds($latest->created_at->copy()->addSeconds($resend)));
            throw new TooManyRequestsHttpException($retryAfter, 'لطفاً کمی بعد دوباره درخواست کد بدهید.');
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $challenge = DB::transaction(function () use ($mobile, $name, $code, $ttl, $maxAttempts): OtpChallenge {
            OtpChallenge::query()
                ->where('mobile', $mobile)
                ->where('purpose', 'auth')
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            return OtpChallenge::query()->create([
                'mobile' => $mobile,
                'purpose' => 'auth',
                'code_hash' => $this->hasher->hash($code),
                'context' => $name ? ['name' => trim($name)] : [],
                'attempt_count' => 0,
                'max_attempts' => $maxAttempts,
                'expires_at' => now()->addSeconds($ttl),
            ]);
        });

        try {
            $this->smsGateway->sendOtp($mobile, $code, $ttl);
        } catch (Throwable $exception) {
            $challenge->forceFill(['consumed_at' => now()])->save();

            if ($exception instanceof SmsDeliveryException) {
                throw $exception;
            }

            throw new SmsDeliveryException('SMS provider failed.', previous: $exception);
        }

        return $challenge;
    }

    private function enforceRateLimits(string $mobile, ?string $ipAddress): void
    {
        $decaySeconds = 600;
        $mobileKey = 'otp:request:mobile:'.hash('sha256', $mobile);
        $ipKey = 'otp:request:ip:'.hash('sha256', (string) $ipAddress);
        $mobileLimit = max(1, (int) config('sms.otp.requests_per_mobile_10_minutes', 5));
        $ipLimit = max($mobileLimit, (int) config('sms.otp.requests_per_ip_10_minutes', 20));

        foreach ([[$mobileKey, $mobileLimit], [$ipKey, $ipLimit]] as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw new TooManyRequestsHttpException(
                    RateLimiter::availableIn($key),
                    'تعداد درخواست‌های کد تأیید بیش از حد مجاز است.',
                );
            }
        }

        RateLimiter::hit($mobileKey, $decaySeconds);
        RateLimiter::hit($ipKey, $decaySeconds);
    }
}
