<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE discount_rules ADD CONSTRAINT discount_rules_max_discount_check CHECK (max_discount_irr IS NULL OR max_discount_irr > 0)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE discount_rules DROP CONSTRAINT IF EXISTS discount_rules_max_discount_check');
        }
    }
};
