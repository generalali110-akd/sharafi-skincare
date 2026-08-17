<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Inventory\AdjustInventoryAction;
use App\Actions\Inventory\UpdateInventorySettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdjustInventoryRequest;
use App\Http\Requests\Api\V1\Admin\AdminInventoryIndexRequest;
use App\Http\Requests\Api\V1\Admin\UpdateInventorySettingsRequest;
use App\Http\Resources\Api\V1\Admin\AdminInventoryResource;
use App\Models\InventoryItem;
use App\Models\ProductVariant;
use App\Support\DatabaseLike;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminInventoryController extends Controller
{
    public function index(AdminInventoryIndexRequest $request): AnonymousResourceCollection
    {
        $query = ProductVariant::query()
            ->with(['product:id,name', 'inventory'])
            ->orderBy('sku');

        if ($request->filled('q')) {
            $search = trim((string) $request->validated('q'));
            $like = DatabaseLike::caseInsensitiveOperator();
            $query->where(function (Builder $query) use ($search, $like): void {
                $query
                    ->where('sku', $like, '%'.$search.'%')
                    ->orWhereHas('product', fn (Builder $product) => $product->where('name', $like, '%'.$search.'%'));
            });
        }

        if ($request->boolean('low_stock')) {
            $query->whereHas('inventory', fn (Builder $inventory) => $inventory
                ->whereRaw('(on_hand - reserved) <= reorder_level'));
        }

        return AdminInventoryResource::collection(
            $query->paginate((int) $request->validated('per_page', 50))->withQueryString(),
        );
    }

    public function adjust(
        AdjustInventoryRequest $request,
        ProductVariant $variant,
        AdjustInventoryAction $action,
    ): JsonResource {
        $inventory = $action->execute(
            variant: $variant,
            delta: (int) $request->validated('delta'),
            reason: (string) $request->validated('reason'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return $this->inventoryResource($variant, $inventory);
    }

    public function updateSettings(
        UpdateInventorySettingsRequest $request,
        ProductVariant $variant,
        UpdateInventorySettingsAction $action,
    ): JsonResource {
        $inventory = $action->execute(
            variant: $variant,
            reorderLevel: (int) $request->validated('reorder_level'),
            actor: $request->user(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return $this->inventoryResource($variant, $inventory);
    }

    private function inventoryResource(ProductVariant $variant, InventoryItem $inventory): JsonResource
    {
        $variant->setRelation('inventory', $inventory);
        $variant->loadMissing('product:id,name');

        return new AdminInventoryResource($variant);
    }
}
