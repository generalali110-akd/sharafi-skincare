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
        'request_lock_seconds' => (int) env('OTP_REQUEST_LOCK_SECONDS', 30),
        'request_lock_wait_seconds' => (int) env('OTP_REQUEST_LOCK_WAIT_SECONDS', 3),
    ],
    'outbox' => [
        'max_attempts' => (int) env('SMS_OUTBOX_MAX_ATTEMPTS', 8),
        'lock_ttl_seconds' => (int) env('SMS_OUTBOX_LOCK_TTL_SECONDS', 300),
        'notification_expire_hours' => (int) env('SMS_NOTIFICATION_EXPIRE_HOURS', 24),
    ],
    'smsir' => [
        'api_key' => env('SMSIR_API_KEY'),
        'sandbox' => (bool) env('SMSIR_SANDBOX', true),
        'otp_template_id' => env('SMSIR_OTP_TEMPLATE_ID'),
        'otp_code_parameter' => env('SMSIR_OTP_CODE_PARAMETER', 'CODE'),
        'line_number' => env('SMSIR_LINE_NUMBER'),
        'connect_timeout_seconds' => (int) env('SMSIR_CONNECT_TIMEOUT_SECONDS', 3),
        'timeout_seconds' => (int) env('SMSIR_TIMEOUT_SECONDS', 8),
        'max_message_chars' => (int) env('SMSIR_MAX_MESSAGE_CHARS', 320),
    ],
];
