<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commerce\CheckoutPricingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CreateOrderAction
{
    public function __construct(private readonly CheckoutPricingService $pricing) {}

    public function execute(User $user, int $addressId, string $shippingMethod, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $addressId, $shippingMethod, $idempotencyKey): array {
            $user = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $existing = Order::query()
                ->where('user_id', $user->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return [$existing->load('items'), false];
            }

            $address = Address::query()
                ->where('user_id', $user->getKey())
                ->whereKey($addressId)
                ->lockForUpdate()
                ->firstOrFail();

            $cart = Cart::query()->where('user_id', $user->getKey())->lockForUpdate()->first();
            if (! $cart) {
                throw new CheckoutConflictException('سبد خرید خالی است.');
            }

            $cartItems = CartItem::query()
                ->where('cart_id', $cart->getKey())
                ->orderBy('variant_id')
                ->lockForUpdate()
                ->get();

            if ($cartItems->isEmpty()) {
                throw new CheckoutConflictException('سبد خرید خالی است.');
            }

            $variantIds = $cartItems->pluck('variant_id')->all();
            $variants = ProductVariant::query()
                ->with('product')
                ->whereIn('id', $variantIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $inventories = InventoryItem::query()
                ->whereIn('variant_id', $variantIds)
                ->orderBy('variant_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('variant_id');

            $lines = [];
            foreach ($cartItems as $cartItem) {
                $variant = $variants->get($cartItem->variant_id);
                $inventory = $inventories->get($cartItem->variant_id);

                if (! $variant || ! $inventory) {
                    throw new CheckoutConflictException('یکی از اقلام سبد خرید دیگر در دسترس نیست.');
                }

                $line = $this->pricing->line($variant, $cartItem->quantity);
                if ($inventory->available < $cartItem->quantity) {
                    throw new CheckoutConflictException("موجودی «{$variant->product->name}» کافی نیست.");
                }

                $lines[] = $line;
            }

            $quote = $this->pricing->totals($lines, $shippingMethod);
            $expiresAt = now()->addMinutes((int) config('shop.order_reservation_ttl_minutes'));

            $order = Order::query()->create([
                'user_id' => $user->getKey(),
                'address_id' => $address->getKey(),
                'order_number' => $this->orderNumber(),
                'idempotency_key' => $idempotencyKey,
                'status' => OrderStatus::PendingPayment,
                'shipping_method' => $shippingMethod,
                'address_snapshot' => $address->snapshot(),
                'subtotal_irr' => $quote['subtotal_irr'],
                'discount_irr' => $quote['discount_irr'],
                'shipping_irr' => $quote['shipping_irr'],
                'total_irr' => $quote['total_irr'],
                'reservation_expires_at' => $expiresAt,
            ]);

            foreach ($lines as $line) {
                $order->items()->create($line);

                $inventory = $inventories->get($line['variant_id']);
                $inventory->reserved += $line['quantity'];
                $inventory->save();

                InventoryMovement::query()->create([
                    'variant_id' => $line['variant_id'],
                    'type' => 'reservation_hold',
                    'quantity' => $line['quantity'],
                    'reason' => 'order_pending_payment',
                    'actor_user_id' => $user->getKey(),
                    'reference_type' => 'order',
                    'reference_id' => $order->order_number,
                    'metadata' => ['bucket' => 'reserved'],
                ]);
            }

            CartItem::query()->where('cart_id', $cart->getKey())->delete();

            return [$order->load('items'), true];
        }, attempts: 3);
    }

    private function orderNumber(): string
    {
        return 'SHR-'.Str::upper((string) Str::ulid());
    }
}
