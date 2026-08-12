<?php

namespace App\Services\Commerce;

use App\Enums\ProductStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\Cart;
use App\Models\ProductVariant;
use App\Models\User;

final class CheckoutPricingService
{
    public function quote(User $user, string $shippingMethod): array
    {
        $cart = Cart::query()
            ->where('user_id', $user->getKey())
            ->with([
                'items' => fn ($query) => $query->orderBy('variant_id'),
                'items.variant.product',
                'items.variant.inventory',
            ])
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            throw new CheckoutConflictException('سبد خرید خالی است.');
        }

        $lines = [];
        foreach ($cart->items as $item) {
            $variant = $item->variant;
            $line = $this->line($variant, $item->quantity);

            if (! $variant->inventory || $variant->inventory->available < $item->quantity) {
                throw new CheckoutConflictException("موجودی «{$variant->product->name}» کافی نیست.");
            }

            $lines[] = $line;
        }

        return $this->totals($lines, $shippingMethod);
    }

    public function line(ProductVariant $variant, int $quantity): array
    {
        $product = $variant->product;
        $published = $product->status === ProductStatus::Active
            && $product->published_at !== null
            && $product->published_at->lte(now());

        if (! $variant->is_active || ! $published) {
            throw new CheckoutConflictException("«{$product->name}» در حال حاضر قابل خرید نیست.");
        }

        if ($quantity < 1 || $quantity > (int) config('shop.max_item_quantity')) {
            throw new CheckoutConflictException('تعداد یکی از اقلام سبد خرید معتبر نیست.');
        }

        $unitPrice = (int) $variant->price_irr;

        return [
            'variant_id' => $variant->getKey(),
            'product_name' => $product->name,
            'variant_title' => $variant->title,
            'sku' => $variant->sku,
            'quantity' => $quantity,
            'unit_price_irr' => $unitPrice,
            'discount_irr' => 0,
            'line_total_irr' => $unitPrice * $quantity,
        ];
    }

    public function totals(array $lines, string $shippingMethod): array
    {
        if (! in_array($shippingMethod, ['standard', 'courier'], true)) {
            throw new CheckoutConflictException('روش ارسال معتبر نیست.');
        }

        $subtotal = array_sum(array_column($lines, 'line_total_irr'));
        $discount = 0;
        $shipping = $this->shippingCost($subtotal, $shippingMethod);

        return [
            'currency' => (string) config('shop.currency'),
            'shipping_method' => $shippingMethod,
            'items' => array_values($lines),
            'subtotal_irr' => $subtotal,
            'discount_irr' => $discount,
            'shipping_irr' => $shipping,
            'total_irr' => $subtotal - $discount + $shipping,
        ];
    }

    private function shippingCost(int $subtotal, string $shippingMethod): int
    {
        if ($shippingMethod === 'courier') {
            return (int) config('shop.courier_shipping_irr');
        }

        return $subtotal >= (int) config('shop.free_shipping_threshold_irr')
            ? 0
            : (int) config('shop.standard_shipping_irr');
    }
}
