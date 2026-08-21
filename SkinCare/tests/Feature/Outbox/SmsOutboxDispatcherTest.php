<?php

namespace Tests\Feature\Outbox;

use App\Contracts\SmsGateway;
use App\Enums\OrderStatus;
use App\Exceptions\PermanentSmsDeliveryException;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\Outbox\SmsOutboxDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fakes\FakeSmsGateway;
use Tests\TestCase;

class SmsOutboxDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_committed_message_is_sent_once_and_marked_processed(): void
    {
        $fake = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $fake);
        $order = $this->order();
        $message = $this->message($order, 'order_shipped');

        $dispatcher = $this->app->make(SmsOutboxDispatcher::class);

        $this->assertSame(SmsOutboxDispatcher::RESULT_PROCESSED, $dispatcher->dispatchOne());
        $this->assertSame(SmsOutboxDispatcher::RESULT_EMPTY, $dispatcher->dispatchOne());
        $this->assertCount(1, $fake->messages);
        $this->assertSame('09121234567', $fake->messages[0]['mobile']);
        $this->assertSame($message->event_key, $fake->messages[0]['idempotency_key']);
        $this->assertNotNull($message->fresh()->processed_at);
        $this->assertSame(1, $message->fresh()->attempts);
    }

    public function test_expired_notification_is_failed_without_external_send(): void
    {
        $fake = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $fake);
        $order = $this->order();
        $message = $this->message($order, 'order_created', now()->subMinute());

        $result = $this->app->make(SmsOutboxDispatcher::class)->dispatchOne();

        $this->assertSame(SmsOutboxDispatcher::RESULT_FAILED, $result);
        $this->assertCount(0, $fake->messages);
        $this->assertNotNull($message->fresh()->failed_at);
        $this->assertSame('expired', $message->fresh()->last_error);
    }

    public function test_delivery_failure_is_deferred_without_persisting_raw_exception_text(): void
    {
        config()->set('sms.outbox.initial_backoff_seconds', 45);
        config()->set('sms.outbox.max_backoff_seconds', 45);
        $testNow = now()->startOfSecond();
        Carbon::setTestNow($testNow);

        try {
            $this->app->instance(SmsGateway::class, new class implements SmsGateway
            {
                public function sendOtp(string $mobile, string $code, int $ttlSeconds): void {}

                public function sendMessage(string $mobile, string $message, string $idempotencyKey): void
                {
                    throw new RuntimeException('SECRET-provider-response-must-not-be-persisted');
                }
            });

            $order = $this->order();
            $message = $this->message($order, 'payment_succeeded');

            $result = $this->app->make(SmsOutboxDispatcher::class)->dispatchOne();
            $message = $message->fresh();

            $this->assertSame(SmsOutboxDispatcher::RESULT_FAILED, $result);
            $this->assertNull($message->processed_at);
            $this->assertNull($message->failed_at);
            $this->assertNull($message->locked_at);
            $this->assertSame('RuntimeException', $message->last_error);
            $this->assertStringNotContainsString('SECRET-provider-response', (string) $message->last_error);
            $this->assertTrue($message->available_at->equalTo($testNow->copy()->addSeconds(45)));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_transient_delivery_failure_marks_message_failed_after_max_attempts(): void
    {
        config()->set('sms.outbox.max_attempts', 2);

        $this->app->instance(SmsGateway::class, new class implements SmsGateway
        {
            public function sendOtp(string $mobile, string $code, int $ttlSeconds): void {}

            public function sendMessage(string $mobile, string $message, string $idempotencyKey): void
            {
                throw new RuntimeException('SECRET-final-provider-response');
            }
        });

        $message = $this->message($this->order(), 'payment_succeeded');
        $message->forceFill(['attempts' => 1])->save();

        $result = $this->app->make(SmsOutboxDispatcher::class)->dispatchOne();
        $message = $message->fresh();

        $this->assertSame(SmsOutboxDispatcher::RESULT_FAILED, $result);
        $this->assertSame(2, $message->attempts);
        $this->assertNull($message->processed_at);
        $this->assertNotNull($message->failed_at);
        $this->assertNull($message->locked_at);
        $this->assertSame('RuntimeException', $message->last_error);
        $this->assertStringNotContainsString('SECRET-final-provider-response', (string) $message->last_error);
    }

    public function test_stale_locked_message_becomes_claimable_after_lock_ttl(): void
    {
        config()->set('sms.outbox.lock_ttl_seconds', 60);

        $fake = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $fake);
        $message = $this->message($this->order(), 'order_shipped');
        $message->forceFill(['locked_at' => now()->subSeconds(61)])->save();

        $result = $this->app->make(SmsOutboxDispatcher::class)->dispatchOne();
        $message = $message->fresh();

        $this->assertSame(SmsOutboxDispatcher::RESULT_PROCESSED, $result);
        $this->assertCount(1, $fake->messages);
        $this->assertNotNull($message->processed_at);
        $this->assertNull($message->locked_at);
        $this->assertSame(1, $message->attempts);
    }

    public function test_permanent_delivery_failure_is_failed_immediately_without_retry_window(): void
    {
        $this->app->instance(SmsGateway::class, new class implements SmsGateway
        {
            public function sendOtp(string $mobile, string $code, int $ttlSeconds): void {}

            public function sendMessage(string $mobile, string $message, string $idempotencyKey): void
            {
                throw new PermanentSmsDeliveryException('SECRET-invalid-template-details');
            }
        });

        $message = $this->message($this->order(), 'payment_succeeded');

        $result = $this->app->make(SmsOutboxDispatcher::class)->dispatchOne();
        $message = $message->fresh();

        $this->assertSame(SmsOutboxDispatcher::RESULT_FAILED, $result);
        $this->assertNull($message->processed_at);
        $this->assertNotNull($message->failed_at);
        $this->assertNull($message->locked_at);
        $this->assertSame('PermanentSmsDeliveryException', $message->last_error);
        $this->assertStringNotContainsString('SECRET-invalid-template-details', (string) $message->last_error);
        $this->assertSame(1, $message->attempts);
    }

    public function test_malformed_outbox_message_is_failed_permanently_without_retrying(): void
    {
        $fake = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $fake);
        $message = $this->message($this->order(), 'unsupported_template');

        $result = $this->app->make(SmsOutboxDispatcher::class)->dispatchOne();
        $message = $message->fresh();

        $this->assertSame(SmsOutboxDispatcher::RESULT_FAILED, $result);
        $this->assertCount(0, $fake->messages);
        $this->assertNotNull($message->failed_at);
        $this->assertNull($message->locked_at);
        $this->assertSame('UnexpectedValueException', $message->last_error);
        $this->assertSame(1, $message->attempts);
    }

    public function test_deleted_order_message_is_failed_permanently_without_external_send(): void
    {
        $fake = new FakeSmsGateway;
        $this->app->instance(SmsGateway::class, $fake);
        $order = $this->order();
        $message = $this->message($order, 'order_shipped');
        $order->delete();

        $result = $this->app->make(SmsOutboxDispatcher::class)->dispatchOne();
        $message = $message->fresh();

        $this->assertSame(SmsOutboxDispatcher::RESULT_FAILED, $result);
        $this->assertCount(0, $fake->messages);
        $this->assertNotNull($message->failed_at);
        $this->assertSame('ModelNotFoundException', $message->last_error);
    }

    public function test_null_sms_driver_leaves_queued_messages_untouched(): void
    {
        config()->set('sms.driver', 'null');
        $message = $this->message($this->order(), 'order_created');

        Artisan::call('outbox:dispatch-sms', ['--limit' => 10]);

        $message = $message->fresh();
        $this->assertSame(0, $message->attempts);
        $this->assertNull($message->locked_at);
        $this->assertNull($message->processed_at);
        $this->assertNull($message->failed_at);
    }

    private function order(): Order
    {
        $user = User::factory()->create(['mobile' => '09121234567']);
        $id = (string) Str::ulid();

        return Order::query()->create([
            'user_id' => $user->getKey(),
            'order_number' => 'SHR-'.$id,
            'idempotency_key' => hash('sha256', 'order-'.$id),
            'idempotency_fingerprint' => hash('sha256', 'fingerprint-'.$id),
            'status' => OrderStatus::Shipped,
            'shipping_method' => 'standard',
            'address_snapshot' => [
                'recipient_name' => 'Test Customer',
                'mobile' => '09121234567',
                'province' => 'Tehran',
                'city' => 'Tehran',
                'postal_code' => '1234567890',
                'address_line' => 'Test address',
            ],
            'subtotal_irr' => 1_000_000,
            'discount_irr' => 0,
            'shipping_irr' => 0,
            'total_irr' => 1_000_000,
            'paid_at' => now(),
            'processing_at' => now(),
            'shipped_at' => now(),
        ]);
    }

    private function message(Order $order, string $template, $expiresAt = null): OutboxMessage
    {
        return OutboxMessage::query()->create([
            'topic' => 'sms',
            'event_key' => 'order:'.$order->getKey().':'.$template.':sms',
            'aggregate_type' => 'order',
            'aggregate_id' => (string) $order->getKey(),
            'payload' => [
                'template' => $template,
                'order_number' => $order->order_number,
            ],
            'available_at' => now(),
            'expires_at' => $expiresAt,
        ]);
    }
}
