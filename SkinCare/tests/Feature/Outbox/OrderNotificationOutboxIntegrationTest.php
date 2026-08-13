<?php

namespace Tests\Feature\Outbox;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderNotificationOutboxIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_and_payment_events_are_enqueued_once_without_copying_mobile_into_payload(): void
    {
        $user = User::factory()->create(['mobile' => '09121234567']);
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create(['price_irr' => 1_000_000]);
        $inventory = InventoryItem::factory()->create([
            'variant_id' => $variant->getKey(),
            'on_hand' => 5,
            'reserved' => 0,
        ]);
        $address = Address::query()->create([
            'user_id' => $user->getKey(),
            'recipient_name' => 'کاربر تست',
            'mobile' => '09121234567',
            'province' => 'تهران',
            'city' => 'تهران',
            'postal_code' => '1234567890',
            'address_line' => 'آدرس تست',
            'is_default' => true,
        ]);

        $this->actingAs($user)
            ->putJson('/api/v1/cart/items/'.$variant->getKey(), ['quantity' => 1])
            ->assertOk();

        $payload = [
            'address_id' => $address->getKey(),
            'shipping_method' => 'standard',
        ];
        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'order-outbox-idempotency-01')
            ->postJson('/api/v1/orders', $payload)
            ->assertCreated();
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'order-outbox-idempotency-01')
            ->postJson('/api/v1/orders', $payload)
            ->assertOk();

        $order = Order::query()->where('order_number', $first->json('data.order_number'))->firstOrFail();
        $createdKey = 'order:'.$order->getKey().':order_created:sms';
        $this->assertSame(1, OutboxMessage::query()->where('event_key', $createdKey)->count());
        $created = OutboxMessage::query()->where('event_key', $createdKey)->firstOrFail();
        $this->assertArrayNotHasKey('mobile', $created->payload);
        $this->assertStringNotContainsString('09121234567', json_encode($created->payload, JSON_THROW_ON_ERROR));
        $this->assertTrue($created->expires_at->equalTo($order->reservation_expires_at));
        $this->assertSame(1, $inventory->fresh()->reserved);

        $payment = Payment::query()->create([
            'order_id' => $order->getKey(),
            'amount_irr' => $order->total_irr,
            'currency' => 'IRR',
            'provider' => 'test-gateway',
            'status' => PaymentStatus::Pending,
        ]);
        $attempt = PaymentAttempt::query()->create([
            'payment_id' => $payment->getKey(),
            'attempt_number' => 1,
            'public_id' => (string) Str::ulid(),
            'idempotency_key_hash' => hash('sha256', 'payment-outbox-attempt-01'),
            'provider' => 'test-gateway',
            'status' => PaymentAttemptStatus::Pending,
            'amount_irr' => $order->total_irr,
        ]);
        $dedupeKey = hash('sha256', 'payment-outbox-event-01');
        $payloadHash = hash('sha256', 'payment-outbox-payload-01');

        $settlement = app(PaymentSettlementService::class);
        $settlement->settleSuccessful($attempt, 'TX-OUTBOX-1', $dedupeKey, $payloadHash);
        $settlement->settleSuccessful($attempt->fresh(), 'TX-OUTBOX-1', $dedupeKey, $payloadHash);

        $paidKey = 'order:'.$order->getKey().':payment_succeeded:sms';
        $this->assertSame(1, OutboxMessage::query()->where('event_key', $paidKey)->count());
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame(4, $inventory->fresh()->on_hand);
        $this->assertSame(0, $inventory->fresh()->reserved);
    }
}
