<?php

namespace Tests\Feature\Catalog;

use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCatalogWriteTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_manager_can_create_product_but_cannot_bypass_inventory_permission(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::query()->where('slug', 'catalog-manager')->firstOrFail());
        $brand = Brand::factory()->create();
        $category = Category::factory()->create();

        $payload = [
            'name' => 'Hydrating Serum',
            'slug' => 'hydrating-serum',
            'brand_id' => $brand->id,
            'status' => 'active',
            'category_ids' => [$category->id],
            'variants' => [[
                'sku' => 'SH-SERUM-30',
                'title' => '30 ml',
                'price_irr' => 4_850_000,
                'is_active' => true,
            ]],
        ];

        $response = $this->actingAs($manager)
            ->postJson('/api/v1/admin/catalog/products', $payload)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Hydrating Serum')
            ->assertJsonPath('data.variants.0.inventory.on_hand', 0);

        $productId = (int) $response->json('data.id');
        $variantId = (int) $response->json('data.variants.0.id');

        $this->assertDatabaseHas('products', ['id' => $productId, 'status' => 'active']);
        $this->assertDatabaseHas('inventory_items', ['variant_id' => $variantId, 'on_hand' => 0, 'reserved' => 0]);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'catalog.product.created',
            'subject_id' => (string) $productId,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/admin/inventory/{$variantId}/adjust", [
                'delta' => 10,
                'reason' => 'Attempted stock update',
            ])
            ->assertForbidden();

        $this->assertSame(0, InventoryItem::query()->where('variant_id', $variantId)->value('on_hand'));
    }

    public function test_catalog_write_rejects_inventory_fields_and_variant_updates_do_not_change_stock(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::query()->where('slug', 'catalog-manager')->firstOrFail());

        $this->actingAs($manager)
            ->postJson('/api/v1/admin/catalog/products', [
                'name' => 'Unsafe Product',
                'slug' => 'unsafe-product',
                'status' => 'draft',
                'variants' => [[
                    'sku' => 'UNSAFE-1',
                    'price_irr' => 1_000_000,
                    'on_hand' => 999,
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants.0.on_hand');

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price_irr' => 1_000_000]);
        InventoryItem::factory()->create(['variant_id' => $variant->id, 'on_hand' => 8, 'reserved' => 1]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/catalog/variants/{$variant->id}", [
                'price_irr' => 1_250_000,
            ])
            ->assertOk()
            ->assertJsonPath('data.price_irr', 1_250_000)
            ->assertJsonPath('data.inventory.on_hand', 8);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/catalog/variants/{$variant->id}", [
                'on_hand' => 0,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('on_hand');

        $this->assertSame(8, $variant->inventory()->value('on_hand'));
    }

    public function test_product_update_rejects_nested_variant_mutations(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::query()->where('slug', 'catalog-manager')->firstOrFail());
        $product = Product::factory()->create();

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/catalog/products/{$product->id}", [
                'variants' => [['sku' => 'BYPASS-1']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variants');
    }

    public function test_last_active_variant_of_active_product_cannot_be_disabled(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::query()->where('slug', 'catalog-manager')->firstOrFail());

        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'is_active' => true]);
        InventoryItem::factory()->create(['variant_id' => $variant->id]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/catalog/variants/{$variant->id}", [
                'is_active' => false,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($variant->fresh()->is_active);
    }
}
