<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Commerce\OrderReservationService;
use App\Services\Commerce\OrderStateMachine;
use App\Services\Notifications\OrderNotificationOutbox;
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
                if (in_array($targetStatus, [OrderStatus::RefundPending, OrderStatus::Refunded], true)) {
                    $this->syncRefundPaymentState($locked, $targetStatus);
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
            if ($targetStatus === OrderStatus::Refunded) {
                $this->notifications->refunded($updated);
            }

            $this->audit->record(
                actor: $actor,
                action: 'order.status.updated',
                subject: $updated,
                changes: [
                    'status' => [
                        'from' => $from->value,
                        'to' => $targetStatus->value,
                    ],
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
                metadata: [
                    'order_number' => $updated->order_number,
                    'reason' => $reason,
                ],
            );

            return $updated->fresh();
        }, attempts: 3);
    }

    private function syncRefundPaymentState(Order $order, OrderStatus $targetStatus): void
    {
        $payment = $order->payment()->lockForUpdate()->first();
        if (! $payment) {
            throw new CheckoutConflictException('برای تغییر وضعیت بازپرداخت، رکورد پرداخت سفارش لازم است.');
        }

        if ($targetStatus === OrderStatus::RefundPending) {
            if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::RefundPending], true)) {
                throw new CheckoutConflictException('پرداخت سفارش در وضعیت قابل بازپرداخت نیست.');
            }

            $payment->status = PaymentStatus::RefundPending;
            $payment->save();

            return;
        }

        $this->assertRefundCanBeCompleted($order, $payment);

        $payment->status = PaymentStatus::Refunded;
        $payment->refunded_at ??= now();
        $payment->save();
    }

    private function assertRefundCanBeCompleted(Order $order, Payment $payment): void
    {
        if ($order->status !== OrderStatus::RefundPending || $payment->status !== PaymentStatus::RefundPending) {
            throw new CheckoutConflictException('تکمیل بازپرداخت فقط بعد از وضعیت refund_pending مجاز است.');
        }
    }
}
