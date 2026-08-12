<?php

use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn () => response()->json([
    'data' => [
        'service' => 'sharafi-skincare-api',
        'status' => 'ok',
        'version' => 'v1',
    ],
]));

Route::middleware('web')->prefix('auth/otp')->group(function (): void {
    Route::post('/request', [OtpAuthController::class, 'requestOtp'])
        ->middleware('throttle:10,1');
    Route::post('/verify', [OtpAuthController::class, 'verifyOtp'])
        ->middleware('throttle:20,1');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', static fn (Request $request) => response()->json([
        'data' => $request->user(),
    ]));

    Route::post('/auth/logout', [OtpAuthController::class, 'logout']);
});
