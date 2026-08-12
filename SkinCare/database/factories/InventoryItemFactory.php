<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryItemFactory extends Factory
{
    public function definition(): array
    {
        $onHand = fake()->numberBetween(0, 100);

        return [
            'variant_id' => ProductVariant::factory(),
            'on_hand' => $onHand,
            'reserved' => fake()->numberBetween(0, $onHand),
            'reorder_level' => 5,
        ];
    }
}
