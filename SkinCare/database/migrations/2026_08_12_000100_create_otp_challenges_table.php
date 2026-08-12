<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('mobile', 11)->index();
            $table->string('purpose', 40)->default('auth');
            $table->string('code_hash', 64);
            $table->text('context')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable()->index();
            $table->timestampsTz();

            $table->index(['mobile', 'purpose', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
