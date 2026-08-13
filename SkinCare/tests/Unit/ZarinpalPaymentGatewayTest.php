<?php

namespace Tests\Unit;

use App\Exceptions\PaymentUnavailableException;
use App\Infrastructure\Payments\ZarinpalPaymentGateway;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZarinpalPaymentGatewayTest extends TestCase
{
    private const MERCHANT_ID = '11111111-2222-3333-4444-555555555555';

    public function test_sandbox_initiation_uses_official_v4_endpoint_and_irr_currency(): void
    {
        $authority = 'S'.str_repeat('A', 35);
        Http::fake([
            'https://sandbox.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => [
                    'code' => 100,
                    'message' => 'Success',
                    'authority' => $authority,
                    'fee_type' => 'Merchant',
                    'fee' => 100,
                ],
                'errors' => [],
            ]),
        ]);

        $gateway = $this->gateway(sandbox: true);
        $result = $gateway->initiate($this->attempt(1_250_000), 'https://shop.test/api/v1/payments/zarinpal/callback');

        $this->assertSame($authority, $result->authority);
        $this->assertSame('https://sandbox.zarinpal.com/pg/StartPay/'.$authority, $result->redirectUrl);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://sandbox.zarinpal.com/pg/v4/payment/request.json'
                && $request['merchant_id'] === self::MERCHANT_ID
                && $request['amount'] === 1_250_000
                && $request['currency'] === 'IRR'
                && $request['metadata']['mobile'] === '09121234567'
                && $request['metadata']['order_id'] === '01TESTORDER000000000000000001';
        });
    }

    public function test_verify_accepts_codes_100_and_101_as_the_same_successful_transaction(): void
    {
        $authority = 'A'.str_repeat('B', 35);
        $attempt = $this->attempt(2_000_000, $authority);

        Http::fakeSequence()
            ->push([
                'data' => [
                    'code' => 100,
                    'message' => 'Verified',
                    'ref_id' => 987654,
                    'card_pan' => '502229******5995',
                    'card_hash' => str_repeat('A', 64),
                    'fee_type' => 'Merchant',
                    'fee' => 0,
                ],
                'errors' => [],
            ])
            ->push([
                'data' => [
                    'code' => 101,
                    'message' => 'Verified',
                    'ref_id' => 987654,
                    'card_pan' => '502229******5995',
                    'card_hash' => str_repeat('A', 64),
                    'fee_type' => 'Merchant',
                    'fee' => 0,
                ],
                'errors' => [],
            ]);

        $gateway = $this->gateway();
        $first = $gateway->verify($attempt, ['Authority' => $authority, 'Status' => 'OK']);
        $second = $gateway->verify($attempt, ['Authority' => $authority, 'Status' => 'OK']);

        $this->assertTrue($first->successful);
        $this->assertTrue($second->successful);
        $this->assertSame('987654', $first->transactionId);
        $this->assertSame($first->eventId, $second->eventId);
        $this->assertFalse($first->metadata['already_verified']);
        $this->assertTrue($second->metadata['already_verified']);
    }

    public function test_nok_callback_is_not_verified_against_provider(): void
    {
        Http::preventStrayRequests();
        $authority = 'A'.str_repeat('C', 35);
        $result = $this->gateway()->verify(
            $this->attempt(500_000, $authority),
            ['Authority' => $authority, 'Status' => 'NOK'],
        );

        $this->assertFalse($result->successful);
        $this->assertSame('callback_not_ok', $result->failureCode);
        Http::assertNothingSent();
    }

    public function test_authority_mismatch_is_rejected_without_provider_request(): void
    {
        Http::preventStrayRequests();
        $attempt = $this->attempt(500_000, 'A'.str_repeat('D', 35));
        $result = $this->gateway()->verify(
            $attempt,
            ['Authority' => 'A'.str_repeat('E', 35), 'Status' => 'OK'],
        );

        $this->assertFalse($result->successful);
        $this->assertSame('authority_mismatch', $result->failureCode);
        Http::assertNothingSent();
    }

    public function test_malformed_stored_authority_is_rejected_without_provider_request(): void
    {
        Http::preventStrayRequests();
        $authority = 'A'.str_repeat('D', 34);
        $result = $this->gateway()->verify(
            $this->attempt(500_000, $authority),
            ['Authority' => $authority, 'Status' => 'OK'],
        );

        $this->assertFalse($result->successful);
        $this->assertSame('invalid_authority', $result->failureCode);
        Http::assertNothingSent();
    }

    public function test_zero_amount_is_rejected_before_provider_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(PaymentUnavailableException::class);
        $this->expectExceptionMessage('مبلغ پرداخت باید بیشتر از صفر باشد.');
        $this->gateway()->initiate($this->attempt(0), 'https://shop.test/callback');
    }

    public function test_amount_above_zarinpal_limit_is_rejected_before_provider_request(): void
    {
        Http::preventStrayRequests();

        $this->expectException(PaymentUnavailableException::class);
        $this->expectExceptionMessage('مبلغ تراکنش از سقف مجاز زرین‌پال بیشتر است.');
        $this->gateway()->initiate($this->attempt(1_000_000_001), 'https://shop.test/callback');
    }

    public function test_provider_validation_errors_are_mapped_to_safe_messages(): void
    {
        Http::fake([
            'https://payment.zarinpal.com/pg/v4/payment/request.json' => Http::response([
                'data' => [],
                'errors' => [
                    'code' => -14,
                    'message' => 'The callback URL domain does not match the registered terminal domain.',
                ],
            ], 422),
        ]);

        $this->expectException(PaymentUnavailableException::class);
        $this->expectExceptionMessage('دامنه Callback با دامنه ثبت‌شده در زرین‌پال سازگار نیست.');
        $this->gateway()->initiate($this->attempt(1_000_000), 'https://wrong.example.test/callback');
    }

    public function test_reverse_uses_official_v4_reverse_endpoint(): void
    {
        $authority = 'A'.str_repeat('F', 35);
        Http::fake([
            'https://payment.zarinpal.com/pg/v4/payment/reverse.json' => Http::response([
                'data' => ['code' => 100, 'message' => 'Reversed'],
                'errors' => [],
            ]),
        ]);

        $result = $this->gateway()->reverse($this->attempt(1_000_000, $authority));

        $this->assertTrue($result->successful);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://payment.zarinpal.com/pg/v4/payment/reverse.json'
            && $request['merchant_id'] === self::MERCHANT_ID
            && $request['authority'] === $authority);
    }

    public function test_invalid_merchant_id_fails_closed_without_network_request(): void
    {
        Http::preventStrayRequests();
        $gateway = new ZarinpalPaymentGateway([
            'merchant_id' => 'invalid',
            'sandbox' => false,
        ]);

        $this->expectException(PaymentUnavailableException::class);
        $gateway->initiate($this->attempt(1_000_000), 'https://shop.test/callback');
    }

    private function gateway(bool $sandbox = false): ZarinpalPaymentGateway
    {
        return new ZarinpalPaymentGateway([
            'merchant_id' => self::MERCHANT_ID,
            'sandbox' => $sandbox,
            'base_url' => 'https://payment.zarinpal.com',
            'sandbox_base_url' => 'https://sandbox.zarinpal.com',
            'connect_timeout_seconds' => 1,
            'timeout_seconds' => 2,
            'verify_attempts' => 2,
        ]);
    }

    private function attempt(int $amount, ?string $authority = null): PaymentAttempt
    {
        $user = new User;
        $user->mobile = '09121234567';

        $order = new Order;
        $order->order_number = '01TESTORDER000000000000000001';
        $order->setRelation('user', $user);

        $payment = new Payment;
        $payment->setRelation('order', $order);

        $attempt = new PaymentAttempt;
        $attempt->amount_irr = $amount;
        $attempt->authority = $authority;
        $attempt->setRelation('payment', $payment);

        return $attempt;
    }
}
