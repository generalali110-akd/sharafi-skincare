<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Contracts\ReversiblePaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\Commerce\OrderStateMachine;
use Illuminate\Support\Facades\DB;

final class PaymentRefundService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderStateMachine $stateMachine,
    ) {}

    public function completeRefund(Order $order, User $actor, ?string $reason = null): Order
    {
        if (! $this->gateway instanceof ReversiblePaymentGateway) {
            throw new CheckoutConflictException('درگاه پرداخت فعال از بازپرداخت خودکار پشتیبانی نمی‌کند.');
        }

        $attempt = $this->refundableAttempt($order);
        $result = $this->gateway->reverse($attempt);

        if (! $result->successful) {
            $this->recordFailedRefund($attempt, $result->failureCode, $result->failureMessage, $result->metadata);

            throw new CheckoutConflictException($result->failureMessage ?: 'بازپرداخت در درگاه پرداخت تأیید نشد.');
        }

        return DB::transaction(function () use ($order, $attempt, $actor, $reason, $result): Order {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $payment = $lockedOrder->payment()->lockForUpdate()->firstOrFail();
            $lockedAttempt = PaymentAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== OrderStatus::RefundPending || $payment->status !== PaymentStatus::RefundPending) {
                throw new CheckoutConflictException('تکمیل بازپرداخت فقط از وضعیت refund_pending مجاز است.');
            }
            if ($lockedAttempt->status !== PaymentAttemptStatus::Succeeded || $lockedAttempt->payment_id !== $payment->getKey()) {
                throw new CheckoutConflictException('تلاش پرداخت موفق برای بازپرداخت این سفارش معتبر نیست.');
            }

            $payment->status = PaymentStatus::Refunded;
            $payment->refunded_at ??= now();
            $payment->save();

            $this->recordRefundEvent($lockedAttempt, 'refund_succeeded', $result->metadata);

            return $this->stateMachine->transition(
                $lockedOrder,
                OrderStatus::Refunded,
                $actor,
                $reason ?: 'provider_refund_completed',
            );
        }, attempts: 3);
    }

    private function refundableAttempt(Order $order): PaymentAttempt
    {
        return DB::transaction(function () use ($order): PaymentAttempt {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            $payment = $lockedOrder->payment()->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== OrderStatus::RefundPending || $payment->status !== PaymentStatus::RefundPending) {
                throw new CheckoutConflictException('سفارش برای تکمیل بازپرداخت آماده نیست.');
            }
            if ($payment->provider !== $this->gateway->name()) {
                throw new CheckoutConflictException('درگاه پرداخت فعال با پرداخت سفارش سازگار نیست.');
            }

            $attempt = $payment->attempts()
                ->where('provider', $payment->provider)
                ->where('status', PaymentAttemptStatus::Succeeded)
                ->whereNotNull('transaction_id')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (! $attempt) {
                throw new CheckoutConflictException('تلاش پرداخت موفق برای بازپرداخت این سفارش ثبت نشده است.');
            }

            return $attempt;
        }, attempts: 3);
    }

    private function recordFailedRefund(PaymentAttempt $attempt, ?string $failureCode, ?string $failureMessage, array $metadata): void
    {
        DB::transaction(function () use ($attempt, $failureCode, $failureMessage, $metadata): void {
            $attempt = PaymentAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();
            $this->recordRefundEvent($attempt, 'refund_failed', [
                'failure_code' => $failureCode,
                'failure_message' => mb_substr($failureMessage ?: 'Payment refund failed.', 0, 500),
                ...$metadata,
            ]);
        }, attempts: 3);
    }

    private function recordRefundEvent(PaymentAttempt $attempt, string $eventType, array $metadata): void
    {
        PaymentEvent::query()->firstOrCreate(
            [
                'dedupe_key' => hash('sha256', $eventType.'|'.$attempt->provider.'|'.$attempt->transaction_id),
            ],
            [
                'payment_id' => $attempt->payment_id,
                'attempt_id' => $attempt->getKey(),
                'provider' => $attempt->provider,
                'event_type' => $eventType,
                'payload_hash' => hash('sha256', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'occurred_at' => now(),
                'metadata' => $metadata,
            ],
        );
    }
}
