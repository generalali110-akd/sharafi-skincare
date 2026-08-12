<?php

namespace App\Services\Commerce;

use App\Enums\OrderStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;

final class OrderStateMachine
{
    private const TRANSITIONS = [
        'pending_payment' => ['paid', 'cancelled', 'expired'],
        'paid' => ['processing', 'refund_pending'],
        'processing' => ['shipped', 'refund_pending'],
        'shipped' => ['delivered', 'refund_pending'],
        'delivered' => ['refund_pending'],
        'cancelled' => ['refund_pending'],
        'expired' => ['refund_pending'],
        'refund_pending' => ['refunded'],
        'refunded' => [],
    ];

    public function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to->value, self::TRANSITIONS[$from->value] ?? [], true);
    }

    public function transition(
        Order $order,
        OrderStatus $to,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = [],
    ): Order {
        $from = $order->status;
        if (! $from instanceof OrderStatus || ! $this->canTransition($from, $to)) {
            throw new CheckoutConflictException('تغییر وضعیت سفارش در این مرحله مجاز نیست.');
        }

        $now = now();
        $order->status = $to;

        if (in_array($to, [OrderStatus::Paid, OrderStatus::Cancelled, OrderStatus::Expired], true)) {
            $order->reservation_expires_at = null;
        }
        if ($to === OrderStatus::Paid && $order->paid_at === null) {
            $order->paid_at = $now;
        }
        if ($to === OrderStatus::Cancelled && $order->cancelled_at === null) {
            $order->cancelled_at = $now;
        }
        if ($to === OrderStatus::Processing && $order->processing_at === null) {
            $order->processing_at = $now;
        }
        if ($to === OrderStatus::Shipped && $order->shipped_at === null) {
            $order->shipped_at = $now;
        }
        if ($to === OrderStatus::Delivered && $order->delivered_at === null) {
            $order->delivered_at = $now;
        }
        if ($to === OrderStatus::RefundPending && $order->refund_pending_at === null) {
            $order->refund_pending_at = $now;
        }
        if ($to === OrderStatus::Refunded && $order->refunded_at === null) {
            $order->refunded_at = $now;
        }

        $order->save();

        OrderStatusTransition::query()->create([
            'order_id' => $order->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => $actor?->getKey(),
            'reason' => $reason,
            'metadata' => $metadata,
        ]);

        return $order;
    }

    public function recordInitial(Order $order, ?User $actor = null): void
    {
        OrderStatusTransition::query()->create([
            'order_id' => $order->getKey(),
            'from_status' => null,
            'to_status' => $order->status,
            'actor_user_id' => $actor?->getKey(),
            'reason' => 'order_created',
            'metadata' => [],
        ]);
    }
}
