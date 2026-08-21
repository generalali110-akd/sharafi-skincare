# Zarinpal payment gateway integration

This adapter is based on the current official Zarinpal payment-gateway documentation reviewed on 2026-08-21.

## Production contract

- Provider name: `zarinpal`
- Canonical shop currency sent to Zarinpal: `IRR`
- Payment request: `POST /pg/v4/payment/request.json`
- Buyer redirect: `/pg/StartPay/{authority}`
- Verification: `POST /pg/v4/payment/verify.json`
- Inquiry is status-only and must never replace verification.
- Successful verification code `100` means newly verified.
- Verification code `101` means the same successful transaction was already verified and is treated idempotently.
- Callback query values are not trusted. `Status=OK` is only a signal to perform server-to-server verification; it never marks an order paid by itself.
- Authority values are validated as the documented 36-character provider token before any callback lookup or verification request.
- The adapter rejects zero/negative amounts and amounts above `1,000,000,000 IRR` before network I/O, matching the provider limit documented for error `-41` at review time.

Production service base URL is `https://payment.zarinpal.com`. Sandbox replaces that host with `https://sandbox.zarinpal.com`; sandbox authorities start with `S` while production authorities start with `A`.

## Configuration

Keep secrets outside Git:

```dotenv
PAYMENT_DRIVER=zarinpal
PAYMENT_CALLBACK_URL=https://api.example.com/api/v1/payments/zarinpal/callback
PAYMENT_RESULT_URL=https://shop.example.com/payment-result
ZARINPAL_MERCHANT_ID=00000000-0000-0000-0000-000000000000
ZARINPAL_SANDBOX=false
ZARINPAL_CONNECT_TIMEOUT_SECONDS=3
ZARINPAL_TIMEOUT_SECONDS=8
ZARINPAL_VERIFY_ATTEMPTS=3
```

Production callback and result URLs must use HTTPS. The callback domain must match the terminal domain registered with Zarinpal or provider error `-14` is expected.

## Failure and retry rules

Payment initiation is not automatically retried after an ambiguous transport failure because the provider may have received the POST even when the response was lost. Such an attempt remains `created`/unknown and a fresh idempotency key is required for a new initiation.

Verification is safe to retry because Zarinpal documents repeat verification as code `101`. The adapter retries only transient connection/server failures and never trusts the browser callback as proof of payment.

`Status=NOK` is intentionally not persisted as a final failed attempt. The callback query string is user-controlled; allowing it to mutate the attempt would let an attacker race a valid payment callback and block later settlement.

Authoritative server-side verification failures are different: they mark the local attempt and payment as failed for customer support and payment-result visibility. Retrying after such a failure requires a fresh payment-attempt idempotency key.

## Settlement integrity

After Zarinpal verification succeeds, the application still verifies its own invariants before settlement:

- PaymentAttempt amount equals Payment amount.
- Payment amount equals immutable Order total.
- Authority belongs to the stored attempt.
- The provider transaction reference is stable.
- Replayed callback events are deduplicated.
- Stock reservation is converted to physical sale exactly once inside the existing database transaction.
- Coupon reservation is consumed only after verified settlement.

## Reverse versus refund

Zarinpal's V4 `payment/reverse.json` is implemented as the `ReversiblePaymentGateway` capability. Official documentation limits reverse to successful transactions within 30 minutes and requires the server IP to be registered for the terminal.

This is not the same as Zarinpal's general refund service. The current official SDK exposes general full/partial refunds as a separate GraphQL `AddRefund` operation. That flow requires an access token and a provider `session_id`, plus the refund amount and optional method/reason fields.

The application therefore uses a separate `RefundablePaymentGateway` contract for general refunds. `ReversiblePaymentGateway` does not satisfy that contract, and the Zarinpal REST reverse endpoint is never treated as proof that a general refund completed.

## Current operational decision

Zarinpal remains the selected payment provider for local/staging preparation. The backend supports sandbox and production payment request/verification through environment configuration.

General Zarinpal refund completion intentionally remains fail-closed until the provider-specific GraphQL access token and stable session-ID mapping are available and validated. Admins may move an eligible paid order into `refund_pending`, but the application will not move order/payment state to `refunded` unless the active payment adapter implements `RefundablePaymentGateway` and returns a successful provider-backed result.

This preserves financial correctness: no local status, operator note, or REST reversal response may be used to claim a general refund that the provider has not durably accepted or completed.
