# Storefront ↔ API integration contract

This document defines the deployment boundary for the static Storefront and the Laravel API.

## Recommended production topology

Prefer serving the Storefront and Laravel API behind the same public origin:

```text
https://shop.example.com/                 -> static Storefront
https://shop.example.com/api/v1/*         -> Laravel API
https://shop.example.com/sanctum/*        -> Laravel Sanctum
```

This keeps first-party Sanctum cookie authentication simple and minimizes CORS/session mistakes. The Storefront API client defaults to `/api/v1` for this topology.

If the API is intentionally hosted on a different same-site subdomain, configure the exact Storefront origin in `CORS_ALLOWED_ORIGINS`, include the Storefront host in `SANCTUM_STATEFUL_DOMAINS`, and configure the session cookie domain appropriately. Do not use wildcard credentialed CORS origins.

## Local development

The checked-in `.env.example` is safe for local HTTP development only:

```dotenv
SESSION_SECURE_COOKIE=false
SANCTUM_STATEFUL_DOMAINS=localhost,localhost:8000,127.0.0.1,127.0.0.1:8000
CORS_ALLOWED_ORIGINS=http://localhost:8000,http://127.0.0.1:8000
```

Every HTTPS staging/production deployment must use:

```dotenv
SESSION_SECURE_COOKIE=true
```

and must replace local origins with the exact deployed Storefront origins.

## API base override

The Storefront defaults to `/api/v1`. If an environment needs a different API origin, set one of these before `assets/js/api.js` is evaluated:

```html
<meta name="sharafi-api-base" content="https://api.example.com/api/v1">
```

or:

```js
window.SHARAFI_API_BASE = 'https://api.example.com/api/v1';
```

Do not hard-code environment-specific API hosts inside feature modules.

## Authentication and CSRF

The Storefront uses Sanctum first-party cookie authentication:

1. Mutating requests first obtain `/sanctum/csrf-cookie`.
2. Requests use `credentials: include`.
3. The `XSRF-TOKEN` cookie is sent as `X-XSRF-TOKEN`.
4. HTTP 419 refreshes CSRF once and retries once.
5. Authentication/access tokens are never stored in `localStorage`.

The OTP challenge identifier exists only in page memory. The plaintext OTP is never persisted by the Storefront.

## Cart and money boundary

Guest cart storage is temporary and may contain only purchase intent plus UI hints. After authentication only `variant_id` and `quantity` are synchronized to the server.

The Storefront must never submit browser-calculated price, discount, shipping, total, inventory quantity, or payment status as authoritative commerce data.

Authenticated cart price/availability, checkout quote, coupon result, order total, and payment state always come from the API.

## Catalog purchase hint

Product list resources expose a `purchase` object:

```json
{
  "variant_id": 123,
  "requires_selection": false
}
```

`variant_id` is returned only when the product has exactly one active variant. Multi-variant products must navigate to Product Detail so the customer explicitly selects a variant. The public API still does not expose exact inventory counts.

## Payment return page

Configure Zarinpal result redirect to the deployed Storefront result page, for example:

```dotenv
PAYMENT_RESULT_URL=https://shop.example.com/payment-result.html
```

The query string returned to the browser is only a navigation hint. `payment-result.html` re-fetches the authenticated order and payment from the API and never treats `payment_status` from the URL as payment truth.

`GET /api/v1/orders/{orderNumber}/payment` returns sanitized payment state for the authenticated order, including `order_status`, `retry_allowed`, `reservation_expires_at`, and the latest attempt status/failure message. The Storefront may use these values for user guidance, but retries must still go through `POST /payment-attempts` with a fresh idempotency key when the API asks for one.

## SMS and payment secrets

Keep these values only in server environment/secret storage:

- `SMSIR_API_KEY`
- `SMSIR_LINE_NUMBER`
- `SMSIR_OTP_TEMPLATE_ID`
- `ZARINPAL_MERCHANT_ID`
- `OTP_PEPPER`

Do not inject these values into Storefront HTML or JavaScript.

## Current Storefront integration coverage

The customer flow is wired for:

- public catalog and search/filter/sort/pagination
- Product Detail and explicit variant selection
- guest cart purchase intent
- OTP login/registration
- guest cart synchronization after login
- server-side cart mutation and pricing
- address create/update
- checkout quote and coupon validation
- idempotent order creation
- payment initiation and provider redirect
- verified payment result display
- account order/address view and payment recovery

Admin UI remains a separate integration slice and must continue to rely on server-side RBAC even when controls are hidden in the browser.
