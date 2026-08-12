<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 160);
            $table->string('kind', 24);
            $table->unsignedBigInteger('value');
            $table->unsignedBigInteger('min_subtotal_irr')->default(0);
            $table->unsignedBigInteger('max_discount_irr')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedInteger('usage_limit_total')->nullable();
            $table->unsignedInteger('usage_limit_per_user')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('discount_rule_id')->nullable()->constrained('discount_rules')->restrictOnDelete();
            $table->string('coupon_code', 64)->nullable();
            $table->char('idempotency_fingerprint', 64)->nullable();
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('refund_pending_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();

            $table->index('discount_rule_id');
        });

        Schema::create('discount_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('discount_rule_id')->constrained('discount_rules')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('reserved');
            $table->unsignedBigInteger('discount_irr');
            $table->timestampTz('reserved_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->index(['discount_rule_id', 'status']);
            $table->index(['user_id', 'discount_rule_id', 'status']);
        });

        Schema::create('order_status_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_irr');
            $table->char('currency', 3)->default('IRR');
            $table->string('provider', 50);
            $table->string('status', 24)->default('pending')->index();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('refunded_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->char('public_id', 26)->unique();
            $table->char('idempotency_key_hash', 64);
            $table->string('provider', 50);
            $table->string('status', 24)->default('created')->index();
            $table->unsignedBigInteger('amount_irr');
            $table->string('authority', 190)->nullable();
            $table->string('transaction_id', 190)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->unique(['payment_id', 'attempt_number']);
            $table->unique(['payment_id', 'idempotency_key_hash']);
            $table->index(['provider', 'authority']);
            $table->index(['provider', 'transaction_id']);
        });

        Schema::create('payment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->string('provider', 50);
            $table->string('event_type', 80);
            $table->char('dedupe_key', 64)->unique();
            $table->char('payload_hash', 64);
            $table->timestampTz('occurred_at');
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['payment_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE discount_rules ADD CONSTRAINT discount_rules_kind_check CHECK (kind IN ('fixed', 'percentage'))");
            DB::statement("ALTER TABLE discount_rules ADD CONSTRAINT discount_rules_value_check CHECK (value > 0 AND (kind <> 'percentage' OR value <= 10000))");
            DB::statement('ALTER TABLE discount_rules ADD CONSTRAINT discount_rules_time_check CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)');
            DB::statement('ALTER TABLE discount_rules ADD CONSTRAINT discount_rules_limits_check CHECK ((usage_limit_total IS NULL OR usage_limit_total > 0) AND (usage_limit_per_user IS NULL OR usage_limit_per_user > 0))');
            DB::statement("ALTER TABLE discount_redemptions ADD CONSTRAINT discount_redemptions_status_check CHECK (status IN ('reserved', 'consumed', 'released'))");
            DB::statement('ALTER TABLE discount_redemptions ADD CONSTRAINT discount_redemptions_amount_check CHECK (discount_irr >= 0)');

            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'expired', 'refund_pending', 'refunded'))");

            DB::statement("ALTER TABLE order_status_transitions ADD CONSTRAINT order_status_transitions_to_check CHECK (to_status IN ('pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'expired', 'refund_pending', 'refunded'))");
            DB::statement("ALTER TABLE order_status_transitions ADD CONSTRAINT order_status_transitions_from_check CHECK (from_status IS NULL OR from_status IN ('pending_payment', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'expired', 'refund_pending', 'refunded'))");

            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'paid', 'failed', 'refund_pending', 'refunded'))");
            DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_currency_check CHECK (currency = 'IRR')");
            DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount_irr >= 0)');
            DB::statement("ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_status_check CHECK (status IN ('created', 'pending', 'succeeded', 'failed'))");
            DB::statement('ALTER TABLE payment_attempts ADD CONSTRAINT payment_attempts_amount_check CHECK (amount_irr >= 0 AND attempt_number > 0)');
            DB::statement('CREATE UNIQUE INDEX payment_attempts_provider_authority_unique ON payment_attempts (provider, authority) WHERE authority IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX payment_attempts_provider_transaction_unique ON payment_attempts (provider, transaction_id) WHERE transaction_id IS NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_status_transitions');
        Schema::dropIfExists('discount_redemptions');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_status_check');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_payment', 'paid', 'cancelled', 'expired'))");
        }

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['discount_rule_id']);
            $table->dropIndex(['discount_rule_id']);
            $table->dropColumn([
                'discount_rule_id', 'coupon_code', 'idempotency_fingerprint', 'processing_at',
                'shipped_at', 'delivered_at', 'refund_pending_at', 'refunded_at',
            ]);
        });

        Schema::dropIfExists('discount_rules');
    }
};
