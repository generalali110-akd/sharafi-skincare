<?php

namespace Tests\Feature\Infrastructure;

use App\Services\Operations\ProviderReadinessService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderReadinessTest extends TestCase
{
    public function test_readiness_passes_for_complete_https_provider_configuration(): void
    {
        $this->configureReadyProviders();

        $result = app(ProviderReadinessService::class)->inspect();

        $this->assertTrue($result['ok']);
        $this->assertNotEmpty($result['checks']);
    }

    public function test_readiness_fails_closed_for_missing_sensitive_provider_configuration(): void
    {
        $this->configureReadyProviders();
        config([
            'sms.smsir.api_key' => '',
            'payment.zarinpal.merchant_id' => '',
            'payment.callback_url' => 'http://staging.example.test/callback',
        ]);

        $result = app(ProviderReadinessService::class)->inspect();
        $failed = collect($result['checks'])->where('ok', false)->pluck('name')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('smsir.api_key', $failed);
        $this->assertContains('zarinpal.merchant_id', $failed);
        $this->assertContains('payment.callback_https', $failed);
    }

    public function test_readiness_rejects_mismatched_browser_and_payment_hosts(): void
    {
        $this->configureReadyProviders();
        config([
            'payment.result_url' => 'https://payments.example.test/payment-result.html',
            'sanctum.stateful' => ['admin.example.test'],
            'cors.allowed_origins' => ['https://admin.example.test'],
        ]);

        $result = app(ProviderReadinessService::class)->inspect();
        $failed = collect($result['checks'])->where('ok', false)->pluck('name')->all();

        $this->assertFalse($result['ok']);
        $this->assertContains('payment.result_host', $failed);
        $this->assertContains('sanctum.stateful_domains', $failed);
        $this->assertContains('cors.allowed_origins', $failed);
    }

    public function test_smsir_probe_is_read_only_and_verifies_account_and_configured_line(): void
    {
        $this->configureReadyProviders();

        Http::fake([
            'https://api.sms.ir/v1/credit' => Http::response([
                'status' => 1,
                'message' => 'ok',
                'data' => 100.5,
            ]),
            'https://api.sms.ir/v1/line' => Http::response([
                'status' => 1,
                'message' => 'ok',
                'data' => [30004505000017],
            ]),
        ]);

        $result = app(ProviderReadinessService::class)->inspect(true);

        $this->assertTrue($result['ok']);
        Http::assertSentCount(2);
        Http::assertSent(static function (Request $request): bool {
            return $request->method() === 'GET'
                && in_array($request->url(), [
                    'https://api.sms.ir/v1/credit',
                    'https://api.sms.ir/v1/line',
                ], true)
                && $request->hasHeader('X-API-KEY', 'sandbox-key');
        });
    }

    public function test_smsir_probe_rejects_a_line_not_owned_by_the_account(): void
    {
        $this->configureReadyProviders();

        Http::fake([
            'https://api.sms.ir/v1/credit' => Http::response(['status' => 1, 'data' => 100]),
            'https://api.sms.ir/v1/line' => Http::response(['status' => 1, 'data' => [30001111111111]]),
        ]);

        $result = app(ProviderReadinessService::class)->inspect(true);
        $lineProbe = collect($result['checks'])->firstWhere('name', 'smsir.line_probe');

        $this->assertFalse($result['ok']);
        $this->assertFalse($lineProbe['ok']);
    }

    public function test_zarinpal_smoke_command_requires_the_real_zarinpal_driver(): void
    {
        config(['payment.driver' => 'null']);

        $this->artisan('ops:zarinpal-smoke', ['orderNumber' => 'SHR-NOT-USED'])
            ->expectsOutput('PAYMENT_DRIVER must be zarinpal.')
            ->assertFailed();
    }

    private function configureReadyProviders(): void
    {
        config([
            'app.url' => 'https://staging.example.test',
            'session.secure' => true,
            'session.encrypt' => true,
            'sms.driver' => 'smsir',
            'sms.smsir.api_key' => 'sandbox-key',
            'sms.smsir.sandbox' => true,
            'sms.smsir.otp_template_id' => null,
            'sms.smsir.line_number' => '30004505000017',
            'payment.driver' => 'zarinpal',
            'payment.callback_url' => 'https://staging.example.test/api/v1/payments/zarinpal/callback',
            'payment.result_url' => 'https://staging.example.test/payment-result.html',
            'payment.zarinpal.merchant_id' => '12345678-1234-1234-1234-123456789abc',
            'payment.zarinpal.sandbox' => true,
            'payment.zarinpal.sandbox_base_url' => 'https://sandbox.zarinpal.com',
            'sanctum.stateful' => ['staging.example.test'],
            'cors.allowed_origins' => ['https://staging.example.test'],
        ]);
    }
}
