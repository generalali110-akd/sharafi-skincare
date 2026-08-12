<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'title' => null,
            'barcode' => null,
            'price_irr' => fake()->numberBetween(1_000_000, 20_000_000),
            'compare_at_price_irr' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
