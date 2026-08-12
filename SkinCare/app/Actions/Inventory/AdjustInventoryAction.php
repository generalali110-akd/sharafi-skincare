<?php

namespace App\Actions\Inventory;

use App\Exceptions\InventoryConflictException;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class AdjustInventoryAction
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function execute(
        ProductVariant $variant,
        int $delta,
        string $reason,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): InventoryItem {
        if ($delta === 0) {
            throw new InventoryConflictException('تغییر موجودی نمی‌تواند صفر باشد.');
        }

        return DB::transaction(function () use ($variant, $delta, $reason, $actor, $ipAddress, $userAgent): InventoryItem {
            $inventory = InventoryItem::query()
                ->where('variant_id', $variant->getKey())
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                throw new InventoryConflictException('رکورد موجودی این تنوع محصول وجود ندارد.');
            }

            $before = [
                'on_hand' => $inventory->on_hand,
                'reserved' => $inventory->reserved,
                'available' => $inventory->available,
            ];

            $newOnHand = $inventory->on_hand + $delta;

            if ($newOnHand < 0 || $newOnHand < $inventory->reserved) {
                throw new InventoryConflictException('موجودی فیزیکی نمی‌تواند از موجودی رزروشده کمتر شود.');
            }

            $inventory->on_hand = $newOnHand;
            $inventory->save();

            InventoryMovement::query()->create([
                'variant_id' => $variant->getKey(),
                'type' => 'admin_adjustment',
                'quantity' => $delta,
                'reason' => trim($reason),
                'actor_user_id' => $actor->getKey(),
                'reference_type' => 'admin',
                'reference_id' => (string) $actor->getKey(),
                'metadata' => null,
            ]);

            $this->auditLogger->record(
                actor: $actor,
                action: 'inventory.adjusted',
                subject: $variant,
                changes: [
                    'before' => $before,
                    'after' => [
                        'on_hand' => $inventory->on_hand,
                        'reserved' => $inventory->reserved,
                        'available' => $inventory->available,
                    ],
                    'delta' => $delta,
                    'reason' => trim($reason),
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $inventory->refresh();
        });
    }
}
