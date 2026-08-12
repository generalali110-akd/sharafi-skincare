<?php

namespace App\Http\Resources\Api\V1\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'short_description' => $this->short_description,
            'description' => $this->description,
            'brand' => $this->brand ? [
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
            ] : null,
            'categories' => $this->categories->map(fn ($category) => [
                'name' => $category->name,
                'slug' => $category->slug,
            ])->values(),
            'variants' => $this->variants->map(fn ($variant) => [
                'id' => $variant->id,
                'sku' => $variant->sku,
                'title' => $variant->title,
                'price' => [
                    'currency' => 'IRR',
                    'amount' => (int) $variant->price_irr,
                    'compare_at' => $variant->compare_at_price_irr !== null
                        ? (int) $variant->compare_at_price_irr
                        : null,
                ],
                'in_stock' => ($variant->inventory?->available ?? 0) > 0,
            ])->values(),
        ];
    }
}
