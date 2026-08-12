<?php

use App\Http\Controllers\Api\V1\Admin\AdminProductController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\Catalog\ProductController;
use App\Http\Controllers\Api\V1\Catalog\TaxonomyController;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn () => response()->json([
    'data' => [
        'service' => 'sharafi-skincare-api',
        'status' => 'ok',
        'version' => 'v1',
    ],
]));

Route::prefix('auth/otp')->group(function (): void {
    Route::post('/request', [OtpAuthController::class, 'requestOtp'])
        ->middleware('throttle:10,1');
    Route::post('/verify', [OtpAuthController::class, 'verifyOtp'])
        ->middleware('throttle:20,1');
});

Route::prefix('catalog')->group(function (): void {
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{slug}', [ProductController::class, 'show']);
    Route::get('/categories', [TaxonomyController::class, 'categories']);
    Route::get('/brands', [TaxonomyController::class, 'brands']);
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', static fn (Request $request) => response()->json([
        'data' => $request->user(),
    ]));

    Route::post('/auth/logout', [OtpAuthController::class, 'logout']);

    Route::prefix('admin')->group(function (): void {
        Route::get('/catalog/products', [AdminProductController::class, 'index'])
            ->middleware('permission:'.Permissions::CATALOG_READ);
    });
});
