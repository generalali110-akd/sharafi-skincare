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
use App\Services\Catalog\ProductImageService;
use App\Support\DatabaseLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AdminProductController extends Controller
{
    public function index(AdminProductIndexRequest $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->with([
                'brand:id,name,slug',
                'variants.inventory:id,variant_id,on_hand,reserved',
                'primaryImage:id,product_id,disk,path,alt_text,sort_order,is_primary',
            ])
            ->latest('updated_at');

        if ($request->filled('q')) {
            $search = trim((string) $request->validated('q'));
            $like = DatabaseLike::caseInsensitiveOperator();
            $query->where(function (Builder $query) use ($search, $like): void {
                $query
                    ->where('name', $like, '%'.$search.'%')
                    ->orWhereHas('variants', fn (Builder $variant) => $variant->where('sku', $like, '%'.$search.'%'));
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
            'images:id,product_id,variant_id,disk,path,alt_text,sort_order,is_primary',
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

    public function storeImage(Request $request, Product $product, ProductImageService $images): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'alt_text' => ['nullable', 'string', 'max:220'],
            'variant_id' => [
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where('product_id', $product->id),
            ],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $image = $images->store($product, $request->file('image'), $data);

        return response()->json(['data' => $this->imagePayload($image)], Response::HTTP_CREATED);
    }

    public function updateImage(Request $request, Product $product, int $image, ProductImageService $images): JsonResponse
    {
        $productImage = $product->images()->findOrFail($image);
        $data = $request->validate([
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:220'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $productImage = $images->update($product, $productImage, $data);

        return response()->json(['data' => $this->imagePayload($productImage)]);
    }

    public function destroyImage(Product $product, int $image, ProductImageService $images): JsonResponse
    {
        $productImage = $product->images()->findOrFail($image);
        $images->destroy($product, $productImage);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function imagePayload($image): array
    {
        return [
            'id' => $image->id,
            'url' => $image->publicUrl(),
            'alt_text' => $image->alt_text,
            'is_primary' => $image->is_primary,
            'sort_order' => $image->sort_order,
            'variant_id' => $image->variant_id,
        ];
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
