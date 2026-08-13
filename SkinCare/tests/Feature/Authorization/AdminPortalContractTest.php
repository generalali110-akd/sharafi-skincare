<?php

namespace Tests\Feature\Authorization;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\SystemAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPortalContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemAccessSeeder::class);
    }

    public function test_customer_without_admin_permissions_cannot_open_admin_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/admin/session')
            ->assertForbidden();
    }

    public function test_admin_session_returns_only_identity_roles_and_permissions(): void
    {
        $user = $this->userWithRole('catalog-manager');

        $response = $this->actingAs($user)
            ->getJson('/api/v1/admin/session')
            ->assertOk()
            ->assertJsonPath('data.user.mobile', $user->mobile)
            ->assertJsonPath('data.roles.0', 'catalog-manager');

        $this->assertContains(Permissions::CATALOG_READ, $response->json('data.permissions'));
        $this->assertNotContains(Permissions::ORDERS_WRITE, $response->json('data.permissions'));
        $response->assertJsonMissingPath('data.user.id')
            ->assertJsonMissingPath('data.user.mobile_verified_at')
            ->assertJsonMissingPath('data.user.status');
    }

    public function test_customer_listing_requires_permission_and_exposes_minimal_support_fields(): void
    {
        $customer = User::factory()->create([
            'name' => 'مشتری تست',
            'mobile' => '09121234567',
        ]);
        $catalogManager = $this->userWithRole('catalog-manager');

        $this->actingAs($catalogManager)
            ->getJson('/api/v1/admin/customers')
            ->assertForbidden();

        $orderManager = $this->userWithRole('order-manager');
        $response = $this->actingAs($orderManager)
            ->getJson('/api/v1/admin/customers?q=09121234567')
            ->assertOk()
            ->assertJsonPath('data.0.mobile', '09121234567')
            ->assertJsonPath('data.0.name', 'مشتری تست');

        $response->assertJsonMissingPath('data.0.mobile_verified_at')
            ->assertJsonMissingPath('data.0.remember_token')
            ->assertJsonMissingPath('data.0.addresses');
    }

    public function test_dashboard_requires_dedicated_permission_and_does_not_expose_customer_pii(): void
    {
        $catalogManager = $this->userWithRole('catalog-manager');

        $this->actingAs($catalogManager)
            ->getJson('/api/v1/admin/dashboard')
            ->assertForbidden();

        $admin = $this->userWithRole('admin');
        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'timezone',
                    'today' => [
                        'paid_sales_irr',
                        'new_orders',
                        'new_customers',
                        'low_stock_variants',
                    ],
                    'sales_7d',
                    'recent_orders',
                    'low_stock',
                ],
            ])
            ->assertJsonPath('data.timezone', 'Asia/Tehran');

        $this->assertStringNotContainsString('mobile', $response->getContent());
    }

    public function test_catalog_reader_can_load_product_detail_for_editing(): void
    {
        $user = $this->userWithRole('catalog-manager');
        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->for($product)->create();

        $this->actingAs($user)
            ->getJson('/api/v1/admin/catalog/products/'.$product->getKey())
            ->assertOk()
            ->assertJsonPath('data.id', $product->getKey())
            ->assertJsonPath('data.variants.0.id', $variant->getKey());
    }

    private function userWithRole(string $slug): User
    {
        $user = User::factory()->create();
        $role = Role::query()->where('slug', $slug)->firstOrFail();
        $user->roles()->attach($role);

        return $user;
    }
}
