<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutConflictException;
use App\Exceptions\PaymentUnavailableException;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class ZarinpalCallbackService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentSettlementService $settlement,
    ) {}

    public function handle(array $payload): array
    {
        if ($this->gateway->name() !== 'zarinpal') {
            throw new PaymentUnavailableException('درگاه فعال برای Callback زرین‌پال پیکربندی نشده است.');
        }

        $authority = trim((string) ($payload['Authority'] ?? $payload['authority'] ?? ''));
        if (! preg_match('/^[AS][A-Za-z0-9]{35}$/', $authority)) {
            throw (new ModelNotFoundException)->setModel(PaymentAttempt::class);
        }

        $attempt = PaymentAttempt::query()
            ->where('provider', 'zarinpal')
            ->where('authority', $authority)
            ->firstOrFail();
        $attempt->loadMissing('payment.order');
        $order = $attempt->payment?->order;

        if (! $order) {
            throw new CheckoutConflictException('سفارش مرتبط با پرداخت یافت نشد.');
        }

        $status = strtoupper(trim((string) ($payload['Status'] ?? $payload['status'] ?? '')));
        if ($status !== 'OK') {
            return [
                'status' => 'cancelled',
                'order_number' => $order->order_number,
                'message' => 'پرداخت تکمیل نشد یا توسط کاربر لغو شد.',
            ];
        }

        $result = $this->gateway->verify($attempt, $payload);
        if (! $result->successful) {
            $this->markFailedVerification($attempt, $result->failureCode, $result->failureMessage);

            return [
                'status' => 'failed',
                'order_number' => $order->order_number,
                'failure_code' => $result->failureCode,
                'message' => $result->failureMessage ?? 'پرداخت تأیید نشد.',
            ];
        }

        if (! $result->transactionId || ! $result->eventId) {
            throw new PaymentUnavailableException('نتیجه وریفای زرین‌پال کامل نیست.');
        }

        $stablePayload = [
            'provider' => 'zarinpal',
            'authority' => $attempt->authority,
            'transaction_id' => $result->transactionId,
            'amount_irr' => $attempt->amount_irr,
            'card_pan' => $result->metadata['card_pan'] ?? null,
            'card_hash' => $result->metadata['card_hash'] ?? null,
        ];

        $dedupeKey = hash('sha256', $result->eventId);
        $payloadHash = hash('sha256', json_encode($stablePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $order = $this->settlement->settleSuccessful(
            $attempt,
            $result->transactionId,
            $dedupeKey,
            $payloadHash,
            [
                'authority' => $attempt->authority,
                'card_pan' => $result->metadata['card_pan'] ?? null,
                'card_hash' => $result->metadata['card_hash'] ?? null,
                'fee_type' => $result->metadata['fee_type'] ?? null,
                'fee' => $result->metadata['fee'] ?? null,
            ],
        );

        return [
            'status' => $order->status->value,
            'order_number' => $order->order_number,
            'transaction_id' => $result->transactionId,
        ];
    }

    private function markFailedVerification(PaymentAttempt $attempt, ?string $failureCode, ?string $failureMessage): void
    {
        DB::transaction(function () use ($attempt, $failureCode, $failureMessage): void {
            $lockedAttempt = PaymentAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $payment = $lockedAttempt->payment()->lockForUpdate()->firstOrFail();

            if ($lockedAttempt->status !== PaymentAttemptStatus::Succeeded) {
                $lockedAttempt->status = PaymentAttemptStatus::Failed;
                $lockedAttempt->failure_code = $failureCode ?: 'provider_verification_failed';
                $lockedAttempt->failure_message = mb_substr($failureMessage ?: 'Payment verification failed.', 0, 500);
                $lockedAttempt->failed_at ??= now();
                $lockedAttempt->save();
            }

            if (! in_array($payment->status, [PaymentStatus::Paid, PaymentStatus::RefundPending, PaymentStatus::Refunded], true)) {
                $payment->status = PaymentStatus::Failed;
                $payment->save();
            }
        }, attempts: 3);
    }
}
