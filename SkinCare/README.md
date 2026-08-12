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

All application endpoints will be versioned under `/api/v1`.

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
- OTP codes are generated server-side, expire quickly, are rate-limited, and are never logged in plaintext.
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

The UI displays Toman, but backend monetary values should use an integer canonical unit. Recommended canonical unit: Iranian Rial (IRR) as integer values, with explicit conversion at the presentation boundary. Floating point types must not be used for money.

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

Success responses should use stable resource/data structures. Validation errors should expose machine-readable field errors and a user-safe message. Internal exception details, SQL errors, stack traces, and secrets must never be returned in production.

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

## Proposed stack (pending final approval)

Recommended implementation stack:

- Laravel 13
- PHP 8.5
- PostgreSQL
- Redis for cache, queues, rate-limit/ephemeral workloads where appropriate
- Laravel Sanctum / first-party cookie-based authentication for the storefront

No framework-specific application files should be scaffolded until this stack choice is confirmed.
