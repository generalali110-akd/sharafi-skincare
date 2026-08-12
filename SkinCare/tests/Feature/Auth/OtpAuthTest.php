<?php

namespace Tests\Feature\Auth;

use App\Contracts\SmsGateway;
use App\Models\OtpChallenge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSmsGateway;
use Tests\TestCase;

class OtpAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_iran_mobile_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/otp/request', [
            'mobile' => '12345',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('mobile');
    }

    public function test_otp_can_authenticate_a_customer_without_exposing_plaintext_code(): void
    {
        $gateway = new FakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $response = $this->postJson('/api/v1/auth/otp/request', [
            'mobile' => '۰۹۱۲۱۲۳۴۵۶۷',
            'name' => 'کاربر تست',
        ])->assertCreated();

        $challengeId = (string) $response->json('data.challenge_id');

        $this->assertSame('09121234567', $gateway->mobile);
        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $gateway->code);
        $this->assertNotSame($gateway->code, OtpChallenge::query()->findOrFail($challengeId)->code_hash);

        $this->postJson('/api/v1/auth/otp/verify', [
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
        $gateway = new FakeSmsGateway();
        $this->app->instance(SmsGateway::class, $gateway);

        $payload = ['mobile' => '09121234567'];

        $this->postJson('/api/v1/auth/otp/request', $payload)->assertCreated();
        $this->postJson('/api/v1/auth/otp/request', $payload)->assertStatus(429);
    }
}
