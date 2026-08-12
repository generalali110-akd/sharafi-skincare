<?php

namespace App\ValueObjects\Payments;

final readonly class PaymentVerificationResult
{
    public function __construct(
        public bool $successful,
        public ?string $transactionId = null,
        public ?string $eventId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $metadata = [],
    ) {}
}
