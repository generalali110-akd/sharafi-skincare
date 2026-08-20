# Sharafi Business Policy

This document records the initial backend policy decisions that are now treated as code-level contract. Environment values may tune the numeric thresholds, but API clients must not override them.

## Shipping and cart

- Canonical money unit is integer IRR.
- Maximum quantity per cart item is `SHOP_MAX_ITEM_QUANTITY`, default `99`.
- Standard shipping is `SHOP_STANDARD_SHIPPING_IRR`, default `450000`.
- Courier shipping is `SHOP_COURIER_SHIPPING_IRR`, default `650000`.
- Standard shipping becomes free when the pre-discount subtotal reaches `SHOP_FREE_SHIPPING_THRESHOLD_IRR`, default `8000000`.
- Courier shipping remains an explicit paid method and does not become free through the standard-shipping threshold.

## Orders

- Pending-payment reservations last `SHOP_ORDER_RESERVATION_TTL_MINUTES`, default `15`.
- Customer cancellation is allowed only while the order is `pending_payment`.
- Cancellation and expiry release reserved inventory exactly once.
- Order creation and payment initiation require idempotency keys.
- Client-supplied financial totals are rejected.

## Payment

- Zarinpal is the selected first payment provider.
- `PAYMENT_DRIVER=null` is safe local fail-closed mode.
- `PAYMENT_DRIVER=zarinpal` requires a valid merchant ID plus HTTPS callback/result URLs outside local development.
- Browser callback values are navigation hints only; server-to-server verification is payment truth.
- Late verified payments after released reservations move the order and payment to `refund_pending`.

## Refunds

- General automatic provider refunds are not enabled yet.
- Admin refund flow is deliberately two step: `refund_pending -> refunded`.
- `refunded` requires an existing payment already marked `refund_pending`.
- Refund completion records local operational state and timestamp. Provider-side GraphQL/OAuth refund automation is a separate future slice.

## SMS notifications

- SMS.ir is the selected first SMS provider.
- `SMS_DRIVER=null` is safe local fail-closed mode.
- OTP uses SMS.ir Verify templates.
- Order/payment notifications are queued transactionally through the SMS outbox.
- Initial notification events are `order_created`, `payment_succeeded`, `order_shipped`, `order_cancelled`, `refund_pending`, and `refund_completed`.
