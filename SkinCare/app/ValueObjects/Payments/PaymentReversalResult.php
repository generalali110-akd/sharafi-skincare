<?php

namespace App\ValueObjects\Payments;

final readonly class PaymentReversalResult
{
    public function __construct(
        public bool $successful,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        public array $metadata = [],
    ) {}
}
