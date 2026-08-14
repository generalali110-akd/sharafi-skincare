<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProductImageRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProductImageRequest;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Catalog\ProductImageService;
use App\Support\ProductImagePayload;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class AdminProductImageController extends Controller
{
    public function store(
        StoreProductImageRequest $request,
        Product $product,
        ProductImageService $images,
    ): JsonResponse {
        $image = $images->store(
            product: $product,
            upload: $request->file('image'),
            data: $request->safe()->except('image'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['data' => ProductImagePayload::make($image)], Response::HTTP_CREATED);
    }

    public function update(
        UpdateProductImageRequest $request,
        Product $product,
        ProductImage $image,
        ProductImageService $images,
    ): JsonResponse {
        $image = $images->update(
            product: $product,
            image: $image,
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['data' => ProductImagePayload::make($image)]);
    }

    public function destroy(
        StoreProductImageRequest $request,
        Product $product,
        ProductImage $image,
        ProductImageService $images,
    ): Response {
        $images->delete(
            product: $product,
            image: $image,
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->noContent();
    }
}
