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
- Cart synchronization
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
- Payment callbacks are verified against the payment provider before an order is marked paid.
- Inventory updates use database transactions and concurrency-safe checks.
- Secrets live only in environment/secret storage and are never committed.
- Uploaded files are validated by MIME/type, size, and storage policy; executable uploads are not served directly.
- Sensitive admin/customer actions are auditable.

## Money and currency

The UI displays Toman, but backend monetary values use an integer canonical unit. The selected canonical unit is Iranian Rial (IRR) as integer values, with explicit conversion at the presentation boundary. Floating point types must not be used for money.

## Core domain model

Planned entities:

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

Order items will store a snapshot of product name, SKU, quantity, unit price, discounts, taxes/fees if applicable, and final line total so historical orders are not changed by later catalog edits.

## Transaction boundaries

Critical flows that require database transactions:

- reserving/decrementing stock during checkout/order creation
- creating order + immutable order items
- applying discount usage limits
- confirming payment and transitioning order state
- restocking on eligible cancellation/refund

Idempotency will be used for payment callbacks and other externally retried write operations.

## API response conventions

Success responses use stable resource/data structures. Validation errors expose machine-readable field errors and a user-safe message. Internal exception details, SQL errors, stack traces, and secrets must never be returned in production.

Recommended HTTP behavior:

- 200/201 for successful reads/creates
- 204 for successful operations with no response body
- 401 for unauthenticated requests
- 403 for authenticated but unauthorized requests
- 404 for missing resources
- 409 for business/concurrency conflicts such as insufficient stock
- 422 for validation/business input errors
- 429 for rate limits

## Testing requirements

Backend work is not considered complete without automated coverage of the critical paths:

- authentication and OTP rate limits
- authorization / admin permissions
- product filtering and pagination
- cart pricing validation
- stock concurrency
- order creation
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
- `SmsGateway` abstraction with a fail-closed null provider
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
- inventory items with physical `on_hand`, `reserved`, derived availability, and immutable-style movement history foundation
- PostgreSQL constraints that reject invalid reservation states and zero-quantity inventory movements
- indexes for high-frequency RBAC, catalog, and inventory query paths
- public product listing/detail APIs that expose stock availability without leaking exact inventory counts
- category, brand, price range, search, sorting, and pagination foundations
- Admin product list API protected by `catalog.read`
- PostgreSQL integration tests for price and inventory constraints

Current public catalog endpoints:

- `GET /api/v1/catalog/products`
- `GET /api/v1/catalog/products/{slug}`
- `GET /api/v1/catalog/categories`
- `GET /api/v1/catalog/brands`

Current Admin catalog endpoint:

- `GET /api/v1/admin/catalog/products` (`catalog.read` permission required)

System roles/permissions can be created with `php artisan db:seed --class=SystemAccessSeeder`. Administrative access is then granted to an already verified user with `php artisan access:grant-role <mobile> <role>`. The command intentionally does not create or verify users and no default admin credentials are shipped with the application.

## SMS provider status

The application contract for SMS is implemented, but no commercial SMS provider is hard-coded. `SMS_DRIVER=null` intentionally fails closed until a provider is selected and credentials are supplied through secret storage. A provider-specific adapter will implement `SmsGateway` without changing the OTP domain logic.

Plaintext OTP values must not be written to database-backed queues, logs, exceptions, analytics, or audit records.
