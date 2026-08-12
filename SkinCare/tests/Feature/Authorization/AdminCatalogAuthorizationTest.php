<?php

namespace Tests\Feature\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\SystemAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCatalogAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_admin_catalog(): void
    {
        $this->getJson('/api/v1/admin/catalog/products')->assertUnauthorized();
    }

    public function test_authenticated_customer_without_permission_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/admin/catalog/products')
            ->assertForbidden();
    }

    public function test_catalog_manager_can_read_admin_catalog(): void
    {
        $this->seed(SystemAccessSeeder::class);

        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'catalog-manager')->firstOrFail();
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->getJson('/api/v1/admin/catalog/products')
            ->assertOk();

        $this->assertTrue($user->fresh()->hasPermission(Permissions::CATALOG_READ));
        $this->assertFalse($user->fresh()->hasPermission(Permissions::ORDERS_WRITE));
        $this->assertTrue(Permission::query()->where('slug', Permissions::CATALOG_WRITE)->exists());
    }
}
