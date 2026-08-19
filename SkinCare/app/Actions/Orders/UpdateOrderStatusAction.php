<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\Order;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Commerce\OrderReservationService;
use App\Services\Commerce\OrderStateMachine;
use App\Services\Notifications\OrderNotificationOutbox;
use App\Services\Payments\PaymentRefundService;
use Illuminate\Support\Facades\DB;

final class UpdateOrderStatusAction
{
    private const ADMIN_TARGETS = [
        OrderStatus::Processing,
        OrderStatus::Shipped,
        OrderStatus::Delivered,
        OrderStatus::Cancelled,
        OrderStatus::RefundPending,
        OrderStatus::Refunded,
    ];

    public function __construct(
        private readonly OrderStateMachine $stateMachine,
        private readonly OrderReservationService $reservations,
        private readonly OrderNotificationOutbox $notifications,
        private readonly AuditLogger $audit,
        private readonly PaymentRefundService $refunds,
    ) {}

    public function execute(
        Order $order,
        OrderStatus $expectedStatus,
        OrderStatus $targetStatus,
        User $actor,
        ?string $reason = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): Order {
        if (! in_array($targetStatus, self::ADMIN_TARGETS, true)) {
            throw new CheckoutConflictException('این وضعیت از پنل مدیریت قابل تنظیم نیست.');
        }

        if ($targetStatus === OrderStatus::Refunded) {
            return $this->completeRefund($order, $expectedStatus, $actor, $reason, $ipAddress, $userAgent);
        }

        return DB::transaction(function () use (
            $order,
            $expectedStatus,
            $targetStatus,
            $actor,
            $reason,
            $ipAddress,
            $userAgent,
        ): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($from !== $expectedStatus) {
                throw new CheckoutConflictException('وضعیت سفارش تغییر کرده است؛ اطلاعات را تازه‌سازی و دوباره تلاش کنید.');
            }

            if ($targetStatus === OrderStatus::Cancelled) {
                $updated = $this->reservations->cancelAsAdmin($actor, $locked, $reason);
            } else {
                if ($targetStatus === OrderStatus::RefundPending) {
                    $this->markPaymentRefundPending($locked);
                }

                $updated = $this->stateMachine->transition(
                    $locked,
                    $targetStatus,
                    $actor,
                    $reason ?: 'admin_status_update',
                );
            }

            if ($targetStatus === OrderStatus::Shipped) {
                $this->notifications->shipped($updated);
            }
            if ($targetStatus === OrderStatus::RefundPending) {
                $this->notifications->refundPending($updated);
            }

            $this->recordAudit($actor, $updated, $from, $targetStatus, $reason, $ipAddress, $userAgent);

            return $updated->fresh();
        }, attempts: 3);
    }

    private function completeRefund(
        Order $order,
        OrderStatus $expectedStatus,
        User $actor,
        ?string $reason,
        ?string $ipAddress,
        ?string $userAgent,
    ): Order {
        DB::transaction(function () use ($order, $expectedStatus): void {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== $expectedStatus) {
                throw new CheckoutConflictException('وضعیت سفارش تغییر کرده است؛ اطلاعات را تازه‌سازی و دوباره تلاش کنید.');
            }
        }, attempts: 3);

        $updated = $this->refunds->completeRefund($order, $actor, $reason);
        $this->notifications->refunded($updated);
        $this->recordAudit($actor, $updated, $expectedStatus, OrderStatus::Refunded, $reason, $ipAddress, $userAgent);

        return $updated->fresh();
    }

    private function markPaymentRefundPending(Order $order): void
    {
        $payment = $order->payment()->lockForUpdate()->first();
        if (! $payment) {
            throw new CheckoutConflictException('برای تغییر وضعیت بازپرداخت، رکورد پرداخت سفارش لازم است.');
        }

        if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::RefundPending], true)) {
            throw new CheckoutConflictException('پرداخت سفارش در وضعیت قابل بازپرداخت نیست.');
        }

        $payment->status = PaymentStatus::RefundPending;
        $payment->save();
    }

    private function recordAudit(
        User $actor,
        Order $order,
        OrderStatus $from,
        OrderStatus $to,
        ?string $reason,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        $this->audit->record(
            actor: $actor,
            action: 'order.status.updated',
            subject: $order,
            changes: [
                'status' => [
                    'from' => $from->value,
                    'to' => $to->value,
                ],
            ],
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            metadata: [
                'order_number' => $order->order_number,
                'reason' => $reason,
            ],
        );
    }
}
