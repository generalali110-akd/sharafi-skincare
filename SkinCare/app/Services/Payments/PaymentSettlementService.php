<?php

namespace App\Services\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvent;
use App\Services\Commerce\DiscountService;
use App\Services\Commerce\OrderStateMachine;
use App\Services\Notifications\OrderNotificationOutbox;
use Illuminate\Support\Facades\DB;

final class PaymentSettlementService
{
    public function __construct(
        private readonly DiscountService $discounts,
        private readonly OrderStateMachine $stateMachine,
        private readonly OrderNotificationOutbox $notifications,
    ) {}

    public function settleSuccessful(
        PaymentAttempt $attempt,
        string $transactionId,
        string $dedupeKey,
        string $payloadHash,
        array $metadata = [],
    ): Order {
        $this->assertIdentifiers($transactionId, $dedupeKey, $payloadHash);

        return DB::transaction(function () use ($attempt, $transactionId, $dedupeKey, $payloadHash, $metadata): Order {
            $attempt = PaymentAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();
            $payment = $attempt->payment()->lockForUpdate()->firstOrFail();
            $order = $payment->order()->lockForUpdate()->firstOrFail();

            $existingEvent = PaymentEvent::query()->where('dedupe_key', $dedupeKey)->first();
            if ($existingEvent) {
                if ($existingEvent->payment_id !== $payment->getKey()
                    || $existingEvent->attempt_id !== $attempt->getKey()
                    || $existingEvent->provider !== $attempt->provider) {
                    throw new CheckoutConflictException('شناسه رویداد پرداخت با رویداد دیگری تداخل دارد.');
                }
                if (! hash_equals($existingEvent->payload_hash, $payloadHash)) {
                    throw new CheckoutConflictException('Payload رویداد تکراری پرداخت با نسخه قبلی سازگار نیست.');
                }

                return $order;
            }

            if ($attempt->amount_irr !== $payment->amount_irr || $payment->amount_irr !== $order->total_irr) {
                throw new CheckoutConflictException('مبلغ تأییدشده با مبلغ سفارش سازگار نیست.');
            }

            if ($attempt->status === PaymentAttemptStatus::Succeeded) {
                if ($attempt->transaction_id !== $transactionId) {
                    throw new CheckoutConflictException('شناسه تراکنش تأییدشده با تلاش قبلی سازگار نیست.');
                }

                $this->recordEvent($payment->getKey(), $attempt->getKey(), $attempt->provider, $dedupeKey, $payloadHash, $metadata);

                return $order;
            }

            if ($attempt->status === PaymentAttemptStatus::Failed) {
                throw new CheckoutConflictException('تلاش پرداخت ناموفق قبلی قابل تبدیل به پرداخت موفق نیست.');
            }

            $attempt->status = PaymentAttemptStatus::Succeeded;
            $attempt->transaction_id = $transactionId;
            $attempt->verified_at = now();
            $attempt->save();

            $payment->status = PaymentStatus::Paid;
            $payment->paid_at = now();
            $payment->save();

            $this->recordEvent($payment->getKey(), $attempt->getKey(), $attempt->provider, $dedupeKey, $payloadHash, $metadata);

            if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Expired], true)) {
                $payment->status = PaymentStatus::RefundPending;
                $payment->save();
                $this->stateMachine->transition($order, OrderStatus::RefundPending, null, 'late_payment_after_release');

                return $order;
            }

            if ($order->status !== OrderStatus::PendingPayment) {
                if (in_array($order->status, [OrderStatus::Paid, OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered], true)) {
                    return $order;
                }

                throw new CheckoutConflictException('وضعیت سفارش برای تسویه پرداخت معتبر نیست.');
            }

            $items = $order->items()->whereNotNull('variant_id')->orderBy('variant_id')->get();
            $inventories = InventoryItem::query()
                ->whereIn('variant_id', $items->pluck('variant_id')->all())
                ->orderBy('variant_id')
                ->lockForUpdate()
                ->get()
                ->keyBy('variant_id');

            foreach ($items as $item) {
                $inventory = $inventories->get($item->variant_id);
                if (! $inventory || $inventory->reserved < $item->quantity || $inventory->on_hand < $item->quantity) {
                    throw new CheckoutConflictException('رزرو موجودی سفارش برای تسویه ناسازگار است.');
                }

                $inventory->reserved -= $item->quantity;
                $inventory->on_hand -= $item->quantity;
                $inventory->save();

                InventoryMovement::query()->create([
                    'variant_id' => $item->variant_id,
                    'type' => 'sale_settlement',
                    'quantity' => -$item->quantity,
                    'reason' => 'payment_verified',
                    'actor_user_id' => null,
                    'reference_type' => 'order',
                    'reference_id' => $order->order_number,
                    'metadata' => ['reserved_delta' => -$item->quantity],
                ]);
            }

            $this->discounts->consumeForOrder($order);
            $this->stateMachine->transition($order, OrderStatus::Paid, null, 'payment_verified');
            $this->notifications->paymentSucceeded($order);

            return $order;
        }, attempts: 3);
    }

    private function assertIdentifiers(string $transactionId, string $dedupeKey, string $payloadHash): void
    {
        if ($transactionId === '' || mb_strlen($transactionId) > 190) {
            throw new CheckoutConflictException('شناسه تراکنش پرداخت معتبر نیست.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $dedupeKey)) {
            throw new CheckoutConflictException('کلید یکتای رویداد پرداخت معتبر نیست.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/', $payloadHash)) {
            throw new CheckoutConflictException('Hash رویداد پرداخت معتبر نیست.');
        }
    }

    private function recordEvent(
        int $paymentId,
        int $attemptId,
        string $provider,
        string $dedupeKey,
        string $payloadHash,
        array $metadata,
    ): void {
        PaymentEvent::query()->create([
            'payment_id' => $paymentId,
            'attempt_id' => $attemptId,
            'provider' => $provider,
            'event_type' => 'verified_success',
            'dedupe_key' => $dedupeKey,
            'payload_hash' => $payloadHash,
            'occurred_at' => now(),
            'metadata' => $metadata,
        ]);
    }
}
