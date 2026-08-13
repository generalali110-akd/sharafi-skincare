<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Payments\PaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class ZarinpalSmokeCommand extends Command
{
    protected $signature = 'ops:zarinpal-smoke {orderNumber : Existing pending-payment staging order number} {--json : Emit machine-readable JSON only}';

    protected $description = 'Create a recorded Zarinpal payment attempt for an existing staging order';

    public function handle(PaymentService $payments): int
    {
        if (! app()->environment(['staging', 'testing'])) {
            $this->error('Zarinpal smoke initiation is restricted to staging/testing environments.');

            return self::FAILURE;
        }

        if (config('payment.driver') !== 'zarinpal') {
            $this->error('PAYMENT_DRIVER must be zarinpal.');

            return self::FAILURE;
        }

        $orderNumber = trim((string) $this->argument('orderNumber'));
        $order = Order::query()->with('user')->where('order_number', $orderNumber)->first();

        if (! $order || ! $order->user) {
            $this->error('Order not found.');

            return self::FAILURE;
        }

        try {
            [, $attempt] = $payments->initiate(
                $order->user,
                $order,
                'staging-smoke-'.Str::uuid()->toString(),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Zarinpal smoke initiation failed. Review the application log for the safe mapped error.');

            return self::FAILURE;
        }

        $result = [
            'ok' => true,
            'order_number' => $order->order_number,
            'payment_attempt' => $attempt->public_id,
            'redirect_url' => $attempt->redirect_url,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Zarinpal payment attempt created.');
            $this->line('Order: '.$result['order_number']);
            $this->line('Attempt: '.$result['payment_attempt']);
            $this->line('Redirect: '.$result['redirect_url']);
        }

        return self::SUCCESS;
    }
}
