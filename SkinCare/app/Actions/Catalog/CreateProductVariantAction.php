<?php

namespace App\Actions\Catalog;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class CreateProductVariantAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        Product $product,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ProductVariant {
        return DB::transaction(function () use ($product, $data, $actor, $ipAddress, $userAgent): ProductVariant {
            $variant = $product->variants()->create($data);
            $variant->inventory()->create([
                'on_hand' => 0,
                'reserved' => 0,
                'reorder_level' => 0,
            ]);

            $this->auditLogger->record(
                actor: $actor,
                action: 'catalog.variant.created',
                subject: $variant,
                changes: [
                    'product_id' => $product->getKey(),
                    'sku' => $variant->sku,
                    'price_irr' => $variant->price_irr,
                    'is_active' => $variant->is_active,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $variant->load('inventory');
        });
    }
}
