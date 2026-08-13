# Browser E2E testing contract

This document defines the production-safety boundary for the Sharafi browser E2E suite.

## What is real in E2E

The `Browser E2E` GitHub Actions workflow runs the same commerce contracts used by the Storefront against:

- Laravel on PHP 8.5
- PostgreSQL 18
- database-backed Laravel sessions
- Sanctum first-party cookie authentication and CSRF protection
- the real Storefront and Admin HTML/JavaScript
- a real Chromium browser through Playwright
- the real cart, checkout, address, order, inventory reservation, payment-attempt and payment-settlement domain services

The Storefront is mounted into Laravel's public root during CI so browser and API requests are genuinely same-origin. This avoids hiding Cookie/CSRF bugs behind a mocked HTTP layer.

## What is deliberately not real

No external commercial provider is contacted by this suite.

- `SMS_DRIVER=e2e` writes OTP data to a temporary private filesystem location under `storage/framework/e2e`.
- `PAYMENT_DRIVER=e2e` produces a deterministic test redirect.
- successful payment is completed through the testing-only Artisan command `e2e:settle-order`, which calls the real `PaymentSettlementService`.

Therefore a green Browser E2E run proves the internal application flow and browser integration; it does **not** prove that SMS.ir or Zarinpal credentials, provider connectivity, callbacks, or production TLS configuration are valid.

Those checks belong to the separate staging-provider validation stage.

## Production safety guards

The E2E SMS and payment gateways are resolved only when Laravel is actually running with `APP_ENV=testing`. Selecting either `e2e` driver in staging or production raises an application configuration error instead of silently enabling a test provider.

`E2ESeeder` and `e2e:settle-order` also refuse to run outside the testing environment. There is no HTTP endpoint that exposes an OTP, bypasses authentication, or marks an order paid for tests.

OTP test files:

- live outside the public web root;
- use a SHA-256 hash of the mobile number in the filename rather than the raw mobile;
- are created in a `0700` directory and written as `0600` files where supported;
- are runtime artifacts ignored by Git;
- are removed/recreated by the CI workflow before each suite.

## Deterministic fixtures

`Database\Seeders\E2ESeeder` is restricted to the testing environment and creates only deterministic browser-test fixtures, including:

- one active product with one purchasable variant;
- deterministic inventory;
- an administrator with the existing `admin` RBAC role.

The customer account is intentionally created through the real OTP flow during the browser test rather than pre-authenticated or injected into browser storage.

## Covered browser journey

The current Chromium suite covers the critical customer journey:

`Product -> Cart -> OTP -> Server Cart -> Address -> Checkout -> Order -> Payment Attempt -> Real Domain Settlement -> Payment Result -> Account Order`

It also covers the management journey:

`Admin OTP -> Permission-protected Dashboard -> Live KPI/Recent Orders -> Customer Directory`

The customer-directory assertion verifies that the customer is visible while the seeded staff account is not exposed as a customer.

## CI dependency policy

Playwright is pinned in `e2e/package.json` and `e2e/package-lock.json`. CI uses `npm ci`, audits npm dependencies at high severity, audits locked Composer dependencies, and installs Chromium with Playwright's documented browser dependency installer.

Failure-only Playwright traces, screenshots/videos, and the Laravel E2E server log are uploaded as short-lived CI artifacts for diagnosis. Local Playwright report/result directories are ignored by Git.

## Local/staging boundary

Do not put real SMS.ir API keys, Zarinpal Merchant IDs, OTP peppers, or other production secrets in E2E fixtures, source files, Playwright configuration, or GitHub workflow YAML.

Real provider validation must use rotated/private credentials supplied through environment or secret storage on a dedicated staging environment.