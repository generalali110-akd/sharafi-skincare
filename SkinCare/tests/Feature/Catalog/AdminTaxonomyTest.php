<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\SystemAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminTaxonomyTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_cycle_is_rejected(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::query()->where('slug', 'catalog-manager')->firstOrFail());

        $parent = Category::factory()->create(['parent_id' => null, 'slug' => 'parent']);
        $child = Category::factory()->create(['parent_id' => $parent->id, 'slug' => 'child']);

        $this->actingAs($manager)
            ->patchJson("/api/v1/admin/catalog/categories/{$parent->id}", [
                'parent_id' => $child->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');

        $this->assertNull($parent->fresh()->parent_id);
    }
}
