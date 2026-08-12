<?php

namespace App\Services\Commerce;

use App\Enums\ProductStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CartService
{
    public function get(User $user): Cart
    {
        return Cart::query()->firstOrCreate(['user_id' => $user->getKey()])->load([
            'items.variant.product:id,name,slug,status,published_at',
            'items.variant.inventory',
        ]);
    }

    public function setQuantity(User $user, ProductVariant $variant, int $quantity): Cart
    {
        if ($quantity < 1 || $quantity > (int) config('shop.max_item_quantity')) {
            throw new CheckoutConflictException('تعداد انتخاب‌شده معتبر نیست.');
        }

        return DB::transaction(function () use ($user, $variant, $quantity): Cart {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $cart = Cart::query()->firstOrCreate(['user_id' => $user->getKey()]);
            $cart = Cart::query()->whereKey($cart->getKey())->lockForUpdate()->firstOrFail();

            $variant = ProductVariant::query()
                ->with(['product', 'inventory'])
                ->whereKey($variant->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $published = $variant->product->status === ProductStatus::Active
                && $variant->product->published_at !== null
                && $variant->product->published_at->lte(now());

            if (! $variant->is_active || ! $published) {
                throw new CheckoutConflictException('این محصول در حال حاضر قابل خرید نیست.');
            }

            if (! $variant->inventory || $variant->inventory->available < $quantity) {
                throw new CheckoutConflictException('موجودی کافی برای تعداد انتخاب‌شده وجود ندارد.');
            }

            CartItem::query()->updateOrCreate(
                ['cart_id' => $cart->getKey(), 'variant_id' => $variant->getKey()],
                ['quantity' => $quantity],
            );

            return $this->get($user);
        });
    }

    public function remove(User $user, ProductVariant $variant): Cart
    {
        return DB::transaction(function () use ($user, $variant): Cart {
            $cart = Cart::query()->where('user_id', $user->getKey())->lockForUpdate()->first();

            if ($cart) {
                CartItem::query()
                    ->where('cart_id', $cart->getKey())
                    ->where('variant_id', $variant->getKey())
                    ->delete();
            }

            return $this->get($user);
        });
    }
}
