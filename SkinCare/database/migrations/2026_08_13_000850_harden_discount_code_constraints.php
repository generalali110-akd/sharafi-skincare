<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE discount_rules ADD CONSTRAINT discount_rules_code_format_check CHECK (code = UPPER(code) AND code ~ '^[A-Z0-9_-]{3,64}$')");
            DB::statement('CREATE UNIQUE INDEX discount_rules_code_upper_unique ON discount_rules (UPPER(code))');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS discount_rules_code_upper_unique');
            DB::statement('ALTER TABLE discount_rules DROP CONSTRAINT IF EXISTS discount_rules_code_format_check');
        }
    }
};
