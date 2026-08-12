<?php

namespace App\Services\Commerce;

use App\Enums\ProductStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\Cart;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\MoneyMath;

final class CheckoutPricingService
{
    public function __construct(private readonly DiscountService $discounts) {}

    public function quote(User $user, string $shippingMethod, ?string $couponCode = null): array
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

        $subtotal = $this->subtotal($lines);
        $discount = $this->discounts->preview($user, $couponCode, $subtotal);

        return $this->totals($lines, $shippingMethod, (int) $discount['discount_irr'], $discount['coupon_code']);
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
        if ($unitPrice < 0 || $unitPrice > (int) config('shop.max_variant_price_irr')) {
            throw new CheckoutConflictException('قیمت یکی از اقلام معتبر نیست.');
        }

        return [
            'variant_id' => $variant->getKey(),
            'product_name' => $product->name,
            'variant_title' => $variant->title,
            'sku' => $variant->sku,
            'quantity' => $quantity,
            'unit_price_irr' => $unitPrice,
            'discount_irr' => 0,
            'line_total_irr' => MoneyMath::multiply($unitPrice, $quantity),
        ];
    }

    public function subtotal(array $lines): int
    {
        return MoneyMath::add(...array_map(
            static fn (array $line): int => (int) $line['line_total_irr'],
            $lines,
        ));
    }

    public function totals(array $lines, string $shippingMethod, int $discount = 0, ?string $couponCode = null): array
    {
        if (! in_array($shippingMethod, ['standard', 'courier'], true)) {
            throw new CheckoutConflictException('روش ارسال معتبر نیست.');
        }

        $subtotal = $this->subtotal($lines);
        if ($discount < 0 || $discount > $subtotal) {
            throw new CheckoutConflictException('مبلغ تخفیف محاسبه‌شده معتبر نیست.');
        }

        // Free-shipping qualification intentionally uses the pre-discount subtotal.
        $shipping = $this->shippingCost($subtotal, $shippingMethod);
        $payableBeforeShipping = $subtotal - $discount;
        $total = MoneyMath::add($payableBeforeShipping, $shipping);

        return [
            'currency' => (string) config('shop.currency'),
            'shipping_method' => $shippingMethod,
            'coupon_code' => $couponCode,
            'items' => array_values($lines),
            'subtotal_irr' => $subtotal,
            'discount_irr' => $discount,
            'shipping_irr' => $shipping,
            'total_irr' => $total,
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
