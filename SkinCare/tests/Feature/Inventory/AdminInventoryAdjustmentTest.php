<?php

namespace Tests\Feature\Inventory;

use App\Models\AuditLog;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminInventoryAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorized_inventory_manager_can_adjust_inventory_and_change_is_audited(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::query()->where('slug', 'inventory-manager')->firstOrFail());

        $variant = ProductVariant::factory()->create();
        InventoryItem::factory()->create([
            'variant_id' => $variant->id,
            'on_hand' => 10,
            'reserved' => 2,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/admin/inventory/{$variant->id}/adjust", [
                'delta' => 5,
                'reason' => 'Warehouse recount',
            ])
            ->assertOk()
            ->assertJsonPath('data.inventory.on_hand', 15)
            ->assertJsonPath('data.inventory.available', 13);

        $this->assertDatabaseHas('inventory_movements', [
            'variant_id' => $variant->id,
            'type' => 'admin_adjustment',
            'quantity' => 5,
            'actor_user_id' => $manager->id,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->id,
            'action' => 'inventory.adjusted',
            'subject_id' => (string) $variant->id,
        ]);

        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_inventory_cannot_be_adjusted_below_reserved_quantity(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::query()->where('slug', 'inventory-manager')->firstOrFail());

        $variant = ProductVariant::factory()->create();
        InventoryItem::factory()->create([
            'variant_id' => $variant->id,
            'on_hand' => 10,
            'reserved' => 4,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/v1/admin/inventory/{$variant->id}/adjust", [
                'delta' => -7,
                'reason' => 'Damaged stock',
            ])
            ->assertConflict();

        $this->assertDatabaseHas('inventory_items', [
            'variant_id' => $variant->id,
            'on_hand' => 10,
            'reserved' => 4,
        ]);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_inventory_reorder_level_can_be_updated_without_creating_stock_movement(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::query()->where('slug', 'inventory-manager')->firstOrFail());

        $variant = ProductVariant::factory()->create();
        InventoryItem::factory()->create([
            'variant_id' => $variant->id,
            'on_hand' => 10,
            'reserved' => 0,
            'reorder_level' => 2,
        ]);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/inventory/{$variant->id}/settings", [
                'reorder_level' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.inventory.reorder_level', 5);

        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'inventory.settings.updated',
            'subject_id' => (string) $variant->id,
        ]);
    }
}
