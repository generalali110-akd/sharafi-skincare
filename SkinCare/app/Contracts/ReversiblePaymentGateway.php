<?php

namespace App\Contracts;

use App\Models\PaymentAttempt;
use App\ValueObjects\Payments\PaymentReversalResult;

interface ReversiblePaymentGateway
{
    public function reverse(PaymentAttempt $attempt): PaymentReversalResult;
}
