<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('topic', 32);
            $table->string('event_key', 190)->unique();
            $table->string('aggregate_type', 64);
            $table->string('aggregate_id', 190);
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('last_error', 190)->nullable();
            $table->timestampsTz();

            $table->index(['topic', 'processed_at', 'failed_at', 'available_at'], 'outbox_dispatch_idx');
            $table->index('locked_at');
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
