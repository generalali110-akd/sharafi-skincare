<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Catalog\CreateProductAction;
use App\Actions\Catalog\CreateProductVariantAction;
use App\Actions\Catalog\UpdateProductAction;
use App\Actions\Catalog\UpdateProductVariantAction;
use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminProductIndexRequest;
use App\Http\Requests\Api\V1\Admin\StoreProductRequest;
use App\Http\Requests\Api\V1\Admin\StoreProductVariantRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProductRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProductVariantRequest;
use App\Http\Resources\Api\V1\Admin\AdminProductDetailResource;
use App\Http\Resources\Api\V1\Admin\AdminProductListResource;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class AdminProductController extends Controller
{
    public function index(AdminProductIndexRequest $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->with([
                'brand:id,name,slug',
                'variants.inventory:id,variant_id,on_hand,reserved',
            ])
            ->latest('updated_at');

        if ($request->filled('q')) {
            $search = trim((string) $request->validated('q'));
            $query->where(function (Builder $query) use ($search): void {
                $query
                    ->where('name', 'ilike', '%'.$search.'%')
                    ->orWhereHas('variants', fn (Builder $variant) => $variant->where('sku', 'ilike', '%'.$search.'%'));
            });
        }

        if ($request->filled('status')) {
            $status = $request->enum('status', ProductStatus::class);
            $query->where('status', $status?->value);
        }

        return AdminProductListResource::collection(
            $query->paginate((int) $request->validated('per_page', 25))->withQueryString(),
        );
    }

    public function show(Product $product): AdminProductDetailResource
    {
        $product->load([
            'brand:id,name,slug',
            'categories:id,name,slug',
            'variants.inventory:id,variant_id,on_hand,reserved,reorder_level',
        ]);

        return new AdminProductDetailResource($product);
    }

    public function store(StoreProductRequest $request, CreateProductAction $action): JsonResponse
    {
        $product = $action->execute(
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new AdminProductDetailResource($product))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        UpdateProductRequest $request,
        Product $product,
        UpdateProductAction $action,
    ): JsonResponse {
        $product = $action->execute(
            product: $product,
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return (new AdminProductDetailResource($product))->response();
    }

    public function storeVariant(
        StoreProductVariantRequest $request,
        Product $product,
        CreateProductVariantAction $action,
    ): JsonResponse {
        $variant = $action->execute(
            product: $product,
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'data' => $this->variantPayload($variant),
        ], Response::HTTP_CREATED);
    }

    public function updateVariant(
        UpdateProductVariantRequest $request,
        ProductVariant $variant,
        UpdateProductVariantAction $action,
    ): JsonResponse {
        $variant = $action->execute(
            variant: $variant,
            data: $request->validated(),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['data' => $this->variantPayload($variant)]);
    }

    private function variantPayload(ProductVariant $variant): array
    {
        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'title' => $variant->title,
            'barcode' => $variant->barcode,
            'price_irr' => $variant->price_irr,
            'compare_at_price_irr' => $variant->compare_at_price_irr,
            'is_active' => $variant->is_active,
            'sort_order' => $variant->sort_order,
            'inventory' => [
                'on_hand' => $variant->inventory?->on_hand ?? 0,
                'reserved' => $variant->inventory?->reserved ?? 0,
                'available' => $variant->inventory?->available ?? 0,
            ],
        ];
    }
}
