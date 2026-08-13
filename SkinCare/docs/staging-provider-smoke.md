# Staging provider smoke runbook

This runbook validates SMS.ir and Zarinpal without committing provider credentials.

## Safety boundary

- Provider secrets live only in staging environment/secret storage.
- Never paste SMS.ir API keys, OTP pepper, database passwords, or payment credentials into repository files or CI logs.
- The previously exposed SMS.ir key must not be reused; rotate it before any live validation.
- `ops:provider-readiness --probe-smsir` is read-only: it calls SMS.ir credit and line-list endpoints and does not send an SMS.
- `ops:zarinpal-smoke` is staging/testing-only. It creates a real, recorded payment attempt for an existing pending-payment staging order, but it does not mark the order paid.
- Payment success is accepted only through the normal Zarinpal callback + server-to-server verify + `PaymentSettlementService` path.

## Required staging configuration

Start from `.env.staging.example` and inject real values through the deployment secret store.

Required values include:

- `APP_URL` using public HTTPS
- `OTP_PEPPER`
- `SMSIR_API_KEY`
- `SMSIR_LINE_NUMBER`
- `ZARINPAL_MERCHANT_ID`
- PostgreSQL credentials

For safe provider testing, keep `SMSIR_SANDBOX=true` and `ZARINPAL_SANDBOX=true` until a deliberate live-provider test is scheduled.

## Readiness check

```bash
php artisan ops:provider-readiness
```

Read-only SMS.ir credential/connectivity probe:

```bash
php artisan ops:provider-readiness --probe-smsir
```

Machine-readable mode:

```bash
php artisan ops:provider-readiness --probe-smsir --json
```

The command checks HTTPS, secure/encrypted sessions, active provider drivers, SMS.ir key/template/line configuration, Zarinpal Merchant ID format, payment result/callback HTTPS URLs, and the active Zarinpal base URL.

The SMS.ir probe additionally calls:

- `GET https://api.sms.ir/v1/credit`
- `GET https://api.sms.ir/v1/line`

No account balance, API key, or full secret value is printed by the command.

## Zarinpal initiation smoke

Create a normal staging order through the Storefront and leave it in `pending_payment` with an active reservation. Then run:

```bash
php artisan ops:zarinpal-smoke SHR-ORDER-NUMBER
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

## Manual GitHub gate

`.github/workflows/staging-provider-smoke.yml` is `workflow_dispatch` only. It uses the protected GitHub `staging` environment and never runs on pull-request code with secrets.

Configure these GitHub environment values before triggering it:

Secrets:

- `SMSIR_API_KEY`
- `ZARINPAL_MERCHANT_ID`

Variables:

- `STAGING_APP_URL`
- `SMSIR_SANDBOX`
- `SMSIR_OTP_TEMPLATE_ID`
- `SMSIR_OTP_CODE_PARAMETER`
- `SMSIR_LINE_NUMBER`
- `ZARINPAL_SANDBOX`

The workflow runs a Composer audit and the read-only readiness/SMS.ir provider probe. Real Zarinpal initiation remains an explicit staging-order operation so every generated authority is recorded in the application database.
