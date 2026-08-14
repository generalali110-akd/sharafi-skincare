<?php

namespace Tests\Feature\Auth;

use App\Contracts\SmsGateway;
use App\Models\OtpChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fakes\FakeSmsGateway;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    private function fromStorefront(): static
    {
        return $this->withHeaders([
            'Origin' => 'http://localhost:8000',
            'Referer' => 'http://localhost:8000/login.html',
            'Accept' => 'application/json',
        ]);
    }

    public function test_invalid_iran_mobile_is_rejected(): void
    {
        $this->fromStorefront()->postJson('/api/v1/auth/otp/request', [
            'mobile' => '12345',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('mobile');
    }

    public function test_otp_can_authenticate_a_customer_without_exposing_plaintext_code(): void
    {
        $gateway = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $gateway);

        $response = $this->fromStorefront()->postJson('/api/v1/auth/otp/request', [
            'mobile' => '۰۹۱۲۱۲۳۴۵۶۷',
            'name' => 'کاربر تست',
        ])->assertCreated();

        $challengeId = (string) $response->json('data.challenge_id');

        $this->assertSame('09121234567', $gateway->mobile);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $gateway->code);
        $this->assertNotSame($gateway->code, OtpChallenge::query()->findOrFail($challengeId)->code_hash);

        $this->fromStorefront()->postJson('/api/v1/auth/otp/verify', [
            'challenge_id' => $challengeId,
            'code' => $gateway->code,
        ])->assertOk()
            ->assertJsonPath('data.authenticated', true)
            ->assertJsonPath('data.user.mobile', '09121234567');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'mobile' => '09121234567',
            'status' => 'active',
        ]);
    }

    public function test_resend_window_returns_too_many_requests(): void
    {
        $gateway = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $gateway);

        $payload = ['mobile' => '09121234567'];

        $this->fromStorefront()->postJson('/api/v1/auth/otp/request', $payload)->assertCreated();
        $this->fromStorefront()->postJson('/api/v1/auth/otp/request', $payload)->assertStatus(429);
    }

    public function test_concurrent_request_for_same_mobile_is_rejected_while_lock_is_held(): void
    {
        $this->app->instance(SmsGateway::class, new FakeSmsGateway);
        config()->set('sms.otp.request_lock_wait_seconds', 0);

        $mobile = '09121234567';
        $lock = Cache::lock('otp:request:lock:'.hash('sha256', $mobile), 30);
        $this->assertTrue($lock->get());

        try {
            $this->fromStorefront()->postJson('/api/v1/auth/otp/request', [
                'mobile' => $mobile,
            ])->assertStatus(429)
                ->assertJsonPath('message', 'درخواست دیگری برای این شماره در حال پردازش است.');

            $this->assertDatabaseCount('otp_challenges', 0);
        } finally {
            $lock->release();
        }
    }
}
