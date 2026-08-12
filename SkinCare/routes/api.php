<?php

use App\Http\Controllers\Api\V1\Admin\AdminAuditLogController;
use App\Http\Controllers\Api\V1\Admin\AdminInventoryController;
use App\Http\Controllers\Api\V1\Admin\AdminProductController;
use App\Http\Controllers\Api\V1\Admin\AdminTaxonomyController;
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
        Route::post('/catalog/products', [AdminProductController::class, 'store'])
            ->middleware('permission:'.Permissions::CATALOG_WRITE);
        Route::patch('/catalog/products/{product}', [AdminProductController::class, 'update'])
            ->middleware('permission:'.Permissions::CATALOG_WRITE);
        Route::post('/catalog/products/{product}/variants', [AdminProductController::class, 'storeVariant'])
            ->middleware('permission:'.Permissions::CATALOG_WRITE);
        Route::patch('/catalog/variants/{variant}', [AdminProductController::class, 'updateVariant'])
            ->middleware('permission:'.Permissions::CATALOG_WRITE);

        Route::post('/catalog/brands', [AdminTaxonomyController::class, 'storeBrand'])
            ->middleware('permission:'.Permissions::CATALOG_WRITE);
        Route::patch('/catalog/brands/{brand}', [AdminTaxonomyController::class, 'updateBrand'])
            ->middleware('permission:'.Permissions::CATALOG_WRITE);
        Route::post('/catalog/categories', [AdminTaxonomyController::class, 'storeCategory'])
            ->middleware('permission:'.Permissions::CATALOG_WRITE);
        Route::patch('/catalog/categories/{category}', [AdminTaxonomyController::class, 'updateCategory'])
            ->middleware('permission:'.Permissions::CATALOG_WRITE);

        Route::get('/inventory', [AdminInventoryController::class, 'index'])
            ->middleware('permission:'.Permissions::INVENTORY_READ);
        Route::post('/inventory/{variant}/adjust', [AdminInventoryController::class, 'adjust'])
            ->middleware('permission:'.Permissions::INVENTORY_WRITE);
        Route::patch('/inventory/{variant}/settings', [AdminInventoryController::class, 'updateSettings'])
            ->middleware('permission:'.Permissions::INVENTORY_WRITE);

        Route::get('/audit-logs', [AdminAuditLogController::class, 'index'])
            ->middleware('permission:'.Permissions::AUDIT_READ);
    });
});
