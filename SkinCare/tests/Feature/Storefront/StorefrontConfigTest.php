<?php

namespace Tests\Feature\Storefront;

use Tests\TestCase;

class StorefrontConfigTest extends TestCase
{
    public function test_public_storefront_config_exposes_only_non_sensitive_commerce_values(): void
    {
        config()->set('shop.currency', 'IRR');
        config()->set('shop.max_item_quantity', 99);
        config()->set('shop.free_shipping_threshold_irr', 8_000_000);
        config()->set('shop.standard_shipping_irr', 450_000);
        config()->set('shop.courier_shipping_irr', 650_000);
        config()->set('sms.smsir.api_key', 'must-not-leak');
        config()->set('payment.zarinpal.merchant_id', 'must-not-leak');

        $response = $this->getJson('/api/v1/storefront/config');

        $response->assertOk()
            ->assertExactJson([
                'data' => [
                    'currency' => 'IRR',
                    'cart' => [
                        'max_item_quantity' => 99,
                    ],
                    'shipping' => [
                        'free_threshold_irr' => 8_000_000,
                        'standard_irr' => 450_000,
                        'courier_irr' => 650_000,
                    ],
                ],
            ]);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cacheControl);
        $this->assertStringContainsString('max-age=300', $cacheControl);
        $this->assertStringContainsString('stale-while-revalidate=60', $cacheControl);

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('must-not-leak', $encoded);
        $this->assertStringNotContainsString('api_key', $encoded);
        $this->assertStringNotContainsString('merchant_id', $encoded);
    }
}
