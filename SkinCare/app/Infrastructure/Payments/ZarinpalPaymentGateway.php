<?php

namespace App\Infrastructure\Payments;

use App\Contracts\PaymentGateway;
use App\Contracts\ReversiblePaymentGateway;
use App\Exceptions\PaymentInitiationUnknownException;
use App\Exceptions\PaymentUnavailableException;
use App\Models\PaymentAttempt;
use App\ValueObjects\Payments\PaymentInitiationResult;
use App\ValueObjects\Payments\PaymentReversalResult;
use App\ValueObjects\Payments\PaymentVerificationResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class ZarinpalPaymentGateway implements PaymentGateway, ReversiblePaymentGateway
{
    private const MAX_AMOUNT_IRR = 1_000_000_000;

    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'zarinpal';
    }

    public function initiate(PaymentAttempt $attempt, string $callbackUrl): PaymentInitiationResult
    {
        $this->assertConfigured();
        $this->assertPayableAmount($attempt->amount_irr);
        $attempt->loadMissing('payment.order.user');
        $order = $attempt->payment?->order;

        if (! $order) {
            throw new PaymentUnavailableException('اطلاعات سفارش برای شروع پرداخت در دسترس نیست.');
        }

        $metadata = array_filter([
            'mobile' => $order->user?->mobile,
            'order_id' => $order->order_number,
        ], static fn ($value): bool => $value !== null && $value !== '');

        try {
            $response = $this->client()->post($this->endpoint('/pg/v4/payment/request.json'), [
                'merchant_id' => $this->merchantId(),
                'amount' => $attempt->amount_irr,
                'currency' => 'IRR',
                'description' => mb_substr('پرداخت سفارش '.$order->order_number, 0, 500),
                'callback_url' => $callbackUrl,
                'metadata' => $metadata,
            ]);
        } catch (ConnectionException $exception) {
            throw new PaymentInitiationUnknownException('نتیجه درخواست شروع پرداخت نامشخص است؛ لطفاً با شناسه جدید دوباره تلاش کنید.', previous: $exception);
        }

        $payload = $this->decodeResponse($response);
        $code = $this->providerCode($payload);

        if ($response->serverError()) {
            throw new PaymentUnavailableException('سرویس زرین‌پال موقتاً در دسترس نیست.');
        }
        if ($code !== 100) {
            throw new PaymentUnavailableException($this->safeProviderMessage($code));
        }

        $authority = (string) data_get($payload, 'data.authority', '');
        if (! $this->validAuthority($authority)) {
            throw new PaymentUnavailableException('پاسخ شروع پرداخت زرین‌پال معتبر نیست.');
        }

        return new PaymentInitiationResult(
            authority: $authority,
            redirectUrl: $this->endpoint('/pg/StartPay/'.$authority),
            metadata: [
                'fee_type' => data_get($payload, 'data.fee_type'),
                'fee' => data_get($payload, 'data.fee'),
            ],
        );
    }

    public function verify(PaymentAttempt $attempt, array $payload): PaymentVerificationResult
    {
        $this->assertConfigured();
        $this->assertPayableAmount($attempt->amount_irr);

        $callbackStatus = strtoupper(trim((string) ($payload['Status'] ?? $payload['status'] ?? '')));
        if ($callbackStatus !== 'OK') {
            return new PaymentVerificationResult(
                successful: false,
                failureCode: 'callback_not_ok',
                failureMessage: 'پرداخت توسط کاربر تکمیل نشده است.',
            );
        }

        if (! $attempt->authority || ! $this->validAuthority($attempt->authority)) {
            return new PaymentVerificationResult(
                successful: false,
                failureCode: 'invalid_authority',
                failureMessage: 'شناسه پرداخت ذخیره‌شده معتبر نیست.',
            );
        }

        $callbackAuthority = trim((string) ($payload['Authority'] ?? $payload['authority'] ?? ''));
        if (! hash_equals($attempt->authority, $callbackAuthority)) {
            return new PaymentVerificationResult(
                successful: false,
                failureCode: 'authority_mismatch',
                failureMessage: 'شناسه پرداخت با درخواست ثبت‌شده سازگار نیست.',
            );
        }

        $response = $this->verifyRequest($attempt);
        $body = $this->decodeResponse($response);
        $code = $this->providerCode($body);

        if (! in_array($code, [100, 101], true)) {
            return new PaymentVerificationResult(
                successful: false,
                failureCode: $code === null ? 'zarinpal_invalid_response' : 'zarinpal_'.$code,
                failureMessage: $this->safeProviderMessage($code),
            );
        }

        $refId = data_get($body, 'data.ref_id');
        if (! is_int($refId) && ! (is_string($refId) && ctype_digit($refId))) {
            throw new PaymentUnavailableException('پاسخ وریفای زرین‌پال فاقد شناسه تراکنش معتبر است.');
        }

        $transactionId = (string) $refId;

        return new PaymentVerificationResult(
            successful: true,
            transactionId: $transactionId,
            eventId: 'zarinpal|'.$attempt->authority.'|'.$transactionId,
            metadata: [
                'authority' => $attempt->authority,
                'ref_id' => $transactionId,
                'card_pan' => data_get($body, 'data.card_pan'),
                'card_hash' => data_get($body, 'data.card_hash'),
                'fee_type' => data_get($body, 'data.fee_type'),
                'fee' => data_get($body, 'data.fee'),
                'already_verified' => $code === 101,
            ],
        );
    }

    public function reverse(PaymentAttempt $attempt): PaymentReversalResult
    {
        $this->assertConfigured();

        if (! $attempt->authority || ! $this->validAuthority($attempt->authority)) {
            return new PaymentReversalResult(false, 'invalid_authority', 'شناسه پرداخت برای ریورس معتبر نیست.');
        }

        try {
            $response = $this->client()->post($this->endpoint('/pg/v4/payment/reverse.json'), [
                'merchant_id' => $this->merchantId(),
                'authority' => $attempt->authority,
            ]);
        } catch (ConnectionException $exception) {
            throw new PaymentUnavailableException('وضعیت ریورس زرین‌پال نامشخص است و باید استعلام شود.', previous: $exception);
        }

        $body = $this->decodeResponse($response);
        $code = $this->providerCode($body);

        return new PaymentReversalResult(
            successful: $code === 100,
            failureCode: $code === 100 ? null : ($code === null ? 'zarinpal_invalid_response' : 'zarinpal_'.$code),
            failureMessage: $code === 100 ? null : $this->safeProviderMessage($code),
            metadata: ['provider_code' => $code],
        );
    }

    private function verifyRequest(PaymentAttempt $attempt): Response
    {
        $lastException = null;
        $attempts = max(1, (int) ($this->config['verify_attempts'] ?? 3));

        for ($try = 1; $try <= $attempts; $try++) {
            try {
                $response = $this->client()->post($this->endpoint('/pg/v4/payment/verify.json'), [
                    'merchant_id' => $this->merchantId(),
                    'amount' => $attempt->amount_irr,
                    'authority' => $attempt->authority,
                ]);

                if (! $response->serverError() || $try === $attempts) {
                    return $response;
                }
            } catch (ConnectionException $exception) {
                $lastException = $exception;
                if ($try === $attempts) {
                    break;
                }
            }

            usleep(150_000 * $try);
        }

        throw new PaymentUnavailableException('تأیید پرداخت زرین‌پال موقتاً در دسترس نیست.', previous: $lastException);
    }

    private function client()
    {
        return Http::acceptJson()
            ->asJson()
            ->connectTimeout(max(1, (int) ($this->config['connect_timeout_seconds'] ?? 3)))
            ->timeout(max(2, (int) ($this->config['timeout_seconds'] ?? 8)));
    }

    private function endpoint(string $path): string
    {
        $base = $this->sandbox()
            ? (string) ($this->config['sandbox_base_url'] ?? 'https://sandbox.zarinpal.com')
            : (string) ($this->config['base_url'] ?? 'https://payment.zarinpal.com');

        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    private function assertConfigured(): void
    {
        if (! preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $this->merchantId())) {
            throw new PaymentUnavailableException('Merchant ID زرین‌پال پیکربندی نشده یا معتبر نیست.');
        }
    }

    private function assertPayableAmount(int $amountIrr): void
    {
        if ($amountIrr <= 0) {
            throw new PaymentUnavailableException('مبلغ پرداخت باید بیشتر از صفر باشد.');
        }
        if ($amountIrr > self::MAX_AMOUNT_IRR) {
            throw new PaymentUnavailableException('مبلغ تراکنش از سقف مجاز زرین‌پال بیشتر است.');
        }
    }

    private function merchantId(): string
    {
        return trim((string) ($this->config['merchant_id'] ?? ''));
    }

    private function sandbox(): bool
    {
        return (bool) ($this->config['sandbox'] ?? false);
    }

    private function validAuthority(string $authority): bool
    {
        $prefix = $this->sandbox() ? 'S' : 'A';

        return (bool) preg_match('/^'.$prefix.'[A-Za-z0-9]{35}$/', $authority);
    }

    private function decodeResponse(Response $response): array
    {
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new PaymentUnavailableException('پاسخ دریافتی از زرین‌پال معتبر نیست.');
        }

        return $payload;
    }

    private function providerCode(array $payload): ?int
    {
        $code = data_get($payload, 'data.code', data_get($payload, 'errors.code'));

        return is_numeric($code) ? (int) $code : null;
    }

    private function safeProviderMessage(?int $code): string
    {
        return match ($code) {
            -9 => 'اطلاعات درخواست پرداخت معتبر نیست.',
            -10 => 'Merchant ID یا IP درگاه زرین‌پال معتبر نیست.',
            -11 => 'درگاه زرین‌پال فعال نیست.',
            -12 => 'تعداد تلاش‌های پرداخت در بازه کوتاه بیش از حد مجاز است.',
            -13 => 'محدودیت تراکنش درگاه زرین‌پال فعال شده است.',
            -14 => 'دامنه Callback با دامنه ثبت‌شده در زرین‌پال سازگار نیست.',
            -15, -16, -17, -19 => 'دسترسی درگاه زرین‌پال برای این پذیرنده محدود شده است.',
            -41 => 'مبلغ تراکنش از سقف مجاز زرین‌پال بیشتر است.',
            -50 => 'مبلغ وریفای با مبلغ تراکنش زرین‌پال سازگار نیست.',
            -51 => 'پرداخت در زرین‌پال موفق نشده است.',
            -52 => 'زرین‌پال در تأیید تراکنش با خطای داخلی مواجه شد.',
            -53 => 'تراکنش متعلق به Merchant ID دیگری است.',
            -54 => 'Authority زرین‌پال معتبر نیست.',
            -60 => 'ریورس تراکنش از سمت بانک امکان‌پذیر نیست.',
            -61 => 'تراکنش برای ریورس در وضعیت مناسب نیست.',
            -62 => 'برای ریورس باید IP سرور در زرین‌پال ثبت شده باشد.',
            -63 => 'مهلت ۳۰ دقیقه‌ای ریورس تراکنش به پایان رسیده است.',
            default => 'زرین‌پال درخواست پرداخت را تأیید نکرد.',
        };
    }
}
