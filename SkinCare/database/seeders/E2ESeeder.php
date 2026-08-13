<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class E2ESeeder extends Seeder
{
    public const CUSTOMER_MOBILE = '09120000002';

    public const ADMIN_MOBILE = '09120000003';

    public const PRODUCT_NAME = 'سرم تست E2E شرفی';

    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('E2ESeeder is restricted to the testing environment.');
        }

        $this->call(SystemAccessSeeder::class);

        $admin = User::query()->updateOrCreate(
            ['mobile' => self::ADMIN_MOBILE],
            [
                'name' => 'مدیر تست E2E',
                'status' => 'active',
                'mobile_verified_at' => now(),
            ],
        );
        $adminRole = Role::query()->where('slug', 'admin')->firstOrFail();
        $admin->roles()->syncWithoutDetaching([$adminRole->id]);

        Brand::query()->updateOrCreate(
            ['slug' => 'e2e-brand'],
            ['name' => 'برند تست E2E', 'is_active' => true],
        );
        $category = Category::query()->updateOrCreate(
            ['slug' => 'e2e-category'],
            ['name' => 'مراقبت تست E2E', 'is_active' => true, 'sort_order' => 0],
        );
        $brand = Brand::query()->where('slug', 'e2e-brand')->firstOrFail();

        $product = Product::query()->updateOrCreate(
            ['slug' => 'e2e-test-serum'],
            [
                'brand_id' => $brand->id,
                'name' => self::PRODUCT_NAME,
                'short_description' => 'محصول قطعی برای تست مرورگر فروشگاه.',
                'status' => ProductStatus::Active->value,
                'is_featured' => true,
                'published_at' => now()->subMinute(),
            ],
        );
        $product->categories()->sync([$category->id]);

        $variant = ProductVariant::query()->updateOrCreate(
            ['sku' => 'E2E-SERUM-001'],
            [
                'product_id' => $product->id,
                'title' => 'نسخه تست',
                'price_irr' => 2_500_000,
                'compare_at_price_irr' => 3_000_000,
                'is_active' => true,
                'sort_order' => 0,
            ],
        );

        InventoryItem::query()->updateOrCreate(
            ['variant_id' => $variant->id],
            ['on_hand' => 20, 'reserved' => 0, 'reorder_level' => 3],
        );
    }
}
