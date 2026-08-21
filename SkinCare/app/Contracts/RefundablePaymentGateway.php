<?php

namespace App\Contracts;

use App\Models\PaymentAttempt;
use App\ValueObjects\Payments\PaymentRefundResult;

interface RefundablePaymentGateway
{
    /**
     * Complete a provider-backed refund for a previously successful payment attempt.
     *
     * Implementations must be safe to retry for the same attempt and must never report
     * success until the provider has durably accepted or completed the refund.
     */
    public function refund(PaymentAttempt $attempt): PaymentRefundResult;
}
