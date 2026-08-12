<?php

namespace App\Actions\Catalog;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateProductAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        Product $product,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Product {
        return DB::transaction(function () use ($product, $data, $actor, $ipAddress, $userAgent): Product {
            $product = Product::query()->lockForUpdate()->findOrFail($product->getKey());
            $categoryIdsProvided = array_key_exists('category_ids', $data);
            $categoryIds = $data['category_ids'] ?? [];
            unset($data['category_ids']);

            $effectiveStatus = isset($data['status'])
                ? ProductStatus::from($data['status'])
                : $product->status;

            if ($effectiveStatus === ProductStatus::Active && ! $product->variants()->active()->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['محصول فعال باید حداقل یک تنوع فعال داشته باشد.'],
                ]);
            }

            if ($effectiveStatus === ProductStatus::Active && ! array_key_exists('published_at', $data) && ! $product->published_at) {
                $data['published_at'] = now();
            }

            if ($effectiveStatus === ProductStatus::Active && array_key_exists('published_at', $data) && $data['published_at'] === null) {
                $data['published_at'] = now();
            }

            $before = [
                'name' => $product->name,
                'slug' => $product->slug,
                'status' => $product->status->value,
                'brand_id' => $product->brand_id,
                'is_featured' => $product->is_featured,
                'published_at' => $product->published_at?->toISOString(),
                'category_ids' => $product->categories()->pluck('categories.id')->all(),
            ];

            $data['updated_by'] = $actor->getKey();
            $product->fill($data);
            $product->save();

            if ($categoryIdsProvided) {
                $product->categories()->sync($categoryIds);
            }

            $after = [
                'name' => $product->name,
                'slug' => $product->slug,
                'status' => $product->status->value,
                'brand_id' => $product->brand_id,
                'is_featured' => $product->is_featured,
                'published_at' => $product->published_at?->toISOString(),
                'category_ids' => $product->categories()->pluck('categories.id')->all(),
            ];

            if ($before !== $after) {
                $this->auditLogger->record(
                    actor: $actor,
                    action: 'catalog.product.updated',
                    subject: $product,
                    changes: ['before' => $before, 'after' => $after],
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );
            }

            return $product->load([
                'brand:id,name,slug',
                'categories:id,name,slug',
                'variants.inventory',
            ]);
        });
    }
}
