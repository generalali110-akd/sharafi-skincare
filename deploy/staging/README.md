# Sharafi staging deployment

This stack is intended for a single staging host. It serves the static Storefront/Admin and Laravel API from the same HTTPS origin.

## Host prerequisites

- a Linux host with current Docker Engine and Docker Compose v2
- a public DNS A/AAAA record for the staging hostname pointing at the host
- inbound TCP 80/443 and UDP 443 available to Caddy
- outbound HTTPS available for SMS.ir, Zarinpal, Composer/registry access during builds, and ACME certificate issuance
- `curl` installed on the host for the final health check

Caddy provisions and renews HTTPS certificates automatically after DNS and ports are correct.

## Private environment

Create the untracked runtime file from the template:

```bash
cp SkinCare/.env.staging.example SkinCare/.env.staging
chmod 600 SkinCare/.env.staging
```

Set unique/private values for at least:

- `APP_KEY`
- `DB_PASSWORD`
- `OTP_PEPPER`
- rotated `SMSIR_API_KEY`
- `SMSIR_LINE_NUMBER`
- `ZARINPAL_MERCHANT_ID`
- `STAGING_DOMAIN`
- `APP_URL`
- `PAYMENT_CALLBACK_URL`
- `PAYMENT_RESULT_URL`
- `SANCTUM_STATEFUL_DOMAINS`
- `CORS_ALLOWED_ORIGINS`
- `ACME_EMAIL`

Never commit `SkinCare/.env.staging`. It is explicitly Git-ignored.

Keep SMS.ir and Zarinpal in sandbox mode until their staging smoke tests have passed.

## Deploy

```bash
sh deploy/staging/deploy.sh
```

The script validates the Compose model, builds pinned PHP/Caddy images, starts the private PostgreSQL service, runs migrations before exposing the new application containers, starts web/app/queue/scheduler, runs provider/security readiness, and verifies the public HTTPS health endpoint.

## Services

- `web`: Caddy TLS termination, compression, static Storefront/Admin, PHP FastCGI routing
- `app`: PHP 8.5 FPM Laravel API
- `queue`: database queue worker
- `scheduler`: Laravel scheduler worker
- `db`: PostgreSQL 18, not published to the host network

Persistent named volumes hold PostgreSQL data, Laravel storage, and Caddy certificate state.

## Post-deploy provider checks

Read-only configuration/SMS.ir probe:

```bash
docker compose --env-file SkinCare/.env.staging -f deploy/staging/compose.yml exec -T app \
  php artisan ops:provider-readiness --probe-smsir
```

Zarinpal initiation must use a real pending-payment staging order so the generated authority is recorded in the normal payment tables:

```bash
docker compose --env-file SkinCare/.env.staging -f deploy/staging/compose.yml exec -T app \
  php artisan ops:zarinpal-smoke SHR-ORDER-NUMBER
```

Payment completion is never forced by deployment tooling. The normal public callback, provider verification, amount validation, settlement service, state machine, and inventory transaction remain authoritative.
