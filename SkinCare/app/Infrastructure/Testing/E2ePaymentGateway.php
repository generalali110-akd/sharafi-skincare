<?php

namespace App\Infrastructure\Testing;

use App\Contracts\PaymentGateway;
use App\Models\PaymentAttempt;
use App\ValueObjects\Payments\PaymentInitiationResult;
use App\ValueObjects\Payments\PaymentVerificationResult;
use RuntimeException;

final class E2ePaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'e2e';
    }

    public function initiate(PaymentAttempt $attempt, string $callbackUrl): PaymentInitiationResult
    {
        $order = $attempt->payment()->with('order')->firstOrFail()->order;
        $resultUrl = trim((string) config('payment.result_url'));
        if ($resultUrl === '') {
            $resultUrl = rtrim((string) config('app.url'), '/').'/payment-result.html';
        }

        $separator = str_contains($resultUrl, '?') ? '&' : '?';

        return new PaymentInitiationResult(
            authority: 'E2E-'.$attempt->public_id,
            redirectUrl: $resultUrl.$separator.'order='.rawurlencode($order->order_number),
            metadata: ['environment' => 'testing'],
        );
    }

    public function verify(PaymentAttempt $attempt, array $payload): PaymentVerificationResult
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('E2E payment verification is restricted to the testing environment.');
        }

        return new PaymentVerificationResult(
            successful: true,
            transactionId: 'E2E-TX-'.$attempt->public_id,
            eventId: hash('sha256', 'e2e-event:'.$attempt->public_id),
            metadata: ['environment' => 'testing'],
        );
    }
}
