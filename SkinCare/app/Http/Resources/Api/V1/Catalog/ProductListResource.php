<?php

namespace App\Http\Resources\Api\V1\Catalog;

use App\Support\ProductImagePayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $hasSingleActiveVariant = (int) $this->active_variants_count === 1;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'image' => $this->whenLoaded('primaryImage', fn () => $this->primaryImage
                ? ProductImagePayload::make($this->primaryImage)
                : null),
            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
            ] : null),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->map(fn ($category) => [
                'name' => $category->name,
                'slug' => $category->slug,
            ])->values()),
            'pricing' => [
                'currency' => 'IRR',
                'min' => $this->min_price_irr !== null ? (int) $this->min_price_irr : null,
                'max' => $this->max_price_irr !== null ? (int) $this->max_price_irr : null,
            ],
            'purchase' => [
                'variant_id' => $hasSingleActiveVariant ? (int) $this->single_variant_id : null,
                'requires_selection' => ! $hasSingleActiveVariant,
            ],
            'in_stock' => (bool) $this->in_stock,
        ];
    }
}
