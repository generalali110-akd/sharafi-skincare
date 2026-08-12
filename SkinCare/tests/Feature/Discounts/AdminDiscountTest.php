<?php

namespace Tests\Feature\Discounts;

use App\Models\AuditLog;
use App\Models\DiscountRule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDiscountTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_admin_can_create_discount_and_change_is_audited(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('slug', 'admin')->firstOrFail());

        $this->actingAs($admin)->postJson('/api/v1/admin/discounts', [
            'code' => ' summer_20 ',
            'name' => 'Summer 20',
            'kind' => 'percentage',
            'value' => 2_000,
            'min_subtotal_irr' => 1_000_000,
            'max_discount_irr' => 500_000,
            'usage_limit_total' => 100,
            'usage_limit_per_user' => 1,
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.code', 'SUMMER_20');

        $this->assertDatabaseHas('discount_rules', [
            'code' => 'SUMMER_20',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $admin->id,
            'action' => 'discount.created',
        ]);
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_support_role_cannot_write_discounts(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $support = User::factory()->create();
        $support->roles()->attach(Role::query()->where('slug', 'support')->firstOrFail());

        $this->actingAs($support)->postJson('/api/v1/admin/discounts', [
            'code' => 'NOACCESS',
            'name' => 'No access',
            'kind' => 'fixed',
            'value' => 100_000,
        ])->assertForbidden();

        $this->assertSame(0, DiscountRule::query()->count());
    }

    public function test_percentage_discount_over_one_hundred_percent_is_rejected(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::query()->where('slug', 'admin')->firstOrFail());

        $this->actingAs($admin)->postJson('/api/v1/admin/discounts', [
            'code' => 'TOOMUCH',
            'name' => 'Too much',
            'kind' => 'percentage',
            'value' => 10_001,
        ])->assertUnprocessable();

        $this->assertSame(0, DiscountRule::query()->count());
    }
}
