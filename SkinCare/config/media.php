<?php

return [
    'product_images' => [
        'disk' => env('PRODUCT_IMAGE_DISK', 'public'),
        'max_bytes' => (int) env('PRODUCT_IMAGE_MAX_BYTES', 8 * 1024 * 1024),
        'max_width' => (int) env('PRODUCT_IMAGE_MAX_WIDTH', 6000),
        'max_height' => (int) env('PRODUCT_IMAGE_MAX_HEIGHT', 6000),
        'max_pixels' => (int) env('PRODUCT_IMAGE_MAX_PIXELS', 24_000_000),
        'webp_quality' => (int) env('PRODUCT_IMAGE_WEBP_QUALITY', 82),
        'avif_quality' => (int) env('PRODUCT_IMAGE_AVIF_QUALITY', 55),
    ],
];
