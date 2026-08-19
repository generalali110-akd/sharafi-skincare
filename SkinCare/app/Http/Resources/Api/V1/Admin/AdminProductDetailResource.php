<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'status' => $this->status->value,
            'is_featured' => $this->is_featured,
            'published_at' => $this->published_at?->toISOString(),
            'brand' => $this->brand ? [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
            ] : null,
            'categories' => $this->categories->map(fn ($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ])->values(),
            'images' => $this->images
                ->sortBy([['is_primary', 'desc'], ['sort_order', 'asc'], ['id', 'asc']])
                ->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $image->publicUrl(),
                    'alt_text' => $image->alt_text ?: $this->name,
                    'is_primary' => $image->is_primary,
                    'sort_order' => $image->sort_order,
                    'variant_id' => $image->variant_id,
                ])
                ->values(),
            'variants' => $this->variants->map(fn ($variant) => [
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
                    'reorder_level' => $variant->inventory?->reorder_level ?? 0,
                ],
            ])->values(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
