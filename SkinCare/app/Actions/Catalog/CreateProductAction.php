<?php

namespace App\Actions\Catalog;

use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class CreateProductAction
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function execute(
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Product {
        return DB::transaction(function () use ($data, $actor, $ipAddress, $userAgent): Product {
            $variants = $data['variants'];
            $categoryIds = $data['category_ids'] ?? [];
            unset($data['variants'], $data['category_ids']);

            $status = ProductStatus::from($data['status']);
            $data['published_at'] = $status === ProductStatus::Active
                ? ($data['published_at'] ?? now())
                : ($data['published_at'] ?? null);
            $data['created_by'] = $actor->getKey();
            $data['updated_by'] = $actor->getKey();

            $product = Product::query()->create($data);
            $product->categories()->sync($categoryIds);

            $variantIds = [];
            foreach ($variants as $variantData) {
                $variant = $product->variants()->create($variantData);
                $variant->inventory()->create([
                    'on_hand' => 0,
                    'reserved' => 0,
                    'reorder_level' => 0,
                ]);
                $variantIds[] = $variant->getKey();
            }

            $this->auditLogger->record(
                actor: $actor,
                action: 'catalog.product.created',
                subject: $product,
                changes: [
                    'name' => $product->name,
                    'slug' => $product->slug,
                    'status' => $product->status->value,
                    'brand_id' => $product->brand_id,
                    'category_ids' => array_values($categoryIds),
                    'variant_ids' => $variantIds,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $product->load([
                'brand:id,name,slug',
                'categories:id,name,slug',
                'variants.inventory',
            ]);
        });
    }
}
