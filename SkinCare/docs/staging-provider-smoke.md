# Staging provider smoke runbook

This runbook validates SMS.ir and Zarinpal without committing provider credentials and runs the decisive smoke commands inside the deployed staging host.

## Safety boundary

- Provider secrets live only in staging environment/secret storage.
- Never paste SMS.ir API keys, OTP pepper, database passwords, payment credentials, SSH private keys, or backup identities into repository files or CI logs.
- `ops:provider-readiness --probe-smsir` is read-only: it calls SMS.ir credit and line-list endpoints and does not send an SMS.
- `ops:zarinpal-smoke` is staging/testing-only. It creates a real, recorded payment attempt for an existing pending-payment staging order, but it does not mark the order paid.
- Payment success is accepted only through the normal Zarinpal callback + server-to-server verify + `PaymentSettlementService` path.
- The GitHub workflow pins the staging SSH host key instead of disabling host-key verification.

## Required application configuration

Start from `.env.staging.example` and inject real values through the deployment secret store.

Required values include:

- `APP_URL` using public HTTPS
- `APP_KEY`
- `OTP_PEPPER`
- `SMSIR_API_KEY`
- `SMSIR_LINE_NUMBER`
- `ZARINPAL_MERCHANT_ID`
- PostgreSQL credentials
- `BACKUP_AGE_RECIPIENT` containing only the public age recipient
- `PAYMENT_CALLBACK_URL` ending in `/api/v1/payments/zarinpal/callback`
- `PAYMENT_RESULT_URL` pointing at the deployed Storefront result page
- `SANCTUM_STATEFUL_DOMAINS` and `CORS_ALLOWED_ORIGINS` matching the staging host

For safe provider testing, keep `SMSIR_SANDBOX=true` and `ZARINPAL_SANDBOX=true` until a deliberate live-provider test is scheduled.

Before the first deploy, confirm `SkinCare/.env.staging` is untracked and readable only by the deployment user. The template is safe to commit; the resolved staging environment file is not.

## Readiness check on the staging host

```bash
php artisan ops:provider-readiness
```

Read-only SMS.ir credential/connectivity probe:

```bash
php artisan ops:provider-readiness --probe-smsir --json
```

Runtime health gate:

```bash
php artisan ops:runtime-health --json
```

The readiness command checks HTTPS, secure/encrypted sessions, active provider drivers, SMS.ir key/template/line configuration, Zarinpal Merchant ID format, payment result/callback HTTPS URLs, and the active Zarinpal base URL.

The SMS.ir probe additionally calls:

- `GET https://api.sms.ir/v1/credit`
- `GET https://api.sms.ir/v1/line`

No account balance, API key, or full secret value is printed by the command.

The runtime health gate must be green before provider smoke results are treated as release evidence. A stale scheduler heartbeat, failed outbox messages, or aged pending outbox item means the staging deployment still needs operational follow-up even if provider credentials are valid.

## Zarinpal initiation smoke

Create a normal staging order through the Storefront and leave it in `pending_payment` with an active reservation. Then run inside the deployed application container:

```bash
php artisan ops:zarinpal-smoke SHR-ORDER-NUMBER --json
```

The command uses the production `PaymentService`, creates a persisted `PaymentAttempt`, and returns the provider redirect URL. Open that URL to continue the sandbox checkout.

The command refuses to run outside `staging` or `testing` and refuses to run when `PAYMENT_DRIVER` is not `zarinpal`.

## Callback/verify validation

After completing the sandbox checkout, Zarinpal must return to:

```text
https://<staging-host>/api/v1/payments/zarinpal/callback
```

The application must then:

1. validate callback `Status` and `Authority`,
2. verify the transaction server-to-server with Zarinpal,
3. compare the verified amount with the immutable order/payment amount,
4. settle inventory exactly once,
5. transition the order through the existing state machine,
6. redirect to the configured HTTPS result page,
7. show the final state by re-reading server data rather than trusting callback query parameters.

Repeated callbacks must remain idempotent and must not decrement inventory twice.

## GitHub staging environment gate

`.github/workflows/staging-provider-smoke.yml` is `workflow_dispatch` only. It does not copy provider credentials to a generic runner. Instead it connects to the deployed staging host using a pinned SSH host key and executes the application commands in the existing container.

Create a protected GitHub environment named `staging` and configure:

Secrets:

- `STAGING_SSH_HOST`
- `STAGING_SSH_USER`
- `STAGING_SSH_PRIVATE_KEY`
- `STAGING_SSH_HOST_KEY` (the exact known_hosts line for the staging host)

Variables:

- `STAGING_DEPLOY_PATH` (absolute repository path on the host, for example `/srv/sharafi-skincare`)

Trigger the workflow with an existing pending-payment staging order number. The workflow runs, in order:

1. the read-only SMS.ir credential/connectivity probe,
2. a real persisted Zarinpal initiation for the supplied order,
3. the internal runtime-health gate.

The resulting Zarinpal redirect still requires the controlled operator to complete the sandbox payment and confirm the normal callback/verify/result flow.
