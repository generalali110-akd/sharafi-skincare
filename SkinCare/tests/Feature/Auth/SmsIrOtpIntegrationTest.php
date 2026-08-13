<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsIrOtpIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_otp_request_resolves_smsir_gateway_from_application_configuration(): void
    {
        config()->set('sms.driver', 'smsir');
        config()->set('sms.smsir', [
            'api_key' => str_repeat('a', 64),
            'sandbox' => true,
            'otp_template_id' => null,
            'otp_code_parameter' => 'CODE',
            'line_number' => '30004505000017',
            'connect_timeout_seconds' => 3,
            'timeout_seconds' => 8,
            'max_message_chars' => 320,
        ]);

        Http::fake([
            'https://api.sms.ir/v1/send/verify' => Http::response([
                'status' => 1,
                'message' => 'موفق',
                'data' => ['messageId' => 89545112, 'cost' => 1.0],
            ]),
        ]);

        $this->withHeaders([
            'Origin' => 'http://localhost:8000',
            'Referer' => 'http://localhost:8000/login.html',
            'Accept' => 'application/json',
        ])->postJson('/api/v1/auth/otp/request', [
            'mobile' => '09121234567',
        ])->assertCreated()
            ->assertJsonPath('data.mobile', '0912***4567');

        Http::assertSent(function (Request $request): bool {
            $parameters = $request['parameters'];

            return $request->url() === 'https://api.sms.ir/v1/send/verify'
                && $request['templateId'] === 123456
                && is_array($parameters)
                && ($parameters[0]['name'] ?? null) === 'CODE'
                && preg_match('/^\d{6}$/', (string) ($parameters[0]['value'] ?? '')) === 1;
        });
    }
}
