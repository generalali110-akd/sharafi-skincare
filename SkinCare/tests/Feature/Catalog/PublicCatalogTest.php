<?php

namespace Tests\Feature\Catalog;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_only_returns_published_products(): void
    {
        $published = Product::factory()->published()->create(['name' => 'Published Product']);
        $variant = ProductVariant::factory()->create(['product_id' => $published->id, 'price_irr' => 4_850_000]);

        Product::factory()->create(['name' => 'Draft Product']);
        Product::factory()->create([
            'name' => 'Future Product',
            'status' => ProductStatus::Active,
            'published_at' => now()->addDay(),
        ]);

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Published Product')
            ->assertJsonPath('data.0.pricing.min', 4_850_000)
            ->assertJsonPath('data.0.purchase.variant_id', $variant->id)
            ->assertJsonPath('data.0.purchase.requires_selection', false);
    }

    public function test_catalog_does_not_guess_a_variant_when_product_requires_selection(): void
    {
        $product = Product::factory()->published()->create(['name' => 'Multi Variant Product']);
        ProductVariant::factory()->count(2)->create(['product_id' => $product->id]);

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonPath('data.0.purchase.variant_id', null)
            ->assertJsonPath('data.0.purchase.requires_selection', true);
    }

    public function test_catalog_filters_by_category_brand_and_price_and_reports_stock_without_quantity(): void
    {
        $brand = Brand::factory()->create(['slug' => 'cerave']);
        $otherBrand = Brand::factory()->create(['slug' => 'other']);
        $category = Category::factory()->create(['slug' => 'skin']);

        $matching = Product::factory()->published()->create([
            'name' => 'Hydrating Serum',
            'brand_id' => $brand->id,
        ]);
        $matching->categories()->attach($category);
        $variant = ProductVariant::factory()->create([
            'product_id' => $matching->id,
            'price_irr' => 4_850_000,
        ]);
        InventoryItem::factory()->create([
            'variant_id' => $variant->id,
            'on_hand' => 10,
            'reserved' => 2,
        ]);

        $straddling = Product::factory()->published()->create([
            'name' => 'No Variant In Range',
            'brand_id' => $brand->id,
        ]);
        $straddling->categories()->attach($category);
        ProductVariant::factory()->create([
            'product_id' => $straddling->id,
            'price_irr' => 1_000_000,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $straddling->id,
            'price_irr' => 10_000_000,
        ]);

        $other = Product::factory()->published()->create([
            'brand_id' => $otherBrand->id,
        ]);
        ProductVariant::factory()->create([
            'product_id' => $other->id,
            'price_irr' => 9_000_000,
        ]);

        $response = $this->getJson('/api/v1/catalog/products?category=skin&brand=cerave&min_price=4000000&max_price=5000000');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Hydrating Serum')
            ->assertJsonPath('data.0.in_stock', true);

        $this->assertArrayNotHasKey('available_stock', $response->json('data.0'));
    }

    public function test_product_detail_hides_exact_inventory_count(): void
    {
        $product = Product::factory()->published()->create(['slug' => 'hydrating-serum']);
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->id,
            'price_irr' => 4_850_000,
        ]);
        InventoryItem::factory()->create([
            'variant_id' => $variant->id,
            'on_hand' => 7,
            'reserved' => 1,
        ]);

        $response = $this->getJson('/api/v1/catalog/products/hydrating-serum');

        $response->assertOk()
            ->assertJsonPath('data.variants.0.price.amount', 4_850_000)
            ->assertJsonPath('data.variants.0.in_stock', true);

        $this->assertArrayNotHasKey('available_stock', $response->json('data.variants.0'));
        $this->assertArrayNotHasKey('on_hand', $response->json('data.variants.0'));
    }

    public function test_catalog_exposes_product_images_without_inventory_leakage(): void
    {
        $product = Product::factory()->published()->create([
            'name' => 'Photo Product',
            'slug' => 'photo-product',
        ]);
        ProductVariant::factory()->create(['product_id' => $product->id]);
        ProductImage::query()->create([
            'product_id' => $product->id,
            'disk' => 'public',
            'path' => 'products/photo-product.jpg',
            'alt_text' => 'Photo Product bottle',
            'is_primary' => true,
        ]);

        $this->getJson('/api/v1/catalog/products')
            ->assertOk()
            ->assertJsonPath('data.0.primary_image.alt_text', 'Photo Product bottle')
            ->assertJsonPath('data.0.primary_image.url', url('/storage/products/photo-product.jpg'));

        $this->getJson('/api/v1/catalog/products/photo-product')
            ->assertOk()
            ->assertJsonPath('data.images.0.alt_text', 'Photo Product bottle')
            ->assertJsonPath('data.images.0.is_primary', true);
    }

    public function test_invalid_price_range_is_rejected(): void
    {
        $this->getJson('/api/v1/catalog/products?min_price=5000000&max_price=1000000')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('max_price');
    }
}
