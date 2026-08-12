<?php

namespace App\Services\Commerce;

use App\Enums\DiscountKind;
use App\Enums\DiscountRedemptionStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\DiscountRedemption;
use App\Models\DiscountRule;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Str;

final class DiscountService
{
    public function normalizeCode(?string $code): ?string
    {
        $normalized = $code === null ? '' : Str::upper(trim($code));

        return $normalized === '' ? null : $normalized;
    }

    public function preview(User $user, ?string $code, int $subtotal): array
    {
        $normalized = $this->normalizeCode($code);
        if ($normalized === null) {
            return $this->emptyResult();
        }

        $rule = DiscountRule::query()->where('code', $normalized)->first();
        if (! $rule) {
            throw new CheckoutConflictException('کد تخفیف معتبر یا قابل استفاده نیست.');
        }

        return $this->evaluate($rule, $user, $subtotal);
    }

    public function lockForOrder(User $user, ?string $code, int $subtotal): array
    {
        $normalized = $this->normalizeCode($code);
        if ($normalized === null) {
            return $this->emptyResult();
        }

        $rule = DiscountRule::query()->where('code', $normalized)->lockForUpdate()->first();
        if (! $rule) {
            throw new CheckoutConflictException('کد تخفیف معتبر یا قابل استفاده نیست.');
        }

        return $this->evaluate($rule, $user, $subtotal);
    }

    public function reserve(Order $order, User $user, array $discount): ?DiscountRedemption
    {
        $rule = $discount['rule'] ?? null;
        if (! $rule instanceof DiscountRule || (int) $discount['discount_irr'] <= 0) {
            return null;
        }

        return DiscountRedemption::query()->create([
            'discount_rule_id' => $rule->getKey(),
            'user_id' => $user->getKey(),
            'order_id' => $order->getKey(),
            'status' => DiscountRedemptionStatus::Reserved,
            'discount_irr' => (int) $discount['discount_irr'],
            'reserved_at' => now(),
        ]);
    }

    public function releaseForOrder(Order $order): void
    {
        $redemption = DiscountRedemption::query()
            ->where('order_id', $order->getKey())
            ->lockForUpdate()
            ->first();

        if (! $redemption || $redemption->status !== DiscountRedemptionStatus::Reserved) {
            return;
        }

        $redemption->status = DiscountRedemptionStatus::Released;
        $redemption->released_at = now();
        $redemption->save();
    }

    public function consumeForOrder(Order $order): void
    {
        $redemption = DiscountRedemption::query()
            ->where('order_id', $order->getKey())
            ->lockForUpdate()
            ->first();

        if (! $redemption) {
            return;
        }
        if ($redemption->status === DiscountRedemptionStatus::Consumed) {
            return;
        }
        if ($redemption->status !== DiscountRedemptionStatus::Reserved) {
            throw new CheckoutConflictException('رزرو تخفیف سفارش دیگر معتبر نیست.');
        }

        $redemption->status = DiscountRedemptionStatus::Consumed;
        $redemption->consumed_at = now();
        $redemption->save();
    }

    private function evaluate(DiscountRule $rule, User $user, int $subtotal): array
    {
        $now = now();
        if (! $rule->is_active
            || ($rule->starts_at !== null && $rule->starts_at->isFuture())
            || ($rule->ends_at !== null && $rule->ends_at->lte($now))
            || $subtotal < $rule->min_subtotal_irr) {
            throw new CheckoutConflictException('کد تخفیف در حال حاضر قابل استفاده نیست.');
        }

        $activeStatuses = [
            DiscountRedemptionStatus::Reserved->value,
            DiscountRedemptionStatus::Consumed->value,
        ];

        if ($rule->usage_limit_total !== null) {
            $used = DiscountRedemption::query()
                ->where('discount_rule_id', $rule->getKey())
                ->whereIn('status', $activeStatuses)
                ->count();
            if ($used >= $rule->usage_limit_total) {
                throw new CheckoutConflictException('ظرفیت استفاده از این کد تخفیف تکمیل شده است.');
            }
        }

        if ($rule->usage_limit_per_user !== null) {
            $usedByUser = DiscountRedemption::query()
                ->where('discount_rule_id', $rule->getKey())
                ->where('user_id', $user->getKey())
                ->whereIn('status', $activeStatuses)
                ->count();
            if ($usedByUser >= $rule->usage_limit_per_user) {
                throw new CheckoutConflictException('سقف استفاده شما از این کد تخفیف تکمیل شده است.');
            }
        }

        $amount = match ($rule->kind) {
            DiscountKind::Fixed => min($subtotal, $rule->value),
            DiscountKind::Percentage => $this->percentageAmount($subtotal, $rule->value),
        };

        if ($rule->max_discount_irr !== null) {
            $amount = min($amount, $rule->max_discount_irr);
        }
        $amount = max(0, min($amount, $subtotal));

        return [
            'rule' => $rule,
            'coupon_code' => $rule->code,
            'discount_irr' => $amount,
        ];
    }

    private function percentageAmount(int $subtotal, int $basisPoints): int
    {
        $whole = intdiv($subtotal, 10_000) * $basisPoints;
        $remainder = intdiv(($subtotal % 10_000) * $basisPoints, 10_000);

        return $whole + $remainder;
    }

    private function emptyResult(): array
    {
        return ['rule' => null, 'coupon_code' => null, 'discount_irr' => 0];
    }
}
