<?php

namespace App\Contracts;

use App\Models\PaymentAttempt;
use App\ValueObjects\Payments\PaymentInitiationResult;
use App\ValueObjects\Payments\PaymentVerificationResult;

interface PaymentGateway
{
    public function name(): string;

    public function initiate(PaymentAttempt $attempt, string $callbackUrl): PaymentInitiationResult;

    public function verify(PaymentAttempt $attempt, array $payload): PaymentVerificationResult;
}
