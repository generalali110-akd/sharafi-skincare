<?php

return [
    'currency' => 'IRR',
    'timezone' => env('SHOP_TIMEZONE', 'Asia/Tehran'),

    /*
     * Initial Sharafi commerce policy:
     * - customer cancellation is only for pending-payment orders
     * - admin refund is a guarded refund_pending -> refunded workflow
     * - free shipping is calculated from pre-discount subtotal
     */
    'max_item_quantity' => (int) env('SHOP_MAX_ITEM_QUANTITY', 99),
    'max_variant_price_irr' => (int) env('SHOP_MAX_VARIANT_PRICE_IRR', 1_000_000_000_000),
    'free_shipping_threshold_irr' => (int) env('SHOP_FREE_SHIPPING_THRESHOLD_IRR', 8_000_000),
    'standard_shipping_irr' => (int) env('SHOP_STANDARD_SHIPPING_IRR', 450_000),
    'courier_shipping_irr' => (int) env('SHOP_COURIER_SHIPPING_IRR', 650_000),
    'order_reservation_ttl_minutes' => (int) env('SHOP_ORDER_RESERVATION_TTL_MINUTES', 15),
];
