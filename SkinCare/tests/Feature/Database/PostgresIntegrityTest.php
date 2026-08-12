<?php

namespace Tests\Feature\Database;

use App\Models\InventoryItem;
use App\Models\ProductVariant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PostgresIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_rejects_negative_variant_price(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific integrity test.');
        }

        $this->expectException(QueryException::class);

        ProductVariant::factory()->create([
            'price_irr' => -1,
        ]);
    }

    public function test_postgres_rejects_reserved_inventory_above_on_hand(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific integrity test.');
        }

        $this->expectException(QueryException::class);

        InventoryItem::factory()->create([
            'on_hand' => 3,
            'reserved' => 4,
        ]);
    }
}
