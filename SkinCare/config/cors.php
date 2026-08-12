<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_values(array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:8000')))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'Origin', 'X-Requested-With', 'X-XSRF-TOKEN', 'X-CSRF-TOKEN'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => true,
];
