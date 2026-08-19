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
            'order_cancelled' => "شرافی: سفارش {$number} لغو شد.",
            'refund_pending' => "شرافی: بازپرداخت سفارش {$number} در دست بررسی است.",
            'refund_completed' => "شرافی: بازپرداخت سفارش {$number} تکمیل شد.",
            default => throw new UnexpectedValueException('Unsupported order SMS template.'),
        };
    }
}
