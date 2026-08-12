<?php

namespace App\Actions\Inventory;

use App\Exceptions\InventoryConflictException;
use App\Models\InventoryItem;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

final class UpdateInventorySettingsAction
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        ProductVariant $variant,
        int $reorderLevel,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): InventoryItem {
        return DB::transaction(function () use ($variant, $reorderLevel, $actor, $ipAddress, $userAgent): InventoryItem {
            $inventory = InventoryItem::query()
                ->where('variant_id', $variant->getKey())
                ->lockForUpdate()
                ->first();

            if (! $inventory) {
                throw new InventoryConflictException('رکورد موجودی این تنوع محصول وجود ندارد.');
            }

            $before = $inventory->reorder_level;
            $inventory->reorder_level = $reorderLevel;
            $inventory->save();

            if ($before !== $reorderLevel) {
                $this->auditLogger->record(
                    actor: $actor,
                    action: 'inventory.settings.updated',
                    subject: $variant,
                    changes: [
                        'reorder_level' => ['before' => $before, 'after' => $reorderLevel],
                    ],
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );
            }

            return $inventory->refresh();
        });
    }
}
