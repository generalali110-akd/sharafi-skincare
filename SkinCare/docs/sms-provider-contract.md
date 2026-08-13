# SMS Provider Contract

The application domain depends only on `App\Contracts\SmsGateway`. Provider-specific credentials, endpoints, templates, and sending lines belong to infrastructure/configuration and must not leak into OTP, orders, payments, or the transactional outbox.

## Current provider support

- `null` — default fail-closed provider; never sends externally.
- `smsir` — SMS.ir REST adapter for OTP Verify and plain transactional messages.

`SMS_DRIVER` remains `null` by default. Production or staging must opt in explicitly after secrets are provisioned.

## SMS.ir official API contract used by the adapter

Official documentation: `https://sms.ir/rest-api/`

Authentication:

- header: `X-API-KEY`
- production and Sandbox use the same API URLs; the API key determines the environment.

OTP / high-priority Verify:

- `POST https://api.sms.ir/v1/send/verify`
- request fields: `mobile`, `templateId`, `parameters`
- success requires HTTP 2xx, provider `status = 1`, and a positive `data.messageId`.
- Sandbox has a built-in Verify template ID `123456` with parameter `CODE`.

Plain transactional message:

- `POST https://api.sms.ir/v1/send/bulk`
- request fields: `lineNumber`, `messageText`, `mobiles`, `sendDateTime`
- this uses the configured SMS.ir sending line.
- a destination result of message ID `0` is treated as a permanent failure for that line (for example blacklist/service-line restrictions).

## Environment configuration

```dotenv
SMS_DRIVER=smsir
SMSIR_API_KEY=secret-from-server-secret-store
SMSIR_SANDBOX=true
SMSIR_OTP_TEMPLATE_ID=
SMSIR_OTP_CODE_PARAMETER=CODE
SMSIR_LINE_NUMBER=
SMSIR_CONNECT_TIMEOUT_SECONDS=3
SMSIR_TIMEOUT_SECONDS=8
SMSIR_MAX_MESSAGE_CHARS=320
```

For SMS.ir Sandbox, `SMSIR_OTP_TEMPLATE_ID` may remain empty and the adapter uses the official built-in template `123456`. For Production, an explicit approved template ID is required.

`SMSIR_LINE_NUMBER` is required only for plain `sendMessage` calls used by the order notification outbox. If a future customer uses provider-side transactional templates instead, that mapping belongs in the provider adapter/configuration rather than the order domain.

## Error and retry policy

Provider/network failures are intentionally classified:

- transient: connection failures, server failures, provider rate limiting, temporary SMS.ir service errors, and insufficient credit. Transactional outbox messages remain retryable with bounded exponential backoff.
- permanent: invalid/inactive API credentials, IP restriction mismatch, invalid sending line, invalid destination, missing/invalid template or parameters, blacklist rejection for the configured line, unsupported plan/template, and line activation errors. The outbox marks these failed immediately instead of hammering the provider.

Raw provider response bodies, API keys, and secret details must never be persisted in `outbox_messages.last_error`, audit logs, application responses, or analytics.

## OTP security boundary

OTP delivery remains synchronous. The plaintext OTP exists only long enough to call the provider and is never persisted in queues, logs, audit records, or the database. The database stores only the HMAC hash of the OTP challenge.

Blind provider failover is intentionally not used for OTP or outbox delivery. A timeout can mean the provider accepted the request but the response was lost; immediately resending through another provider can create duplicate messages.

## Transactional outbox boundary

Order notifications are enqueued transactionally with the order/payment state change and dispatched after commit. Delivery is **at least once** because SMS.ir does not document an idempotency-key field for Verify/Bulk requests. The stable outbox `event_key` remains part of the `SmsGateway` contract so a future provider with server-side idempotency can use it without changing business logic.

## Switching the customer's final SMS provider

Changing the final provider should require only:

1. a new `SmsGateway` adapter (if the provider is not already supported),
2. provider-specific environment/configuration values,
3. template/pattern/line mapping,
4. adapter contract tests.

OTP domain logic, order/payment services, outbox schema, API routes, and frontend contracts must remain unchanged.
