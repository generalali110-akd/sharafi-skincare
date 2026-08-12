<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $activeVariants = $this->variants->where('is_active', true);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'brand' => $this->brand ? [
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
            ] : null,
            'variant_count' => $this->variants->count(),
            'active_variant_count' => $activeVariants->count(),
            'available_stock' => $activeVariants->sum(fn ($variant) => $variant->inventory?->available ?? 0),
            'min_price_irr' => $activeVariants->min('price_irr'),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
