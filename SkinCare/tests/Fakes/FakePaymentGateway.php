<?php

namespace Tests\Fakes;

use App\Contracts\PaymentGateway;
use App\Contracts\ReversiblePaymentGateway;
use App\Models\PaymentAttempt;
use App\ValueObjects\Payments\PaymentInitiationResult;
use App\ValueObjects\Payments\PaymentReversalResult;
use App\ValueObjects\Payments\PaymentVerificationResult;

final class FakePaymentGateway implements PaymentGateway, ReversiblePaymentGateway
{
    public array $reversals = [];

    public function __construct(private readonly string $gatewayName = 'fake') {}

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

    public function reverse(PaymentAttempt $attempt): PaymentReversalResult
    {
        $this->reversals[] = $attempt->getKey();

        return new PaymentReversalResult(true, metadata: [
            'fake_reversal_id' => 'REV-'.$attempt->public_id,
        ]);
    }
}
