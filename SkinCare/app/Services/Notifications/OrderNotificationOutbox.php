<?php

namespace App\Services\Notifications;

use App\Models\Order;
use App\Models\OutboxMessage;
use Carbon\CarbonInterface;

final class OrderNotificationOutbox
{
    public function orderCreated(Order $order): void
    {
        $this->record($order, 'order_created', $order->reservation_expires_at);
    }

    public function paymentSucceeded(Order $order): void
    {
        $this->record($order, 'payment_succeeded');
    }

    public function shipped(Order $order): void
    {
        $this->record($order, 'order_shipped');
    }

    public function cancelled(Order $order): void
    {
        $this->record($order, 'order_cancelled');
    }

    public function refundPending(Order $order): void
    {
        $this->record($order, 'refund_pending');
    }

    public function refunded(Order $order): void
    {
        $this->record($order, 'refund_completed');
    }

    private function record(Order $order, string $template, ?CarbonInterface $expiresAt = null): void
    {
        $expiresHours = max(1, min(168, (int) config('sms.outbox.notification_expire_hours', 24)));

        OutboxMessage::query()->firstOrCreate(
            ['event_key' => 'order:'.$order->getKey().':'.$template.':sms'],
            [
                'topic' => 'sms',
                'aggregate_type' => 'order',
                'aggregate_id' => (string) $order->getKey(),
                'payload' => [
                    'template' => $template,
                    'order_number' => $order->order_number,
                ],
                'available_at' => now(),
                'expires_at' => $expiresAt ?? now()->addHours($expiresHours),
            ],
        );
    }
}
