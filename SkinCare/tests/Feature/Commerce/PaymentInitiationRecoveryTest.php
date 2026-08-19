<?php

namespace Tests\Feature\Commerce;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

class PaymentInitiationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_orphaned_created_attempt_does_not_silently_return_without_redirect(): void
    {
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
        $user = User::factory()->create();
        $order = $this->createPendingOrder($user);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount_irr' => $order->total_irr,
            'currency' => 'IRR',
            'provider' => 'fake',
            'status' => PaymentStatus::Pending,
        ]);
        $key = 'orphaned-payment-key-01';
        PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'public_id' => (string) Str::ulid(),
            'idempotency_key_hash' => hash('sha256', $key),
            'provider' => 'fake',
            'status' => PaymentAttemptStatus::Created,
            'amount_irr' => $order->total_irr,
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/v1/orders/{$order->order_number}/payment-attempts")
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'payment_attempt_retry_required');

        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
    }

    public function test_new_attempt_after_verified_failure_moves_payment_back_to_pending(): void
    {
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
        $user = User::factory()->create();
        $order = $this->createPendingOrder($user);
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount_irr' => $order->total_irr,
            'currency' => 'IRR',
            'provider' => 'fake',
            'status' => PaymentStatus::Failed,
        ]);
        PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'public_id' => (string) Str::ulid(),
            'idempotency_key_hash' => hash('sha256', 'failed-payment-key-01'),
            'provider' => 'fake',
            'status' => PaymentAttemptStatus::Failed,
            'amount_irr' => $order->total_irr,
            'failure_code' => 'provider_verification_failed',
            'failed_at' => now(),
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'failed-payment-key-01')
            ->postJson("/api/v1/orders/{$order->order_number}/payment-attempts")
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'payment_attempt_retry_required');

        $this->assertSame(PaymentStatus::Failed, $payment->refresh()->status);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'retry-after-failed-payment')
            ->postJson("/api/v1/orders/{$order->order_number}/payment-attempts")
            ->assertCreated()
            ->assertJsonPath('data.payment.status', 'pending')
            ->assertJsonPath('data.payment.latest_attempt.status', 'pending');

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        $this->assertDatabaseCount('payment_attempts', 2);
    }

    private function createPendingOrder(User $user): Order
    {
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create(['price_irr' => 1_000_000]);
        InventoryItem::factory()->create([
            'variant_id' => $variant->id,
            'on_hand' => 3,
            'reserved' => 0,
        ]);
        $address = Address::query()->create([
            'user_id' => $user->id,
            'recipient_name' => 'کاربر تست',
            'mobile' => '09121234567',
            'province' => 'تهران',
            'city' => 'تهران',
            'postal_code' => '1234567890',
            'address_line' => 'آدرس تست',
            'is_default' => true,
        ]);

        $this->actingAs($user)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])->assertOk();
        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'orphaned-order-key-01')
            ->postJson('/api/v1/orders', [
                'address_id' => $address->id,
                'shipping_method' => 'standard',
            ])->assertCreated();

        return Order::query()->where('order_number', $response->json('data.order_number'))->firstOrFail();
    }
}
