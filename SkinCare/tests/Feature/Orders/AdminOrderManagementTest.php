<?php

namespace Tests\Feature\Orders;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Services\Commerce\OrderStateMachine;
use Database\Seeders\SystemAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Fakes\FakePaymentGateway;
use Tests\TestCase;

class AdminOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_order_routes_require_authentication_and_orders_permission(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $customer = User::factory()->create(['mobile' => '09121234567']);
        $manager = $this->userWithRole('order-manager');
        $order = $this->order($customer, OrderStatus::Paid);

        $this->getJson('/api/v1/admin/orders')->assertUnauthorized();

        $this->actingAs($customer)
            ->getJson('/api/v1/admin/orders')
            ->assertForbidden();

        $this->actingAs($manager)
            ->getJson('/api/v1/admin/orders')
            ->assertOk()
            ->assertJsonPath('data.0.order_number', $order->order_number)
            ->assertJsonPath('data.0.customer.mobile', '0912***4567');
    }

    public function test_operational_transitions_are_concurrency_guarded_audited_and_financial_states_are_blocked(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $customer = User::factory()->create(['mobile' => '09121234567']);
        $manager = $this->userWithRole('order-manager');
        $order = $this->order($customer, OrderStatus::Paid);
        app(OrderStateMachine::class)->recordInitial($order, $customer);

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'paid',
                'status' => 'processing',
                'reason' => 'ready_for_fulfillment',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'processing');

        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->getKey(),
            'action' => 'order.status.updated',
            'subject_id' => (string) $order->getKey(),
        ]);

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'paid',
                'status' => 'shipped',
            ])
            ->assertConflict();

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'processing',
                'status' => 'paid',
            ])
            ->assertUnprocessable();

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'processing',
                'status' => 'shipped',
                'reason' => 'handed_to_carrier',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'shipped');

        $this->assertDatabaseHas('outbox_messages', [
            'event_key' => 'order:'.$order->getKey().':order_shipped:sms',
            'topic' => 'sms',
        ]);
    }

    public function test_admin_cancellation_releases_reserved_inventory_in_the_same_workflow(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $customer = User::factory()->create();
        $manager = $this->userWithRole('order-manager');
        $order = $this->order($customer, OrderStatus::PendingPayment);
        app(OrderStateMachine::class)->recordInitial($order, $customer);

        $product = Product::factory()->create();
        $variant = ProductVariant::factory()->create([
            'product_id' => $product->getKey(),
            'price_irr' => 500_000,
        ]);
        $inventory = InventoryItem::factory()->create([
            'variant_id' => $variant->getKey(),
            'on_hand' => 10,
            'reserved' => 2,
        ]);
        $order->items()->create([
            'variant_id' => $variant->getKey(),
            'product_name' => $product->name,
            'variant_title' => $variant->title,
            'sku' => $variant->sku,
            'quantity' => 2,
            'unit_price_irr' => 500_000,
            'discount_irr' => 0,
            'line_total_irr' => 1_000_000,
        ]);

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'pending_payment',
                'status' => 'cancelled',
                'reason' => 'manual_fraud_review',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(0, $inventory->fresh()->reserved);
        $this->assertDatabaseHas('inventory_movements', [
            'variant_id' => $variant->getKey(),
            'type' => 'reservation_release',
            'quantity' => -2,
            'actor_user_id' => $manager->getKey(),
            'reference_id' => $order->order_number,
        ]);
    }

    public function test_admin_refund_flow_requires_provider_backed_success_before_financial_state_changes(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $customer = User::factory()->create();
        $manager = $this->userWithRole('order-manager');
        $order = $this->order($customer, OrderStatus::Delivered);
        app(OrderStateMachine::class)->recordInitial($order, $customer);

        Payment::query()->create([
            'order_id' => $order->getKey(),
            'amount_irr' => $order->total_irr,
            'currency' => 'IRR',
            'provider' => 'zarinpal',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'delivered',
                'status' => 'refunded',
                'reason' => 'customer_refund_requested',
            ])
            ->assertConflict();

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'delivered',
                'status' => 'refund_pending',
                'reason' => 'customer_refund_requested',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'refund_pending')
            ->assertJsonPath('data.payment.status', 'refund_pending');

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'refund_pending',
                'status' => 'refunded',
                'reason' => 'refund_completed_externally',
            ])
            ->assertConflict();

        $payment = $order->payment()->firstOrFail();
        PaymentAttempt::query()->create([
            'payment_id' => $payment->getKey(),
            'attempt_number' => 1,
            'public_id' => (string) Str::ulid(),
            'idempotency_key_hash' => hash('sha256', 'refund-attempt-'.$order->getKey()),
            'provider' => 'zarinpal',
            'status' => PaymentAttemptStatus::Succeeded,
            'amount_irr' => $order->total_irr,
            'authority' => 'A'.str_repeat('B', 35),
            'transaction_id' => '123456789',
            'verified_at' => now(),
        ]);

        $failedGateway = new FakePaymentGateway('zarinpal', false);
        $this->app->instance(PaymentGateway::class, $failedGateway);

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'refund_pending',
                'status' => 'refunded',
                'reason' => 'provider_refund_attempt',
            ])
            ->assertConflict();

        $this->assertSame(OrderStatus::RefundPending, $order->fresh()->status);
        $this->assertSame(PaymentStatus::RefundPending, $order->payment()->firstOrFail()->status);
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'zarinpal',
            'event_type' => 'refund_failed',
        ]);

        $unprovenGateway = new FakePaymentGateway('zarinpal', true, false);
        $this->app->instance(PaymentGateway::class, $unprovenGateway);

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'refund_pending',
                'status' => 'refunded',
                'reason' => 'provider_claimed_success_without_proof',
            ])
            ->assertConflict();

        $this->assertSame(OrderStatus::RefundPending, $order->fresh()->status);
        $this->assertSame(PaymentStatus::RefundPending, $order->payment()->firstOrFail()->status);
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'zarinpal',
            'event_type' => 'refund_failed',
            'metadata->failure_code' => 'missing_provider_refund_id',
        ]);

        $fakeGateway = new FakePaymentGateway('zarinpal');
        $this->app->instance(PaymentGateway::class, $fakeGateway);

        $this->actingAs($manager)
            ->patchJson('/api/v1/admin/orders/'.$order->order_number.'/status', [
                'expected_status' => 'refund_pending',
                'status' => 'refunded',
                'reason' => 'provider_refund_confirmed',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.payment.status', 'refunded');

        $this->assertNotNull($order->fresh()->refunded_at);
        $this->assertNotNull($order->payment()->firstOrFail()->refunded_at);
        $this->assertCount(1, $fakeGateway->refunds);
        $this->assertDatabaseHas('payment_events', [
            'provider' => 'zarinpal',
            'event_type' => 'refund_succeeded',
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'event_key' => 'order:'.$order->getKey().':refund_pending:sms',
            'topic' => 'sms',
        ]);
        $this->assertDatabaseHas('outbox_messages', [
            'event_key' => 'order:'.$order->getKey().':refund_completed:sms',
            'topic' => 'sms',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_user_id' => $manager->getKey(),
            'action' => 'order.status.updated',
            'subject_id' => (string) $order->getKey(),
        ]);
    }

    public function test_order_detail_timeline_does_not_expose_raw_payment_metadata(): void
    {
        $this->seed(SystemAccessSeeder::class);
        $customer = User::factory()->create(['mobile' => '09121234567']);
        $manager = $this->userWithRole('order-manager');
        $order = $this->order($customer, OrderStatus::Paid);
        app(OrderStateMachine::class)->recordInitial($order, $customer);

        $payment = Payment::query()->create([
            'order_id' => $order->getKey(),
            'amount_irr' => $order->total_irr,
            'currency' => 'IRR',
            'provider' => 'zarinpal',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        $attempt = PaymentAttempt::query()->create([
            'payment_id' => $payment->getKey(),
            'attempt_number' => 1,
            'public_id' => (string) Str::ulid(),
            'idempotency_key_hash' => hash('sha256', 'attempt-'.$order->getKey()),
            'provider' => 'zarinpal',
            'status' => PaymentAttemptStatus::Succeeded,
            'amount_irr' => $order->total_irr,
            'authority' => 'A'.str_repeat('B', 35),
            'transaction_id' => '123456789',
            'verified_at' => now(),
            'metadata' => ['secret_provider_field' => 'DO-NOT-EXPOSE'],
        ]);
        PaymentEvent::query()->create([
            'payment_id' => $payment->getKey(),
            'attempt_id' => $attempt->getKey(),
            'provider' => 'zarinpal',
            'event_type' => 'verified_success',
            'dedupe_key' => hash('sha256', 'event-'.$order->getKey()),
            'payload_hash' => hash('sha256', 'payload-'.$order->getKey()),
            'occurred_at' => now(),
            'metadata' => [
                'card_hash' => 'CARD-HASH-MUST-NOT-LEAK',
                'card_pan' => '502229******5995',
            ],
        ]);
        OutboxMessage::query()->create([
            'topic' => 'sms',
            'event_key' => 'order:'.$order->getKey().':payment_succeeded:sms',
            'aggregate_type' => 'order',
            'aggregate_id' => (string) $order->getKey(),
            'payload' => ['template' => 'payment_succeeded', 'internal' => 'DO-NOT-EXPOSE-EITHER'],
            'available_at' => now(),
        ]);

        $response = $this->actingAs($manager)
            ->getJson('/api/v1/admin/orders/'.$order->order_number)
            ->assertOk()
            ->assertJsonPath('data.payment.attempts.0.transaction_id', '123456789')
            ->assertJsonFragment(['kind' => 'payment', 'event_type' => 'verified_success'])
            ->assertJsonFragment(['kind' => 'notification', 'template' => 'payment_succeeded']);

        $body = $response->getContent();
        $this->assertStringNotContainsString('CARD-HASH-MUST-NOT-LEAK', $body);
        $this->assertStringNotContainsString('DO-NOT-EXPOSE', $body);
        $this->assertStringNotContainsString('502229******5995', $body);
    }

    private function userWithRole(string $slug): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('slug', $slug)->firstOrFail());

        return $user;
    }

    private function order(User $user, OrderStatus $status): Order
    {
        $id = (string) Str::ulid();

        return Order::query()->create([
            'user_id' => $user->getKey(),
            'order_number' => 'SHR-'.$id,
            'idempotency_key' => hash('sha256', 'order-'.$id),
            'idempotency_fingerprint' => hash('sha256', 'fingerprint-'.$id),
            'status' => $status,
            'shipping_method' => 'standard',
            'address_snapshot' => [
                'recipient_name' => 'Test Customer',
                'mobile' => '09121234567',
                'province' => 'Tehran',
                'city' => 'Tehran',
                'postal_code' => '1234567890',
                'address_line' => 'Test address',
            ],
            'subtotal_irr' => 1_000_000,
            'discount_irr' => 0,
            'shipping_irr' => 0,
            'total_irr' => 1_000_000,
            'reservation_expires_at' => $status === OrderStatus::PendingPayment ? now()->addMinutes(15) : null,
            'paid_at' => in_array($status, [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered], true) ? now() : null,
        ]);
    }
}
