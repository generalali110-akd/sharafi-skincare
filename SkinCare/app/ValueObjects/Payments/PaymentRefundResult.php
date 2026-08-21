<?php

namespace App\ValueObjects\Payments;

final readonly class PaymentRefundResult
{
    public function __construct(
        public bool $successful,
        public ?string $providerRefundId = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $metadata = [],
    ) {}
}
