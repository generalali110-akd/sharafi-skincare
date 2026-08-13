<?php

namespace Tests\Unit;

use App\Exceptions\PermanentSmsDeliveryException;
use App\Exceptions\SmsDeliveryException;
use App\Infrastructure\Sms\SmsIrGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsIrGatewayTest extends TestCase
{
    private const API_KEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_sandbox_otp_uses_official_verify_endpoint_and_default_template(): void
    {
        Http::fake([
            'https://api.sms.ir/v1/send/verify' => Http::response([
                'status' => 1,
                'message' => 'موفق',
                'data' => ['messageId' => 89545112, 'cost' => 1.0],
            ]),
        ]);

        $this->gateway()->sendOtp('09121234567', '123456', 120);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.sms.ir/v1/send/verify'
                && $request->hasHeader('X-API-KEY', self::API_KEY)
                && $request['mobile'] === '09121234567'
                && $request['templateId'] === 123456
                && $request['parameters'] === [[
                    'name' => 'CODE',
                    'value' => '123456',
                ]];
        });
    }

    public function test_api_key_length_is_not_guessed_when_provider_does_not_document_a_format(): void
    {
        Http::fake([
            'https://api.sms.ir/v1/send/verify' => Http::response([
                'status' => 1,
                'message' => 'موفق',
                'data' => ['messageId' => 89545112, 'cost' => 1.0],
            ]),
        ]);

        $this->gateway(['api_key' => 'short-key'])->sendOtp('09121234567', '123456', 120);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-KEY', 'short-key'));
    }

    public function test_production_otp_requires_explicit_template_id_before_network_call(): void
    {
        Http::preventStrayRequests();

        $this->expectException(PermanentSmsDeliveryException::class);

        try {
            $this->gateway(['sandbox' => false, 'otp_template_id' => null])
                ->sendOtp('09121234567', '123456', 120);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_bulk_message_uses_configured_line_and_single_destination(): void
    {
        Http::fake([
            'https://api.sms.ir/v1/send/bulk' => Http::response([
                'status' => 1,
                'message' => 'موفق',
                'data' => [
                    'packId' => '2b99e63c-9bf8-4a21-9bfe-3f72dc1b46f1',
                    'messageIds' => [86522023],
                    'cost' => 2.0,
                ],
            ]),
        ]);

        $this->gateway()->sendMessage('09121234567', 'سفارش شما ثبت شد.', 'order:1:created:sms');

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.sms.ir/v1/send/bulk'
                && $request['lineNumber'] === 30004505000017
                && $request['messageText'] === 'سفارش شما ثبت شد.'
                && $request['mobiles'] === ['09121234567']
                && $request['sendDateTime'] === null;
        });
    }

    public function test_missing_api_key_fails_closed_without_network_call(): void
    {
        Http::preventStrayRequests();
        $this->expectException(PermanentSmsDeliveryException::class);

        try {
            $this->gateway(['api_key' => null])->sendOtp('09121234567', '123456', 120);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_missing_template_is_classified_as_permanent_provider_failure(): void
    {
        Http::fake([
            'https://api.sms.ir/v1/send/verify' => Http::response([
                'status' => 113,
                'message' => 'قالب یافت نشد',
                'data' => null,
            ], 400),
        ]);

        $this->expectException(PermanentSmsDeliveryException::class);
        $this->gateway()->sendOtp('09121234567', '123456', 120);
    }

    public function test_rate_limit_is_retryable_instead_of_permanent(): void
    {
        Http::fake([
            'https://api.sms.ir/v1/send/verify' => Http::response([
                'status' => 20,
                'message' => 'تعداد درخواست بیشتر از حد مجاز است',
                'data' => null,
            ], 429),
        ]);

        try {
            $this->gateway()->sendOtp('09121234567', '123456', 120);
            $this->fail('Expected SMS delivery exception was not thrown.');
        } catch (SmsDeliveryException $exception) {
            $this->assertNotInstanceOf(PermanentSmsDeliveryException::class, $exception);
        }
    }

    public function test_blacklisted_bulk_destination_is_treated_as_permanent_for_that_line(): void
    {
        Http::fake([
            'https://api.sms.ir/v1/send/bulk' => Http::response([
                'status' => 1,
                'message' => 'موفق',
                'data' => [
                    'packId' => '2b99e63c-9bf8-4a21-9bfe-3f72dc1b46f1',
                    'messageIds' => [0],
                    'cost' => 0,
                ],
            ]),
        ]);

        $this->expectException(PermanentSmsDeliveryException::class);
        $this->gateway()->sendMessage('09121234567', 'سفارش شما ثبت شد.', 'order:1:created:sms');
    }

    public function test_message_length_guard_prevents_accidental_runaway_sms_cost(): void
    {
        Http::preventStrayRequests();
        $this->expectException(PermanentSmsDeliveryException::class);

        try {
            $this->gateway(['max_message_chars' => 70])
                ->sendMessage('09121234567', str_repeat('الف', 71), 'order:1:created:sms');
        } finally {
            Http::assertNothingSent();
        }
    }

    private function gateway(array $overrides = []): SmsIrGateway
    {
        return new SmsIrGateway(array_replace([
            'api_key' => self::API_KEY,
            'sandbox' => true,
            'otp_template_id' => null,
            'otp_code_parameter' => 'CODE',
            'line_number' => '30004505000017',
            'connect_timeout_seconds' => 3,
            'timeout_seconds' => 8,
            'max_message_chars' => 320,
        ], $overrides));
    }
}
