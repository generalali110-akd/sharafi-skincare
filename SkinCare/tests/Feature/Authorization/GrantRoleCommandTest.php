<?php

namespace Tests\Feature\Authorization;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrantRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_can_be_granted_only_to_existing_verified_active_user(): void
    {
        $this->seed(SystemAccessSeeder::class);

        $user = User::factory()->create([
            'mobile' => '09121234567',
            'mobile_verified_at' => now(),
            'status' => 'active',
        ]);

        $this->artisan('access:grant-role', [
            'mobile' => '۰۹۱۲۱۲۳۴۵۶۷',
            'role' => 'catalog-manager',
            '--force' => true,
        ])->assertSuccessful();

        $role = Role::query()->where('slug', 'catalog-manager')->firstOrFail();
        $this->assertTrue($user->fresh()->roles()->whereKey($role->id)->exists());
    }

    public function test_unverified_user_cannot_receive_role(): void
    {
        $this->seed(SystemAccessSeeder::class);

        $user = User::factory()->create([
            'mobile' => '09121234567',
            'mobile_verified_at' => null,
        ]);

        $this->artisan('access:grant-role', [
            'mobile' => $user->mobile,
            'role' => 'catalog-manager',
            '--force' => true,
        ])->assertFailed();

        $this->assertCount(0, $user->fresh()->roles);
    }
}
