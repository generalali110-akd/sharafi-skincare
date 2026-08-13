# Zarinpal payment gateway integration

This adapter is based on the current official Zarinpal payment-gateway documentation reviewed on 2026-08-13.

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

This is not the same as Zarinpal's general refund service. General full/partial refund is documented through the authenticated Zarinpal GraphQL API, requires OAuth 2.0 credentials/access tokens and transaction/terminal identifiers, and supports PAYA or CARD refund methods. That OAuth refund flow is deliberately not faked or inferred from Merchant ID authentication; it will be implemented as a separate audited refund slice when credentials and operational policy are available.
