<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminInventoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $inventory = $this->inventory;

        return [
            'variant_id' => $this->id,
            'sku' => $this->sku,
            'title' => $this->title,
            'product' => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ],
            'inventory' => [
                'on_hand' => $inventory?->on_hand ?? 0,
                'reserved' => $inventory?->reserved ?? 0,
                'available' => $inventory?->available ?? 0,
                'reorder_level' => $inventory?->reorder_level ?? 0,
            ],
            'updated_at' => $inventory?->updated_at?->toISOString(),
        ];
    }
}
