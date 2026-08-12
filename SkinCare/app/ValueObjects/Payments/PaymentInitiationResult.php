<?php

namespace App\ValueObjects\Payments;

final readonly class PaymentInitiationResult
{
    public function __construct(
        public string $authority,
        public string $redirectUrl,
        public array $metadata = [],
    ) {}
}
