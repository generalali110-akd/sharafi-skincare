<?php

use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 80)->nullable();
            $table->string('recipient_name', 120);
            $table->string('mobile', 11);
            $table->string('province', 100);
            $table->string('city', 100);
            $table->string('postal_code', 10);
            $table->text('address_line');
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('carts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestampsTz();
        });

        Schema::create('cart_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->timestampsTz();

            $table->unique(['cart_id', 'variant_id']);
            $table->index('variant_id');
        });

        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number', 40)->unique();
            $table->string('idempotency_key', 100);
            $table->string('status', 32)->default(OrderStatus::PendingPayment->value)->index();
            $table->string('shipping_method', 24);
            $table->json('address_snapshot');
            $table->unsignedBigInteger('subtotal_irr');
            $table->unsignedBigInteger('discount_irr')->default(0);
            $table->unsignedBigInteger('shipping_irr')->default(0);
            $table->unsignedBigInteger('total_irr');
            $table->timestampTz('reservation_expires_at')->nullable()->index();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('product_name', 180);
            $table->string('variant_title', 160)->nullable();
            $table->string('sku', 100);
            $table->unsignedSmallInteger('quantity');
            $table->unsignedBigInteger('unit_price_irr');
            $table->unsignedBigInteger('discount_irr')->default(0);
            $table->unsignedBigInteger('line_total_irr');
            $table->timestampsTz();

            $table->index(['order_id', 'variant_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX addresses_one_default_per_user ON addresses (user_id) WHERE is_default = true');
            DB::statement('ALTER TABLE cart_items ADD CONSTRAINT cart_items_quantity_check CHECK (quantity BETWEEN 1 AND 99)');
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_status_check CHECK (status IN ('pending_payment', 'paid', 'cancelled', 'expired'))");
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_shipping_method_check CHECK (shipping_method IN ('standard', 'courier'))");
            DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_money_check CHECK (subtotal_irr >= 0 AND discount_irr >= 0 AND shipping_irr >= 0 AND total_irr >= 0 AND total_irr = subtotal_irr - discount_irr + shipping_irr)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity BETWEEN 1 AND 99)');
            DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_money_check CHECK (unit_price_irr >= 0 AND discount_irr >= 0 AND line_total_irr >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('addresses');
    }
};
