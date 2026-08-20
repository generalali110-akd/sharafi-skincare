# Production deployment

This directory is intentionally separate from `deploy/staging`. Production uses its own Compose project, environment file, database volume, application storage, encrypted backup volume, Caddy state and immutable image tag.

## Preconditions

1. Provision a Linux host with Docker Engine + Compose v2.
2. Point the production DNS name to the host and allow TCP 80/443 plus UDP 443 when HTTP/3 is desired.
3. Copy `SkinCare/.env.production.example` to `SkinCare/.env.production` on the host. Never commit the real file.
4. Set permissions to `600` (or `400`).
5. Replace every placeholder/empty secret: `APP_KEY`, database password, OTP pepper, SMS.ir values, Zarinpal merchant id and `BACKUP_AGE_RECIPIENT`.
6. Set `IMAGE_TAG` to an immutable release identifier, preferably the exact Git commit SHA. `latest`, `production` and `staging` are rejected.
7. Keep the age private identity off the application host and test restore access from the designated recovery environment.

Production deploy is fail-closed for `APP_DEBUG`, secure/encrypted sessions, provider sandbox flags and encrypted backup readiness.

## Deploy

```sh
chmod 600 SkinCare/.env.production
./deploy/production/deploy.sh
```

The deploy script validates configuration, builds immutable-tagged images, starts the private PostgreSQL service, creates an encrypted pre-migration backup, enters maintenance mode, runs migrations, starts app/queue/scheduler/backup/web, validates runtime/provider readiness, leaves maintenance mode and executes the HTTPS/security smoke test.

A failed deploy prints service state/logs and the previous successful image tag when one is recorded. It does **not** automatically reverse database migrations.

## Rollback

For a code/runtime rollback where the deployed migrations are backward-compatible:

```sh
./deploy/production/rollback.sh <previous-image-tag>
```

The rollback script requires all three previous images to still exist locally and creates another encrypted database backup before switching images. It deliberately does not execute `migrate:rollback`.

If the migration is not backward-compatible, stop and follow the database restore runbook in `deploy/backup/README.md`; select the known-good encrypted backup and validate it before restoring. Do not combine an old application image with an incompatible newer schema.

## Smoke test only

```sh
PRODUCTION_APP_URL=https://shop.example.com ./deploy/production/http-smoke.sh
```

The smoke test checks HTTPS, HTTP→HTTPS redirect, HSTS, CSP and baseline security headers, unauthenticated API behavior, CSRF cookie security attributes and trusted/untrusted CORS behavior. Provider transactions are intentionally outside this script.

## Release state

Successful deploys record only image tag identifiers under `.deploy-state/production/` by default. That directory is local runtime state and is ignored by Git. Override it with `SHARAFI_RELEASE_STATE_DIR` when host policy requires state under `/var/lib` or another protected path.

## Operational gates not performed by repository code

Real DNS/HTTPS issuance, real SMS.ir delivery, Zarinpal sandbox/production transaction verification, external monitoring integration, customer UAT and the actual production deployment require authorized infrastructure/provider access. The repository must not claim those gates have passed until evidence exists.
