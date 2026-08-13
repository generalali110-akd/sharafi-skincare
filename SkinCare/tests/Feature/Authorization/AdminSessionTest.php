<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\SystemAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_read_admin_session(): void
    {
        $this->getJson('/api/v1/admin/session')->assertUnauthorized();
    }

    public function test_customer_without_admin_permissions_is_forbidden(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/admin/session')
            ->assertForbidden();
    }

    public function test_admin_session_returns_only_minimal_identity_and_effective_permissions(): void
    {
        $this->seed(SystemAccessSeeder::class);

        $user = User::factory()->create([
            'name' => 'مدیر کاتالوگ',
            'mobile' => '09123456789',
        ]);
        $role = Role::query()->where('slug', 'catalog-manager')->firstOrFail();
        $user->roles()->attach($role);

        $this->actingAs($user)
            ->getJson('/api/v1/admin/session')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'name' => 'مدیر کاتالوگ',
                    'mobile' => '09123456789',
                    'permissions' => [
                        Permissions::CATALOG_READ,
                        Permissions::CATALOG_WRITE,
                        Permissions::INVENTORY_READ,
                    ],
                ],
            ]);
    }

    public function test_super_admin_receives_all_known_permissions_without_role_metadata(): void
    {
        $this->seed(SystemAccessSeeder::class);

        $user = User::factory()->create();
        $role = Role::query()->where('slug', 'super-admin')->firstOrFail();
        $user->roles()->attach($role);

        $expectedPermissions = Permissions::all();
        sort($expectedPermissions);

        $response = $this->actingAs($user)
            ->getJson('/api/v1/admin/session')
            ->assertOk();

        $this->assertSame($expectedPermissions, $response->json('data.permissions'));
        $response->assertJsonMissingPath('data.roles');
        $response->assertJsonMissingPath('data.id');
    }
}
