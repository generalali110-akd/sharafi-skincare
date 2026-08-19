<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('LocalDemoSeeder is restricted to local and testing environments.');
        }

        $this->call(SystemAccessSeeder::class);

        $brand = Brand::query()->updateOrCreate(
            ['slug' => 'sharafi-local'],
            ['name' => 'Sharafi Local', 'is_active' => true],
        );

        $category = Category::query()->updateOrCreate(
            ['slug' => 'local-skincare'],
            ['name' => 'Local Skincare', 'is_active' => true, 'sort_order' => 0],
        );

        $products = [
            ['slug' => 'local-vitamin-c-serum', 'sku' => 'LOCAL-SERUM-001', 'name' => 'Local Vitamin C Serum', 'price' => 2_900_000],
            ['slug' => 'local-hydrating-cream', 'sku' => 'LOCAL-CREAM-001', 'name' => 'Local Hydrating Cream', 'price' => 3_400_000],
            ['slug' => 'local-cleanser-gel', 'sku' => 'LOCAL-CLEANSER-001', 'name' => 'Local Cleanser Gel', 'price' => 1_850_000],
        ];

        foreach ($products as $index => $item) {
            $product = Product::query()->updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'brand_id' => $brand->id,
                    'name' => $item['name'],
                    'short_description' => 'Local demo product for development and browser QA.',
                    'description' => 'This product is seeded only for local development.',
                    'status' => ProductStatus::Active->value,
                    'is_featured' => $index === 0,
                    'published_at' => now()->subMinutes(10 - $index),
                ],
            );

            $product->categories()->syncWithoutDetaching([$category->id]);

            $variant = ProductVariant::query()->updateOrCreate(
                ['sku' => $item['sku']],
                [
                    'product_id' => $product->id,
                    'title' => 'Default',
                    'price_irr' => $item['price'],
                    'compare_at_price_irr' => $item['price'] + 500_000,
                    'is_active' => true,
                    'sort_order' => 0,
                ],
            );

            InventoryItem::query()->updateOrCreate(
                ['variant_id' => $variant->id],
                ['on_hand' => 25, 'reserved' => 0, 'reorder_level' => 3],
            );
        }
    }
}
