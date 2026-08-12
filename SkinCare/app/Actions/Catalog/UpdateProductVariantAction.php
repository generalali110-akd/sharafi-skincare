<?php

namespace App\Actions\Catalog;

use App\Enums\ProductStatus;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateProductVariantAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        ProductVariant $variant,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ProductVariant {
        return DB::transaction(function () use ($variant, $data, $actor, $ipAddress, $userAgent): ProductVariant {
            $variant = ProductVariant::query()
                ->with('product')
                ->lockForUpdate()
                ->findOrFail($variant->getKey());

            $willBeActive = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : $variant->is_active;

            if (! $willBeActive && $variant->is_active && $variant->product->status === ProductStatus::Active) {
                $otherActiveExists = $variant->product->variants()
                    ->active()
                    ->where('id', '<>', $variant->getKey())
                    ->exists();

                if (! $otherActiveExists) {
                    throw ValidationException::withMessages([
                        'is_active' => ['آخرین تنوع فعال یک محصول فعال را نمی‌توان غیرفعال کرد.'],
                    ]);
                }
            }

            $before = [
                'sku' => $variant->sku,
                'title' => $variant->title,
                'barcode' => $variant->barcode,
                'price_irr' => $variant->price_irr,
                'compare_at_price_irr' => $variant->compare_at_price_irr,
                'is_active' => $variant->is_active,
                'sort_order' => $variant->sort_order,
            ];

            $variant->fill($data);
            $variant->save();

            $after = [
                'sku' => $variant->sku,
                'title' => $variant->title,
                'barcode' => $variant->barcode,
                'price_irr' => $variant->price_irr,
                'compare_at_price_irr' => $variant->compare_at_price_irr,
                'is_active' => $variant->is_active,
                'sort_order' => $variant->sort_order,
            ];

            if ($before !== $after) {
                $this->auditLogger->record(
                    actor: $actor,
                    action: 'catalog.variant.updated',
                    subject: $variant,
                    changes: ['before' => $before, 'after' => $after],
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );
            }

            return $variant->load('inventory');
        });
    }
}
