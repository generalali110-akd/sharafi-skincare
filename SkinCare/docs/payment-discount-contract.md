# Payment, Discount, and Order State Contract

This document defines the financial contract implemented by the Sharafi Skin Care backend. The domain remains provider-neutral, while Zarinpal is the selected first Iranian gateway adapter. No environment is considered live until `PAYMENT_DRIVER=zarinpal`, provider secrets, HTTPS callback/result URLs, and staging smoke checks are configured.

## Order state machine

Allowed transitions are centralized in `OrderStateMachine`:

- `pending_payment -> paid | cancelled | expired`
- `paid -> processing | refund_pending`
- `processing -> shipped | refund_pending`
- `shipped -> delivered | refund_pending`
- `delivered -> refund_pending`
- `cancelled -> refund_pending` only for a verified late payment after the reservation was already released
- `expired -> refund_pending` only for a verified late payment after the reservation was already released
- `refund_pending -> refunded`

Every transition is appended to `order_status_transitions`. Controllers and future gateway adapters must not write order status directly.

## Discount contract

Discount rules are server-side. Customer requests may submit only a coupon code; they cannot submit a discount amount.

Admin write API uses explicit units:

- fixed discount: `kind=fixed` with `amount_irr`
- percentage discount: `kind=percentage` with `percentage_bps`
- `10_000` basis points means 100%; `1_000` means 10%
- `max_discount_irr`, when present, must be greater than zero

The internal database column `value` is not an API field and is rejected when sent by Admin clients.

Coupon codes are normalized to uppercase and PostgreSQL enforces the canonical format `^[A-Z0-9_-]{3,64}$`.

Usage lifecycle:

1. Checkout quote previews eligibility and value.
2. Order creation locks the discount rule, rechecks time/limits/subtotal, and creates a `reserved` redemption.
3. Cancelled or expired pending-payment orders change that redemption to `released`.
4. Verified successful payment changes it to `consumed` in the same commercial settlement flow.
5. `reserved` and `consumed` both count against total and per-user usage limits to prevent concurrent oversubscription.

## Order idempotency

`POST /api/v1/orders` requires an `Idempotency-Key`.

- raw idempotency keys are not stored; SHA-256 is stored instead
- an immutable request fingerprint covers address, shipping method, and normalized coupon
- retrying the same key with the same request returns the original order
- reusing the same key with a different request returns HTTP 409

A client must create a new key for a genuinely new order operation.

## Payment initiation

Customer endpoints:

- `GET /api/v1/orders/{orderNumber}/payment`
- `POST /api/v1/orders/{orderNumber}/payment-attempts` with `Idempotency-Key`

The browser cannot submit amount, currency, provider, or authority. The backend uses the immutable order total.

`PAYMENT_DRIVER=null` is the default and fails closed with HTTP 503 before creating payment records. This prevents development configuration from looking like a successful payment integration.

A payment attempt stores a hash of its idempotency key. Provider redirect URLs are validated; production redirects must use HTTPS.

If a process crash leaves an attempt in the pre-redirect `created` state, retrying the same payment idempotency key fails safely instead of silently returning an unusable attempt. The client must create a fresh payment-attempt key. A real provider adapter should correlate every provider request with the local attempt public ULID.

## Payment settlement

`PaymentSettlementService` is the only current domain path for a verified success. A real callback adapter must verify the provider response first and call settlement only after authoritative verification.

If server-to-server provider verification returns an authoritative failure, the local payment attempt is marked `failed`, the payment is marked `failed` unless it was already paid/refunding/refunded, and the customer must create a fresh payment-attempt idempotency key for retry. Reusing the same failed payment idempotency key returns a retry-required error instead of a stale redirect.

Settlement runs transactionally and checks:

- payment attempt amount equals payment amount
- payment amount equals immutable order total
- transaction ID is present and bounded
- event dedupe key is a SHA-256 value
- payload hash is a SHA-256 value
- an already-seen event with the same dedupe key must have the same payment, attempt, provider, and payload hash
- inventory reservation still matches the order

For a normal pending-payment order, successful settlement atomically:

1. decrements `reserved`
2. decrements physical `on_hand`
3. appends a `sale_settlement` inventory movement
4. consumes the reserved coupon redemption
5. marks payment paid
6. transitions order to `paid`

Replaying the same verified event does not decrement inventory or consume the coupon twice.

If a payment is authoritatively verified after the order was already cancelled/expired and its reservation was released, inventory is not sold again. Payment and order move to `refund_pending` for later refund handling.

## Money safety

All money is integer IRR. Floating-point money is prohibited.

- pricing uses integer-only safe multiplication/addition
- product variant price has an operational safety cap of `1_000_000_000_000 IRR`
- the same cap is enforced by Admin validation and PostgreSQL constraints
- free-shipping eligibility is based on pre-discount subtotal
- current canonical shipping defaults are `450_000 IRR` standard and `650_000 IRR` courier

Relevant environment variables:

- `SHOP_MAX_ITEM_QUANTITY`
- `SHOP_MAX_VARIANT_PRICE_IRR`
- `SHOP_FREE_SHIPPING_THRESHOLD_IRR`
- `SHOP_STANDARD_SHIPPING_IRR`
- `SHOP_COURIER_SHIPPING_IRR`
- `SHOP_ORDER_RESERVATION_TTL_MINUTES`
- `PAYMENT_DRIVER`
- `PAYMENT_CALLBACK_URL`

## Current live-readiness boundary

The following are implemented and must be smoke-tested before staging/production traffic:

- Zarinpal payment request, redirect, callback lookup, and server-to-server verify
- idempotent successful settlement and late-payment refund-pending handling
- guarded admin refund state workflow: `refund_pending -> refunded`
- sanitized payment result payload with latest-attempt status/failure details for the authenticated customer

The following are deliberately not faked:

- provider credentials
- automatic general refunds through Zarinpal GraphQL/OAuth
- partial refunds
- treating browser callback query values as payment truth

The next payment slice should wire provider credentials in staging, run the provider smoke workflow, and only then decide whether the separate Zarinpal GraphQL refund API is needed operationally.
