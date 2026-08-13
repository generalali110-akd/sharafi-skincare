<?php

namespace App\Services\Notifications;

use App\Models\Order;
use App\Models\OutboxMessage;
use UnexpectedValueException;

final class OrderSmsComposer
{
    public function compose(OutboxMessage $message, Order $order): string
    {
        $template = (string) ($message->payload['template'] ?? '');
        $number = $order->order_number;

        return match ($template) {
            'order_created' => "شرافی: سفارش {$number} ثبت شد. برای تکمیل سفارش، پرداخت را انجام دهید.",
            'payment_succeeded' => "شرافی: پرداخت سفارش {$number} تأیید شد. سفارش برای پردازش آماده است.",
            'order_shipped' => "شرافی: سفارش {$number} ارسال شد. از خرید شما سپاسگزاریم.",
            default => throw new UnexpectedValueException('Unsupported order SMS template.'),
        };
    }
}
