<?php

return [
    'driver' => env('PAYMENT_DRIVER', 'null'),
    'callback_url' => env('PAYMENT_CALLBACK_URL', env('APP_URL').'/api/v1/payments/zarinpal/callback'),
    'result_url' => env('PAYMENT_RESULT_URL'),

    'zarinpal' => [
        'merchant_id' => env('ZARINPAL_MERCHANT_ID'),
        'sandbox' => (bool) env('ZARINPAL_SANDBOX', false),
        'base_url' => env('ZARINPAL_BASE_URL', 'https://payment.zarinpal.com'),
        'sandbox_base_url' => env('ZARINPAL_SANDBOX_BASE_URL', 'https://sandbox.zarinpal.com'),
        'connect_timeout_seconds' => (int) env('ZARINPAL_CONNECT_TIMEOUT_SECONDS', 3),
        'timeout_seconds' => (int) env('ZARINPAL_TIMEOUT_SECONDS', 8),
        'verify_attempts' => (int) env('ZARINPAL_VERIFY_ATTEMPTS', 3),
    ],
];
