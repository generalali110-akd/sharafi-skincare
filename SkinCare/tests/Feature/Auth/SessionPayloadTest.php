<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_endpoint_exposes_only_storefront_identity_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'کاربر تست',
            'mobile' => '09121234567',
            'status' => 'active',
            'mobile_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'name' => 'کاربر تست',
                    'mobile' => '09121234567',
                ],
            ]);
    }
}
