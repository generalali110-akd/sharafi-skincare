<?php

return [
    'driver' => env('PAYMENT_DRIVER', 'null'),
    'callback_url' => env('PAYMENT_CALLBACK_URL', env('APP_URL').'/api/v1/payments/callback'),
];
