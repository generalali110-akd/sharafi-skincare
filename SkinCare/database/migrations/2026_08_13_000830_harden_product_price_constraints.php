<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MAX_VARIANT_PRICE_IRR = 1_000_000_000_000;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_price_upper_check CHECK (price_irr <= '.self::MAX_VARIANT_PRICE_IRR.')');
            DB::statement('ALTER TABLE product_variants ADD CONSTRAINT product_variants_compare_price_upper_check CHECK (compare_at_price_irr IS NULL OR compare_at_price_irr <= '.self::MAX_VARIANT_PRICE_IRR.')');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_price_upper_check');
            DB::statement('ALTER TABLE product_variants DROP CONSTRAINT IF EXISTS product_variants_compare_price_upper_check');
        }
    }
};
