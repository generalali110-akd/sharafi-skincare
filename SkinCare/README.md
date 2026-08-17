# Sharafi Skin Care Backend

This directory contains the production backend for the Sharafi Skin Care ecommerce application.

## Architecture goals

The backend is the source of truth for all security-sensitive and financial data. The browser must never be trusted for price, stock, discount, shipping cost, order totals, payment status, authentication, authorization, or admin permissions.

Primary priorities:

1. Security and correctness
2. Transactional integrity for orders, payments, and inventory
3. Clean modular code and testability
4. Predictable API contracts for Storefront and Admin
5. Performance through indexes, caching, queues, and pagination where appropriate
6. UX-friendly errors and stable response formats

## API boundary

All application endpoints are versioned under `/api/v1`.

Public Storefront modules:

- Catalog: products, categories, brands, search, filters, sorting, pagination
- Product detail: media, pricing, availability, attributes/variants
- Shipping information and public configuration

Authenticated customer modules:

- OTP authentication and session lifecycle
- Customer profile
- Addresses
- Wishlist
- Server-side cart
- Checkout quote
- Orders and order history
- Payment initiation/status

Admin modules:

- Dashboard metrics
- Products and variants
- Categories and brands
- Inventory and stock movements
- Orders and fulfillment status
- Customers
- Discounts/coupons
- Media management
- Audit logs

## Security rules

- Authentication and authorization are enforced server-side on every protected route.
- Admin access is role/permission based; hiding a UI control is never considered authorization.
- OTP codes are generated server-side, expire quickly, are rate-limited, and are never logged or stored in plaintext.
- Session/auth cookies must be Secure, HttpOnly, and SameSite-aware in production.
- CSRF protection is required for state-changing first-party browser requests.
- Validation is performed server-side even when the frontend already validates the same field.
- Order prices are recalculated from current server-side product/discount data.
- Client-supplied price, discount, shipping, and total fields are rejected on cart/checkout/order write paths.
- Payment callbacks are verified against the payment provider before an order is marked paid.
- Inventory updates use database transactions and concurrency-safe checks.
- Secrets live only in environment/secret storage and are never committed.
- Uploaded files are validated by MIME/type, size, and storage policy; executable uploads are not served directly.
- Sensitive admin/customer actions are auditable.

## Money and currency

The UI displays Toman, but backend monetary values use an integer canonical unit. The selected canonical unit is Iranian Rial (IRR) as integer values, with explicit conversion at the presentation boundary. Floating point types must not be used for money.

## Core domain model

Implemented/planned entities:

- users
- roles / permissions
- otp_challenges
- addresses
- categories
- brands
- products
- product_variants
- product_images
- inventory_items
- inventory_movements
- wishlists
- carts / cart_items
- discount_rules / coupons
- orders
- order_items (immutable commercial snapshot)
- payments
- payment_events
- shipments
- audit_logs

Order items store a snapshot of product name, SKU, quantity, unit price, discounts, and final line total so historical orders are not changed by later catalog edits.

## Transaction boundaries

Critical flows that require database transactions:

- reserving stock during pending-payment order creation
- creating order + immutable order items
- releasing reservations on cancellation/expiry
- applying discount usage limits
- confirming payment and transitioning order state
- converting a paid reservation into a physical stock decrement
- restocking on eligible cancellation/refund

Idempotency is required for order creation, payment callbacks, and other externally retried write operations.

## API response conventions

Success responses use stable resource/data structures. Validation errors expose machine-readable field errors and a user-safe message. Internal exception details, SQL errors, stack traces, and secrets must never be returned in production.

Recommended HTTP behavior:

- 200/201 for successful reads/creates
- 204 for successful operations with no response body
- 401 for unauthenticated requests
- 403 for authenticated but unauthorized requests
- 404 for missing or non-owned resources
- 409 for business/concurrency conflicts such as insufficient stock or invalid order state
- 422 for validation/business input errors
- 429 for rate limits

## Testing requirements

Backend work is not considered complete without automated coverage of the critical paths:

- authentication and OTP rate limits
- authorization / admin permissions
- product filtering and pagination
- cart pricing validation
- stock concurrency
- order creation and idempotent retry
- reservation cancellation/expiry
- address ownership
- discount limits
- payment callback idempotency
- forbidden state transitions

## Selected stack

The backend foundation is implemented with:

- Laravel 13
- PHP 8.4+; CI currently verifies PHP 8.5
- PostgreSQL; CI verifies migrations against PostgreSQL 18
- Laravel Sanctum with first-party cookie/session authentication
- Database-backed cache, queue, and sessions for the initial phase
- Redis-ready configuration for cache/queue/rate-limit workloads when operationally justified

Composer dependencies are locked for reproducible builds and audited in CI.

## Implemented authentication foundation

- versioned `/api/v1` routing and health endpoint
- PostgreSQL configuration and migrations
- Sanctum stateful authentication foundation
- Iranian mobile normalization and validation
- OTP request/verify/logout flow
- server-generated six-digit OTP codes
- HMAC-SHA256 OTP storage using a secret server-side pepper
- expiry, resend window, attempt limits, and mobile/IP rate limiting
- session regeneration after successful authentication
- `SmsGateway` abstraction with a fail-closed null provider and provider-specific adapters
- PHPUnit feature/unit coverage
- Composer security audit, PostgreSQL migration check, tests, and Laravel Pint in GitHub Actions

## Implemented authorization, catalog, and inventory foundation

- database-backed roles and granular permissions
- protected Admin API routes enforced by server-side permission middleware
- idempotent system access seeder with no default admin account or predictable credentials
- `access:grant-role` operational command that only grants roles to existing, active, mobile-verified users
- category hierarchy and many-to-many product categorization
- brands, products, product variants, and product image metadata
- SKU and price stored at variant level so future size/color/volume variants do not require a schema rewrite
- IRR integer pricing and PostgreSQL constraints that reject invalid prices
- inventory items with physical `on_hand`, `reserved`, derived availability, and movement history
- PostgreSQL constraints that reject invalid reservation states and zero-quantity inventory movements
- indexes for high-frequency RBAC, catalog, and inventory query paths
- public product listing/detail APIs that expose stock availability without leaking exact inventory counts
- category, brand, price range, search, sorting, and pagination foundations
- PostgreSQL integration tests for price and inventory constraints

## Implemented audited Admin mutations

Administrative writes are separated by permission and executed transactionally:

- `catalog.write` manages product metadata, variants, brands, and categories
- `inventory.write` is the only permission that may change physical stock or inventory settings
- catalog write endpoints explicitly reject `on_hand`, `reserved`, and `reorder_level` fields
- inventory adjustments use row locking and append an `inventory_movements` ledger entry
- stock adjustments that would make `on_hand` negative or lower than `reserved` return HTTP 409 and roll back completely
- product/category/brand/variant/inventory mutations create audit records in the same database transaction
- operational role grants are audited with a system/CLI source and never create an implicit user or default administrator
- audit payloads recursively redact sensitive credential/OTP/token keys
- category parent updates reject hierarchy cycles
- an active product cannot lose its last active variant
- no physical delete API is exposed for catalog taxonomies in this slice; `is_active` is used for safe deactivation

## Implemented customer commerce foundation

Customer purchase state is server-side and transaction-safe:

- customer address CRUD is ownership-scoped; PostgreSQL enforces at most one default address per user
- cart state is stored server-side as `variant_id + quantity`; prices are never persisted from browser input
- cart mutations reject financial fields and validate current publication, variant activity, quantity, and availability
- checkout quote recalculates product prices and shipping from server configuration every time
- standard shipping becomes free at the configured threshold; courier pricing remains a separate explicit method
- order creation requires a per-user `Idempotency-Key` and returns the existing order on safe retries instead of creating a duplicate
- order numbers use ULIDs and are independent from internal database IDs
- address and commercial product details are snapshotted into the order so future edits do not rewrite history
- pending-payment orders increase `inventory_items.reserved` without decrementing physical `on_hand`
- variant and inventory rows are locked in deterministic ID order during order creation to prevent overselling races
- reservation hold/release operations append `inventory_movements` entries
- cancellation releases a reservation exactly once; invalid state transitions return HTTP 409
- stale pending-payment reservations are released by `orders:expire-reservations`, scheduled every minute with overlap prevention
- reservation TTL, quantity limits, and shipping rules are centralized in `config/shop.php` and environment settings
- the initial business policy is documented in `docs/business-policy.md`
- order/customer API payloads do not expose internal idempotency keys or another user's resources

Current customer commerce endpoints (authentication required):

- `GET /api/v1/addresses`
- `POST /api/v1/addresses`
- `PATCH /api/v1/addresses/{address}`
- `DELETE /api/v1/addresses/{address}`
- `GET /api/v1/cart`
- `PUT /api/v1/cart/items/{variant}`
- `DELETE /api/v1/cart/items/{variant}`
- `POST /api/v1/checkout/quote`
- `GET /api/v1/orders`
- `GET /api/v1/orders/{orderNumber}`
- `POST /api/v1/orders` (`Idempotency-Key` required)
- `POST /api/v1/orders/{orderNumber}/cancel`

Current public catalog endpoints:

- `GET /api/v1/catalog/products`
- `GET /api/v1/catalog/products/{slug}`
- `GET /api/v1/catalog/categories`
- `GET /api/v1/catalog/brands`

Current Admin endpoints:

- `GET /api/v1/admin/catalog/products` — `catalog.read`
- `POST /api/v1/admin/catalog/products` — `catalog.write`
- `PATCH /api/v1/admin/catalog/products/{product}` — `catalog.write`
- `POST /api/v1/admin/catalog/products/{product}/variants` — `catalog.write`
- `PATCH /api/v1/admin/catalog/variants/{variant}` — `catalog.write`
- `POST /api/v1/admin/catalog/brands` — `catalog.write`
- `PATCH /api/v1/admin/catalog/brands/{brand}` — `catalog.write`
- `POST /api/v1/admin/catalog/categories` — `catalog.write`
- `PATCH /api/v1/admin/catalog/categories/{category}` — `catalog.write`
- `GET /api/v1/admin/inventory` — `inventory.read`
- `POST /api/v1/admin/inventory/{variant}/adjust` — `inventory.write`
- `PATCH /api/v1/admin/inventory/{variant}/settings` — `inventory.write`
- `GET /api/v1/admin/orders` — `orders.read`
- `GET /api/v1/admin/orders/{orderNumber}` — `orders.read`
- `PATCH /api/v1/admin/orders/{orderNumber}/status` — `orders.write`; supports fulfillment statuses plus the guarded `refund_pending` → `refunded` admin refund workflow
- `GET /api/v1/admin/audit-logs` — `audit.read`

System roles/permissions can be created with `php artisan db:seed --class=SystemAccessSeeder`. Administrative access is then granted to an already verified user with `php artisan access:grant-role <mobile> <role>`. The command intentionally does not create or verify users and no default admin credentials are shipped with the application.

## SMS provider status

The application remains provider-neutral through `SmsGateway`. `SMS_DRIVER=null` is still the default and intentionally fails closed when no provider secrets are configured.

An SMS.ir adapter is implemented for test/staging use without coupling OTP, orders, payments, or the transactional outbox to SMS.ir:

- OTP uses SMS.ir Verify templates over the official REST API.
- Sandbox uses a dedicated SMS.ir Sandbox API key while keeping the same API endpoint; the built-in Verify template can be used for initial OTP tests.
- order/payment/shipment notifications can use the configured SMS.ir sending line through plain transactional send during initial testing.
- API keys, template IDs, and sending-line values are read only from environment/secret storage and are never committed.
- permanent provider/configuration failures stop retrying immediately; transient network/rate/service failures remain eligible for bounded outbox retries.
- no blind automatic provider failover is performed because an ambiguous timeout can otherwise cause duplicate SMS delivery.

The detailed provider contract, environment variables, retry rules, and provider-swap boundary are documented in `docs/sms-provider-contract.md`.

Plaintext OTP values must not be written to database-backed queues, logs, exceptions, analytics, or audit records. Replacing SMS.ir with the customer's final provider should require only an adapter/configuration/template mapping change; OTP, order/payment, outbox, API, and frontend contracts remain unchanged.

## Operational release docs

- `docs/business-policy.md` records the initial commerce policy decisions.
- `docs/payment-discount-contract.md` and `docs/zarinpal-integration.md` define payment, discount, callback, and refund boundaries.
- `docs/sms-provider-contract.md` defines SMS.ir, OTP, notification outbox, and provider-swap boundaries.
- `docs/staging-provider-smoke.md` defines the staging provider validation runbook.
- `docs/production-readiness-checklist.md` is the final production release gate.
