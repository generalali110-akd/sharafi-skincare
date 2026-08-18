<?php

namespace Tests\Feature\Commerce;

use App\Enums\OrderStatus;
use App\Models\Address;
use App\Models\DiscountRule;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommerceFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_and_order_use_server_price_and_idempotent_retry_does_not_double_reserve(): void
    {
        $user = User::factory()->create();
        [$variant, $inventory] = $this->purchasableVariant(price: 1_000_000, stock: 5);
        $address = $this->addressFor($user);

        $this->actingAs($user)
            ->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 2])
            ->assertOk();

        $this->actingAs($user)
            ->postJson('/api/v1/checkout/quote', ['shipping_method' => 'standard'])
            ->assertOk()
            ->assertJsonPath('data.subtotal_irr', 2_000_000)
            ->assertJsonPath('data.shipping_irr', 450_000)
            ->assertJsonPath('data.total_irr', 2_450_000);

        $key = 'order-retry-key-0001';
        $first = $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', [
                'address_id' => $address->id,
                'shipping_method' => 'standard',
            ])
            ->assertCreated()
            ->assertJsonPath('data.subtotal_irr', 2_000_000)
            ->assertJsonPath('data.total_irr', 2_450_000);

        $orderNumber = $first->json('data.order_number');

        $inventory->refresh();
        $this->assertSame(5, $inventory->on_hand);
        $this->assertSame(2, $inventory->reserved);
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseHas('order_items', [
            'product_name' => $variant->product->name,
            'sku' => $variant->sku,
            'quantity' => 2,
            'unit_price_irr' => 1_000_000,
            'line_total_irr' => 2_000_000,
        ]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', [
                'address_id' => $address->id,
                'shipping_method' => 'standard',
            ])
            ->assertOk()
            ->assertJsonPath('data.order_number', $orderNumber);

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(2, $inventory->refresh()->reserved);
        $this->assertSame(1, InventoryMovement::query()->where('type', 'reservation_hold')->count());
    }

    public function test_client_cannot_submit_financial_totals(): void
    {
        $user = User::factory()->create();
        [$variant] = $this->purchasableVariant(price: 5_000_000, stock: 5);
        $address = $this->addressFor($user);

        $this->actingAs($user)
            ->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])
            ->assertOk();

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'tamper-check-key-0001')
            ->postJson('/api/v1/orders', [
                'address_id' => $address->id,
                'shipping_method' => 'standard',
                'total_irr' => 1,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_order_creation_fails_if_stock_changed_after_item_was_added_to_cart(): void
    {
        $user = User::factory()->create();
        [$variant, $inventory] = $this->purchasableVariant(price: 2_000_000, stock: 3);
        $address = $this->addressFor($user);

        $this->actingAs($user)
            ->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 3])
            ->assertOk();

        $inventory->update(['on_hand' => 2]);

        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'stock-change-key-0001')
            ->postJson('/api/v1/orders', [
                'address_id' => $address->id,
                'shipping_method' => 'standard',
            ])
            ->assertConflict();

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $inventory->refresh()->reserved);
    }

    public function test_cancel_releases_reservation_once_and_second_cancel_conflicts(): void
    {
        $user = User::factory()->create();
        [$variant, $inventory] = $this->purchasableVariant(price: 2_000_000, stock: 4);
        $address = $this->addressFor($user);

        $this->actingAs($user)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 2])->assertOk();
        $order = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'cancel-order-key-0001')
            ->postJson('/api/v1/orders', ['address_id' => $address->id, 'shipping_method' => 'standard'])
            ->assertCreated();

        $orderNumber = $order->json('data.order_number');
        $storedOrder = Order::query()->where('order_number', $orderNumber)->firstOrFail();
        $this->assertSame(2, $inventory->refresh()->reserved);

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$orderNumber}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertSame(0, $inventory->refresh()->reserved);
        $this->assertSame(1, InventoryMovement::query()->where('type', 'reservation_release')->count());
        $this->assertDatabaseHas('outbox_messages', [
            'event_key' => 'order:'.$storedOrder->getKey().':order_cancelled:sms',
            'topic' => 'sms',
        ]);

        $this->actingAs($user)
            ->postJson("/api/v1/orders/{$orderNumber}/cancel")
            ->assertConflict();

        $this->assertSame(0, $inventory->refresh()->reserved);
        $this->assertSame(1, InventoryMovement::query()->where('type', 'reservation_release')->count());
    }

    public function test_business_policy_uses_pre_discount_subtotal_for_free_shipping_and_sets_reservation_ttl(): void
    {
        $user = User::factory()->create();
        [$variant] = $this->purchasableVariant(price: 8_000_000, stock: 2);
        $address = $this->addressFor($user);
        $this->discountRule('BIGSAVE', 7_900_000);

        $this->actingAs($user)
            ->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])
            ->assertOk();

        $this->actingAs($user)
            ->postJson('/api/v1/checkout/quote', [
                'shipping_method' => 'standard',
                'coupon_code' => 'BIGSAVE',
            ])
            ->assertOk()
            ->assertJsonPath('data.subtotal_irr', 8_000_000)
            ->assertJsonPath('data.discount_irr', 7_900_000)
            ->assertJsonPath('data.shipping_irr', 0)
            ->assertJsonPath('data.total_irr', 100_000);

        $this->actingAs($user)
            ->postJson('/api/v1/checkout/quote', [
                'shipping_method' => 'courier',
                'coupon_code' => 'BIGSAVE',
            ])
            ->assertOk()
            ->assertJsonPath('data.shipping_irr', 650_000)
            ->assertJsonPath('data.total_irr', 750_000);

        $before = now()->addMinutes((int) config('shop.order_reservation_ttl_minutes'))->subSeconds(5);
        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'policy-order-key-0001')
            ->postJson('/api/v1/orders', [
                'address_id' => $address->id,
                'shipping_method' => 'standard',
                'coupon_code' => 'BIGSAVE',
            ])
            ->assertCreated();
        $after = now()->addMinutes((int) config('shop.order_reservation_ttl_minutes'))->addSeconds(5);

        $order = Order::query()->where('order_number', $response->json('data.order_number'))->firstOrFail();
        $this->assertTrue($order->reservation_expires_at->betweenIncluded($before, $after));
    }

    public function test_cart_quantity_cannot_exceed_shop_policy_limit(): void
    {
        $user = User::factory()->create();
        [$variant] = $this->purchasableVariant(price: 1_000_000, stock: 200);

        $this->actingAs($user)
            ->putJson("/api/v1/cart/items/{$variant->id}", [
                'quantity' => (int) config('shop.max_item_quantity') + 1,
            ])
            ->assertUnprocessable();
    }

    public function test_expiry_command_releases_stale_reservation(): void
    {
        $user = User::factory()->create();
        [$variant, $inventory] = $this->purchasableVariant(price: 2_000_000, stock: 4);
        $address = $this->addressFor($user);

        $this->actingAs($user)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])->assertOk();
        $response = $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'expire-order-key-0001')
            ->postJson('/api/v1/orders', ['address_id' => $address->id, 'shipping_method' => 'standard'])
            ->assertCreated();

        $order = Order::query()->where('order_number', $response->json('data.order_number'))->firstOrFail();
        $order->update(['reservation_expires_at' => now()->subMinute()]);

        $this->artisan('orders:expire-reservations')->assertSuccessful();

        $this->assertSame(OrderStatus::Expired, $order->refresh()->status);
        $this->assertSame(0, $inventory->refresh()->reserved);
        $this->assertSame(1, InventoryMovement::query()->where('type', 'reservation_release')->count());
    }

    public function test_user_cannot_use_or_modify_another_users_address(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $address = $this->addressFor($owner);
        [$variant] = $this->purchasableVariant(price: 2_000_000, stock: 2);

        $this->actingAs($attacker)
            ->patchJson("/api/v1/addresses/{$address->id}", ['city' => 'تهران'])
            ->assertNotFound();

        $this->actingAs($attacker)
            ->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])
            ->assertOk();

        $this->actingAs($attacker)
            ->withHeader('Idempotency-Key', 'foreign-address-0001')
            ->postJson('/api/v1/orders', [
                'address_id' => $address->id,
                'shipping_method' => 'standard',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_only_one_default_address_is_kept_per_user(): void
    {
        $user = User::factory()->create();

        $payload = [
            'recipient_name' => 'کاربر تست',
            'mobile' => '09121234567',
            'province' => 'تهران',
            'city' => 'تهران',
            'postal_code' => '1234567890',
            'address_line' => 'آدرس تست',
            'is_default' => true,
        ];

        $this->actingAs($user)->postJson('/api/v1/addresses', $payload)->assertCreated();
        $this->actingAs($user)->postJson('/api/v1/addresses', [
            ...$payload,
            'address_line' => 'آدرس دوم',
        ])->assertCreated();

        $this->assertSame(2, Address::query()->where('user_id', $user->id)->count());
        $this->assertSame(1, Address::query()->where('user_id', $user->id)->where('is_default', true)->count());
    }

    public function test_address_accepts_and_stores_normalized_persian_digits(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/addresses', [
            'recipient_name' => 'کاربر تست',
            'mobile' => "\u{06F0}\u{06F9}\u{06F1}\u{06F2}\u{06F1}\u{06F2}\u{06F3}\u{06F4}\u{06F5}\u{06F6}\u{06F7}",
            'province' => 'تهران',
            'city' => 'تهران',
            'postal_code' => "\u{06F1}\u{06F2}\u{06F3}\u{06F4}\u{06F5}\u{06F6}\u{06F7}\u{06F8}\u{06F9}\u{06F0}",
            'address_line' => 'آدرس تست',
        ])->assertCreated()
            ->assertJsonPath('data.mobile', '09121234567')
            ->assertJsonPath('data.postal_code', '1234567890');

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'mobile' => '09121234567',
            'postal_code' => '1234567890',
        ]);
    }

    public function test_order_list_does_not_expose_idempotency_key(): void
    {
        $user = User::factory()->create();
        [$variant] = $this->purchasableVariant(price: 2_000_000, stock: 2);
        $address = $this->addressFor($user);

        $this->actingAs($user)->putJson("/api/v1/cart/items/{$variant->id}", ['quantity' => 1])->assertOk();
        $this->actingAs($user)
            ->withHeader('Idempotency-Key', 'private-key-check-0001')
            ->postJson('/api/v1/orders', ['address_id' => $address->id, 'shipping_method' => 'standard'])
            ->assertCreated();

        $response = $this->actingAs($user)->getJson('/api/v1/orders')->assertOk();
        $this->assertArrayNotHasKey('idempotency_key', $response->json('data.0'));
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

    private function discountRule(string $code, int $amount): DiscountRule
    {
        return DiscountRule::query()->create([
            'code' => $code,
            'name' => $code,
            'kind' => 'fixed',
            'value' => $amount,
            'min_subtotal_irr' => 0,
            'is_active' => true,
        ]);
    }
}
