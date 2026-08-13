<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\CheckoutConflictException;
use App\Exceptions\PaymentUnavailableException;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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
        if (! preg_match('/^[AS][A-Za-z0-9]{20,99}$/', $authority)) {
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
}
