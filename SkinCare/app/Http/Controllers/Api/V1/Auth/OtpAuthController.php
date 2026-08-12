<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\RequestOtpAction;
use App\Actions\Auth\VerifyOtpAction;
use App\Exceptions\SmsDeliveryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RequestOtpRequest;
use App\Http\Requests\Api\V1\Auth\VerifyOtpRequest;
use App\Support\IranMobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class OtpAuthController extends Controller
{
    public function requestOtp(RequestOtpRequest $request, RequestOtpAction $action): JsonResponse
    {
        try {
            $challenge = $action->execute(
                $request->normalizedMobile(),
                $request->string('name')->trim()->value() ?: null,
                $request->ip(),
            );
        } catch (SmsDeliveryException) {
            return response()->json([
                'message' => 'سرویس ارسال پیامک موقتاً در دسترس نیست.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'data' => [
                'challenge_id' => $challenge->getKey(),
                'mobile' => IranMobile::mask($challenge->mobile),
                'expires_in' => max(0, now()->diffInSeconds($challenge->expires_at)),
                'resend_after' => (int) config('sms.otp.resend_seconds', 45),
            ],
        ], Response::HTTP_CREATED);
    }

    public function verifyOtp(VerifyOtpRequest $request, VerifyOtpAction $action): JsonResponse
    {
        $user = $action->execute(
            (string) $request->validated('challenge_id'),
            (string) $request->validated('code'),
        );

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json([
            'data' => [
                'user' => $user->only(['id', 'name', 'mobile', 'mobile_verified_at']),
                'authenticated' => true,
            ],
        ]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
