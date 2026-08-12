<?php

namespace App\Actions\Auth;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Auth\OtpCodeHasher;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VerifyOtpAction
{
    public function __construct(private readonly OtpCodeHasher $hasher) {}

    public function execute(string $challengeId, string $code): User
    {
        $result = DB::transaction(function () use ($challengeId, $code): array {
            /** @var OtpChallenge|null $challenge */
            $challenge = OtpChallenge::query()->lockForUpdate()->find($challengeId);

            if (! $challenge || $challenge->consumed_at || $challenge->expires_at->isPast()) {
                return ['valid' => false, 'user' => null, 'blocked' => false];
            }

            if ($challenge->attempt_count >= $challenge->max_attempts) {
                $challenge->forceFill(['consumed_at' => now()])->save();

                return ['valid' => false, 'user' => null, 'blocked' => false];
            }

            $challenge->attempt_count++;
            $valid = $this->hasher->verify($code, $challenge->code_hash);

            if (! $valid) {
                if ($challenge->attempt_count >= $challenge->max_attempts) {
                    $challenge->consumed_at = now();
                }
                $challenge->save();

                return ['valid' => false, 'user' => null, 'blocked' => false];
            }

            $challenge->consumed_at = now();
            $challenge->save();

            /** @var User|null $user */
            $user = User::query()->where('mobile', $challenge->mobile)->lockForUpdate()->first();

            if (! $user) {
                $user = User::query()->create([
                    'mobile' => $challenge->mobile,
                    'name' => $challenge->context['name'] ?? null,
                    'mobile_verified_at' => now(),
                    'status' => 'active',
                ]);
            } else {
                if (! $user->name && ! empty($challenge->context['name'])) {
                    $user->name = $challenge->context['name'];
                }
                $user->mobile_verified_at ??= now();
                $user->save();
            }

            return [
                'valid' => true,
                'user' => $user,
                'blocked' => $user->status !== 'active',
            ];
        });

        if (! $result['valid']) {
            throw ValidationException::withMessages([
                'code' => ['کد تأیید نامعتبر یا منقضی است.'],
            ]);
        }

        if ($result['blocked']) {
            throw new AuthorizationException('حساب کاربری غیرفعال است.');
        }

        return $result['user'];
    }
}
