<?php

namespace Tests\Fakes;

use App\Contracts\PaymentGateway;
use App\Contracts\RefundablePaymentGateway;
use App\Models\PaymentAttempt;
use App\ValueObjects\Payments\PaymentInitiationResult;
use App\ValueObjects\Payments\PaymentRefundResult;
use App\ValueObjects\Payments\PaymentVerificationResult;

final class FakePaymentGateway implements PaymentGateway, RefundablePaymentGateway
{
    public array $refunds = [];

    public function __construct(
        private readonly string $gatewayName = 'fake',
        private readonly bool $refundSuccessful = true,
    ) {}

    public function name(): string
    {
        return $this->gatewayName;
    }

    public function initiate(PaymentAttempt $attempt, string $callbackUrl): PaymentInitiationResult
    {
        return new PaymentInitiationResult(
            authority: 'AUTH-'.$attempt->public_id,
            redirectUrl: 'https://payments.example.test/pay/'.$attempt->public_id,
            metadata: ['callback_url_hash' => hash('sha256', $callbackUrl)],
        );
    }

    public function verify(PaymentAttempt $attempt, array $payload): PaymentVerificationResult
    {
        return new PaymentVerificationResult(
            successful: (bool) ($payload['successful'] ?? false),
            transactionId: $payload['transaction_id'] ?? null,
            eventId: $payload['event_id'] ?? null,
        );
    }

    public function refund(PaymentAttempt $attempt): PaymentRefundResult
    {
        $this->refunds[] = $attempt->getKey();

        if (! $this->refundSuccessful) {
            return new PaymentRefundResult(
                successful: false,
                failureCode: 'fake_refund_failed',
                failureMessage: 'Fake provider rejected the refund.',
            );
        }

        return new PaymentRefundResult(
            successful: true,
            providerRefundId: 'REF-'.$attempt->public_id,
            metadata: ['fake_refund' => true],
        );
    }
}
