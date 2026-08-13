<?php

namespace Tests\Feature\Commerce;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Infrastructure\Payments\ZarinpalPaymentGateway;
use App\Models\Address;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZarinpalCallbackTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT_ID = '11111111-2222-3333-4444-555555555555';

    public function test_public_callback_verifies_server_side_and_settles_stock_only_once_for_100_then_101(): void
    {
        $this->app->instance(PaymentGateway::class, $this->gateway());
        [$order, $attempt, $inventory] = $this->pendingPayment();

        Http::fakeSequence()
            ->push($this->verifiedResponse(100))
            ->push($this->verifiedResponse(101));

        $query = http_build_query(['Authority' => $attempt->authority, 'Status' => 'OK']);
        $this->getJson('/api/v1/payments/zarinpal/callback?'.$query)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.order_number', $order->order_number)
            ->assertJsonPath('data.transaction_id', '445566');

        $this->assertSame(OrderStatus::Paid, $order->refresh()->status);
        $this->assertSame(4, $inventory->refresh()->on_hand);
        $this->assertSame(0, $inventory->reserved);
        $this->assertSame(PaymentStatus::Paid, $attempt->payment->refresh()->status);
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertSame(1, InventoryMovement::query()->where('type', 'sale_settlement')->count());

        $this->getJson('/api/v1/payments/zarinpal/callback?'.$query)
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');

        $this->assertSame(4, $inventory->refresh()->on_hand);
        $this->assertSame(0, $inventory->reserved);
        $this->assertDatabaseCount('payment_events', 1);
        $this->assertSame(1, InventoryMovement::query()->where('type', 'sale_settlement')->count());
    }

    public function test_nok_callback_never_marks_attempt_failed_or_releases_reservation_from_untrusted_query_string(): void
    {
        $this->app->instance(PaymentGateway::class, $this->gateway());
        [$order, $attempt, $inventory] = $this->pendingPayment();
        Http::preventStrayRequests();

        $query = http_build_query(['Authority' => $attempt->authority, 'Status' => 'NOK']);
        $this->getJson('/api/v1/payments/zarinpal/callback?'.$query)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(PaymentAttemptStatus::Pending, $attempt->refresh()->status);
        $this->assertSame(1, $inventory->refresh()->reserved);
        $this->assertSame(5, $inventory->on_hand);
        $this->assertDatabaseCount('payment_events', 0);
        Http::assertNothingSent();
    }

    public function test_unknown_authority_is_not_used_to_call_provider(): void
    {
        $this->app->instance(PaymentGateway::class, $this->gateway());
        Http::preventStrayRequests();

        $query = http_build_query(['Authority' => 'A'.str_repeat('Z', 35), 'Status' => 'OK']);
        $this->getJson('/api/v1/payments/zarinpal/callback?'.$query)->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_failed_server_verification_does_not_settle_order_or_stock(): void
    {
        $this->app->instance(PaymentGateway::class, $this->gateway());
        [$order, $attempt, $inventory] = $this->pendingPayment();
        Http::fake([
            'https://payment.zarinpal.com/pg/v4/payment/verify.json' => Http::response([
                'data' => [],
                'errors' => ['code' => -50, 'message' => 'Session is not valid.'],
            ], 422),
        ]);

        $query = http_build_query(['Authority' => $attempt->authority, 'Status' => 'OK']);
        $this->getJson('/api/v1/payments/zarinpal/callback?'.$query)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.failure_code', 'zarinpal_-50');

        $this->assertSame(OrderStatus::PendingPayment, $order->refresh()->status);
        $this->assertSame(1, $inventory->refresh()->reserved);
        $this->assertSame(5, $inventory->on_hand);
        $this->assertDatabaseCount('payment_events', 0);
    }

    private function pendingPayment(): array
    {
        $user = User::factory()->create();
        $product = Product::factory()->published()->create();
        $variant = ProductVariant::factory()->for($product)->create(['price_irr' => 1_000_000]);
        $inventory = InventoryItem::factory()->create([
            'variant_id' => $variant->id,
            'on_hand' => 5,
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
            ->withHeader('Idempotency-Key', 'zarinpal-order-key-0001')
            ->postJson('/api/v1/orders', [
                'address_id' => $address->id,
                'shipping_method' => 'standard',
            ])
            ->assertCreated();

        $order = Order::query()->where('order_number', $response->json('data.order_number'))->firstOrFail();
        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'amount_irr' => $order->total_irr,
            'currency' => 'IRR',
            'provider' => 'zarinpal',
            'status' => PaymentStatus::Pending,
        ]);
        $attempt = PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'attempt_number' => 1,
            'public_id' => (string) Str::ulid(),
            'idempotency_key_hash' => hash('sha256', 'zarinpal-attempt-key-01'),
            'provider' => 'zarinpal',
            'status' => PaymentAttemptStatus::Pending,
            'amount_irr' => $order->total_irr,
            'authority' => 'A'.str_repeat('Y', 35),
            'redirect_url' => 'https://payment.zarinpal.com/pg/StartPay/'.'A'.str_repeat('Y', 35),
            'requested_at' => now(),
        ]);
        $attempt->setRelation('payment', $payment);

        return [$order, $attempt, $inventory];
    }

    private function gateway(): ZarinpalPaymentGateway
    {
        return new ZarinpalPaymentGateway([
            'merchant_id' => self::MERCHANT_ID,
            'sandbox' => false,
            'base_url' => 'https://payment.zarinpal.com',
            'sandbox_base_url' => 'https://sandbox.zarinpal.com',
            'connect_timeout_seconds' => 1,
            'timeout_seconds' => 2,
            'verify_attempts' => 2,
        ]);
    }

    private function verifiedResponse(int $code): array
    {
        return [
            'data' => [
                'code' => $code,
                'message' => 'Verified',
                'ref_id' => 445566,
                'card_pan' => '502229******5995',
                'card_hash' => str_repeat('1', 64),
                'fee_type' => 'Merchant',
                'fee' => 0,
            ],
            'errors' => [],
        ];
    }
}
