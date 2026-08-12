<?php

namespace Tests\Feature\Commerce;

use App\Contracts\PaymentGateway;
use App\Enums\DiscountRedemptionStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\Address;
use App\Models\DiscountRedemption;
use App\Models\DiscountRule;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commerce\OrderStateMachine;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

class DiscountPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_percentage_coupon_is_server_calculated_reserved_and_released_on_cancel(): void
    {
        $user = User::factory()->create();
        [$variant, $inventory] = $this->purchasableVariant(price: 1_000_000, stock: 5);
        $address = $this->addressFor($user);
        $rule = $this->discountRule(code: 'SAVE10', kind: 'percentage', value: 1_000, totalLimit: 10, perUserLimit: 1);

        $this->actingAs($user)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 2])->assertOk();

        $this->actingAs($user)
            ->postJson('/api/v1/checkout/quote', ['shipping_method' => 'standard', 'coupon_code' => 'save10'])
            ->assertOk()
            ->assertJsonPath('data.subtotal_irr', 2_000_000)
            ->assertJsonPath('data.discount_irr', 200_000)
            ->assertJsonPath('data.total_irr', 2_250_000)
            ->assertJsonPath('data.coupon_code', 'SAVE10');

        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'coupon-order-key-0001')
            ->postJson('/api/v1/orders', [
                'address_id' => $address->id,
                'shipping_method' => 'standard',
                'coupon_code' => 'SAVE10',
            ])
            ->assertCreated()
            ->assertJsonPath('data.discount_irr', 200_000);

        $order = Order::query()->where('order_number', $response->json('data.order_number'))->firstOrFail();
        $redemption = DiscountRedemption::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame($rule->id, $order->discount_rule_id);
        $this->assertSame(DiscountRedemptionStatus::Reserved, $redemption->status);
        $this->assertSame(2, $inventory->refresh()->reserved);

        $this->actingAs($user)->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertOk();

        $this->assertSame(DiscountRedemptionStatus::Released, $redemption->refresh()->status);
        $this->assertSame(0, $inventory->refresh()->reserved);
    }

    public function test_reserved_coupon_counts_against_limit_and_release_makes_it_available_again(): void
    {
        $rule = $this->discountRule(code: 'ONLYONE', kind: 'fixed', value: 100_000, totalLimit: 1, perUserLimit: 1);
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        [$variant] = $this->purchasableVariant(price: 1_000_000, stock: 5);

        $firstAddress = $this->addressFor($firstUser);
        $this->actingAs($firstUser)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])->assertOk();
        $firstOrder = $this->actingAs($firstUser)
            ->withHeader('Idempotency-Key', 'limit-first-order-01')
            ->postJson('/api/v1/orders', [
                'address_id' => $firstAddress->id,
                'shipping_method' => 'standard',
                'coupon_code' => $rule->code,
            ])->assertCreated();

        $secondAddress = $this->addressFor($secondUser);
        $this->actingAs($secondUser)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])->assertOk();
        $this->actingAs($secondUser)
            ->withHeader('Idempotency-Key', 'limit-second-order-1')
            ->postJson('/api/v1/orders', [
                'address_id' => $secondAddress->id,
                'shipping_method' => 'standard',
                'coupon_code' => $rule->code,
            ])->assertConflict();

        $this->actingAs($firstUser)
            ->postJson('/api/v1/orders/'.$firstOrder->json('data.order_number').'/cancel')
            ->assertOk();

        $this->actingAs($secondUser)
            ->withHeader('Idempotency-Key', 'limit-second-order-2')
            ->postJson('/api/v1/orders', [
                'address_id' => $secondAddress->id,
                'shipping_method' => 'standard',
                'coupon_code' => $rule->code,
            ])->assertCreated();
    }

    public function test_same_order_idempotency_key_with_different_payload_conflicts(): void
    {
        $user = User::factory()->create();
        [$variant] = $this->purchasableVariant(price: 1_000_000, stock: 4);
        $firstAddress = $this->addressFor($user);
        $secondAddress = Address::query()->create([
            'user_id' => $user->id,
            'recipient_name' => 'آدرس دوم',
            'mobile' => '09121234567',
            'province' => 'تهران',
            'city' => 'تهران',
            'postal_code' => '1234567891',
            'address_line' => 'آدرس دوم',
            'is_default' => false,
        ]);

        $this->actingAs($user)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])->assertOk();
        $key = 'same-key-different-payload';
        $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/orders', [
            'address_id' => $firstAddress->id,
            'shipping_method' => 'standard',
        ])->assertCreated();

        $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/orders', [
            'address_id' => $secondAddress->id,
            'shipping_method' => 'standard',
        ])->assertConflict();

        $stored = Order::query()->firstOrFail();
        $this->assertSame(hash('sha256', $key), $stored->idempotency_key);
        $this->assertNotSame($key, $stored->idempotency_key);
    }

    public function test_invalid_order_transition_is_rejected(): void
    {
        $user = User::factory()->create();
        [$variant] = $this->purchasableVariant(price: 1_000_000, stock: 3);
        $address = $this->addressFor($user);
        $this->actingAs($user)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])->assertOk();
        $response = $this->actingAs($user)->withHeader('Idempotency-Key', 'state-machine-key-01')->postJson('/api/v1/orders', [
            'address_id' => $address->id,
            'shipping_method' => 'standard',
        ])->assertCreated();

        $order = Order::query()->where('order_number', $response->json('data.order_number'))->firstOrFail();
        $this->expectException(CheckoutConflictException::class);
        app(OrderStateMachine::class)->transition($order, OrderStatus::Shipped, $user, 'invalid_test');
    }

    public function test_null_payment_driver_fails_closed_without_creating_financial_records(): void
    {
        $user = User::factory()->create();
        $order = $this->createPendingOrder($user, 'null-gateway-order-1');

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'null-payment-key-01')
            ->postJson("/api/v1/orders/{$order->order_number}/payment-attempts")
            ->assertServiceUnavailable();

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_verified_payment_settles_reserved_stock_and_is_idempotent(): void
    {
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
        $user = User::factory()->create();
        $rule = $this->discountRule(code: 'PAY10', kind: 'percentage', value: 1_000, totalLimit: 5, perUserLimit: 1);
        [$variant, $inventory] = $this->purchasableVariant(price: 1_000_000, stock: 5);
        $address = $this->addressFor($user);
        $this->actingAs($user)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 2])->assertOk();
        $response = $this->actingAs($user)->withHeader('Idempotency-Key', 'paid-order-key-0001')->postJson('/api/v1/orders', [
            'address_id' => $address->id,
            'shipping_method' => 'standard',
            'coupon_code' => $rule->code,
        ])->assertCreated();
        $order = Order::query()->where('order_number', $response->json('data.order_number'))->firstOrFail();

        $attemptResponse = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'payment-attempt-key-1')
            ->postJson("/api/v1/orders/{$order->order_number}/payment-attempts")
            ->assertCreated();
        $attempt = PaymentAttempt::query()->where('public_id', $attemptResponse->json('data.attempt.attempt_id'))->firstOrFail();

        $service = app(PaymentSettlementService::class);
        $dedupeKey = hash('sha256', 'event-dedupe-10001');
        $payloadHash = hash('sha256', 'payload-1');
        $service->settleSuccessful($attempt, 'TX-10001', $dedupeKey, $payloadHash);

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(3, $inventory->refresh()->on_hand);
        $this->assertSame(0, $inventory->reserved);
        $this->assertSame(PaymentStatus::Paid, Payment::query()->firstOrFail()->status);
        $this->assertSame(DiscountRedemptionStatus::Consumed, DiscountRedemption::query()->firstOrFail()->status);
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'sale_settlement')->count());

        $service->settleSuccessful($attempt->refresh(), 'TX-10001', $dedupeKey, $payloadHash);
        $this->assertSame(3, $inventory->refresh()->on_hand);
        $this->assertSame(1, PaymentEvent::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('type', 'sale_settlement')->count());
    }

    public function test_replayed_payment_event_with_same_dedupe_key_and_different_payload_is_rejected(): void
    {
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
        $user = User::factory()->create();
        $order = $this->createPendingOrder($user, 'replay-order-key-01');
        $attemptResponse = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'replay-payment-key-1')
            ->postJson("/api/v1/orders/{$order->order_number}/payment-attempts")
            ->assertCreated();
        $attempt = PaymentAttempt::query()->where('public_id', $attemptResponse->json('data.attempt.attempt_id'))->firstOrFail();
        $dedupeKey = hash('sha256', 'provider-event-replay-1');

        $service = app(PaymentSettlementService::class);
        $service->settleSuccessful($attempt, 'TX-REPLAY-1', $dedupeKey, hash('sha256', 'payload-a'));

        $this->expectException(CheckoutConflictException::class);
        $service->settleSuccessful($attempt->refresh(), 'TX-REPLAY-1', $dedupeKey, hash('sha256', 'payload-b'));
    }

    public function test_late_verified_payment_after_cancel_goes_to_refund_pending_without_reselling_stock(): void
    {
        $this->app->instance(PaymentGateway::class, new FakePaymentGateway);
        $user = User::factory()->create();
        [$variant, $inventory] = $this->purchasableVariant(price: 1_000_000, stock: 3);
        $address = $this->addressFor($user);
        $this->actingAs($user)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])->assertOk();
        $response = $this->actingAs($user)->withHeader('Idempotency-Key', 'late-paid-order-key')->postJson('/api/v1/orders', [
            'address_id' => $address->id,
            'shipping_method' => 'standard',
        ])->assertCreated();
        $order = Order::query()->where('order_number', $response->json('data.order_number'))->firstOrFail();

        $attemptResponse = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'late-payment-key-01')
            ->postJson("/api/v1/orders/{$order->order_number}/payment-attempts")
            ->assertCreated();
        $attempt = PaymentAttempt::query()->where('public_id', $attemptResponse->json('data.attempt.attempt_id'))->firstOrFail();

        $this->actingAs($user)->postJson("/api/v1/orders/{$order->order_number}/cancel")->assertOk();
        $this->assertSame(0, $inventory->refresh()->reserved);

        app(PaymentSettlementService::class)->settleSuccessful(
            $attempt,
            'TX-LATE-1',
            hash('sha256', 'event-late-1'),
            hash('sha256', 'late-payload'),
        );

        $this->assertSame(OrderStatus::RefundPending, $order->refresh()->status);
        $this->assertSame(PaymentStatus::RefundPending, Payment::query()->firstOrFail()->status);
        $this->assertSame(3, $inventory->refresh()->on_hand);
        $this->assertSame(0, $inventory->reserved);
        $this->assertDatabaseCount('discount_redemptions', 0);
    }

    private function createPendingOrder(User $user, string $key): Order
    {
        [$variant] = $this->purchasableVariant(price: 1_000_000, stock: 3);
        $address = $this->addressFor($user);
        $this->actingAs($user)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])->assertOk();
        $response = $this->actingAs($user)->withHeader('Idempotency-Key', $key)->postJson('/api/v1/orders', [
            'address_id' => $address->id,
            'shipping_method' => 'standard',
        ])->assertCreated();

        return Order::query()->where('order_number', $response->json('data.order_number'))->firstOrFail();
    }

    private function purchasableVariant(int $price, int $stock): array
    {
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create(['price_irr' => $price]);
        $inventory = InventoryItem::factory()->create([
            'variant_id' => $variant->id,
            'on_hand' => $stock,
            'reserved' => 0,
        ]);

        return [$variant->load('product'), $inventory];
    }

    private function addressFor(User $user): Address
    {
        return Address::query()->create([
            'user_id' => $user->id,
            'recipient_name' => 'کاربر تست',
            'mobile' => '09121234567',
            'province' => 'تهران',
            'city' => 'تهران',
            'postal_code' => '1234567890',
            'address_line' => 'آدرس تست',
            'is_default' => true,
        ]);
    }

    private function discountRule(string $code, string $kind, int $value, ?int $totalLimit, ?int $perUserLimit): DiscountRule
    {
        return DiscountRule::query()->create([
            'code' => $code,
            'name' => $code,
            'kind' => $kind,
            'value' => $value,
            'min_subtotal_irr' => 0,
            'usage_limit_total' => $totalLimit,
            'usage_limit_per_user' => $perUserLimit,
            'is_active' => true,
        ]);
    }
}
