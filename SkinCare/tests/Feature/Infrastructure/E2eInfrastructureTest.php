<?php

namespace Tests\Feature\Infrastructure;

use App\Contracts\PaymentGateway;
use App\Contracts\SmsGateway;
use App\Infrastructure\Testing\E2ePaymentGateway;
use App\Infrastructure\Testing\E2eSmsGateway;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class E2eInfrastructureTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/e2e'));
        parent::tearDown();
    }

    public function test_e2e_sms_gateway_writes_otp_to_private_hashed_test_file(): void
    {
        config(['sms.driver' => 'e2e']);
        app()->forgetInstance(SmsGateway::class);

        $gateway = app(SmsGateway::class);
        $this->assertInstanceOf(E2eSmsGateway::class, $gateway);

        $mobile = '09120000002';
        $gateway->sendOtp($mobile, '123456', 120);

        $path = storage_path('framework/e2e/otp-'.hash('sha256', $mobile).'.json');
        $this->assertFileExists($path);
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('123456', $payload['code']);
        $this->assertSame(hash('sha256', $mobile), $payload['mobile_hash']);
        $this->assertStringNotContainsString($mobile, basename($path));
    }

    public function test_e2e_payment_gateway_can_only_be_selected_through_testing_binding(): void
    {
        config(['payment.driver' => 'e2e']);
        app()->forgetInstance(PaymentGateway::class);

        $this->assertInstanceOf(E2ePaymentGateway::class, app(PaymentGateway::class));
    }
}
