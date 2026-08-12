<?php

return [
    'driver' => env('SMS_DRIVER', 'null'),
    'otp' => [
        'pepper' => env('OTP_PEPPER'),
        'ttl_seconds' => (int) env('OTP_TTL_SECONDS', 120),
        'resend_seconds' => (int) env('OTP_RESEND_SECONDS', 45),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'requests_per_mobile_10_minutes' => (int) env('OTP_REQUESTS_PER_MOBILE_10_MINUTES', 5),
        'requests_per_ip_10_minutes' => (int) env('OTP_REQUESTS_PER_IP_10_MINUTES', 20),
    ],
];
