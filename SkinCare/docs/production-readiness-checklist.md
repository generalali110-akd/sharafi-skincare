# Production readiness checklist

Use this as the final release gate after staging has already been deployed and smoke-tested. Do not treat this checklist as a substitute for a successful staging run.

## 1. Source and build gate

- [ ] Release branch is reviewed and contains no uncommitted local-only runtime files.
- [ ] `SkinCare/.env.staging`, `SkinCare/.env.production`, database dumps, backup identities, provider secrets, and generated storage artifacts are not tracked by Git.
- [ ] Backend CI is green: Composer audit, migrations, Pint, feature/unit tests, and PostgreSQL compatibility.
- [ ] Frontend/static CI is green: CSP/static checks, Playwright commerce/auth flows, and visual/performance gates where applicable.
- [ ] Docker image tag or commit SHA for deployment is recorded before deploy.

## 2. Environment and secrets

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` uses the final HTTPS shop origin, and `APP_KEY` is unique to production.
- [ ] `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_SAME_SITE=lax`, and cookie domain/path match the public origin.
- [ ] `SANCTUM_STATEFUL_DOMAINS` includes the final shop host and `CORS_ALLOWED_ORIGINS` includes only approved HTTPS origins.
- [ ] PostgreSQL credentials are production-only, rotated, and not reused from staging.
- [ ] `OTP_PEPPER`, `SMSIR_API_KEY`, `ZARINPAL_MERCHANT_ID`, backup public recipient, SSH keys, and ACME email are provisioned through secret storage.
- [ ] Provider sandbox flags are intentionally set for the chosen cutover mode: sandbox for rehearsal, production only for approved launch.

## 3. Payment and SMS providers

- [ ] `php artisan ops:provider-readiness --json` is green with production values.
- [ ] If SMS.ir is enabled, `php artisan ops:provider-readiness --probe-smsir --json` is green from the deployed runtime.
- [ ] Zarinpal terminal domain matches `PAYMENT_CALLBACK_URL` and `PAYMENT_RESULT_URL`.
- [ ] A controlled provider smoke validates payment initiation, callback, server-side verify, idempotent repeated callback, and payment result rendering.
- [ ] SMS outbox retry policy is accepted operationally: max attempts, lock TTL, backoff, notification expiry, and failed-message alert threshold.
- [ ] Refund policy is operationally clear: current automatic reverse is limited to Zarinpal's supported reverse window; general refund automation is a future audited slice.

## 4. Data, jobs, and scheduler

- [ ] `php artisan migrate --force` has completed on the target database.
- [ ] `php artisan db:seed --class=SystemAccessSeeder --force` has run or equivalent roles/permissions already exist.
- [ ] Queue worker is running and using the intended driver.
- [ ] Scheduler is running, `ops:scheduler-heartbeat` is fresh, and `orders:expire-reservations` plus `outbox:dispatch-sms` are scheduled.
- [ ] `php artisan ops:runtime-health --json` is green: database, queue backlog, failed jobs, failed outbox, outbox age, and scheduler heartbeat.
- [ ] No pending-payment reservation older than the configured TTL remains unreleased.

## 5. Security and HTTP boundary

- [ ] HTTPS certificate is active and HTTP redirects to HTTPS.
- [ ] HSTS and security headers are present.
- [ ] Sensitive API responses are not cacheable by shared caches.
- [ ] `/sanctum/csrf-cookie` sets expected secure cookies for the production origin.
- [ ] Credentialed CORS succeeds only for approved origins and rejects untrusted origins.
- [ ] Admin API access requires authenticated users with explicit roles; no default admin account exists.
- [ ] Upload, storage, and log paths are writable only where intended and do not expose executable files publicly.

## 6. Backup, observability, and rollback

- [ ] Encrypted database backup job has run successfully and restore has been tested in a non-production environment.
- [ ] Backup retention and off-host copy policy are confirmed.
- [ ] Application logs, queue failures, outbox failures, payment failures, and HTTP 5xx rates are visible to operators.
- [ ] Rollback target image/commit is known and compatible with the current migration state.
- [ ] DNS/certificate rollback implications are understood before launch.
- [ ] A launch operator is assigned to watch runtime health, provider callbacks, and first orders during the cutover window.

## 7. Launch decision

- [ ] Staging HTTP smoke is green.
- [ ] Staging provider smoke is green.
- [ ] Runtime health is green after the latest deployment.
- [ ] Business owner approves final shipping, discount, reservation, SMS, payment, refund, and support policies.
- [ ] Production cutover time and rollback owner are written down.
