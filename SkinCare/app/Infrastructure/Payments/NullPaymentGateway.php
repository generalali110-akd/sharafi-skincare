<?php

namespace App\Infrastructure\Payments;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentUnavailableException;
use App\Models\PaymentAttempt;
use App\ValueObjects\Payments\PaymentInitiationResult;
use App\ValueObjects\Payments\PaymentVerificationResult;

final class NullPaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'null';
    }

    public function initiate(PaymentAttempt $attempt, string $callbackUrl): PaymentInitiationResult
    {
        throw new PaymentUnavailableException('درگاه پرداخت هنوز پیکربندی نشده است.');
    }

    public function verify(PaymentAttempt $attempt, array $payload): PaymentVerificationResult
    {
        throw new PaymentUnavailableException('درگاه پرداخت هنوز پیکربندی نشده است.');
    }
}
