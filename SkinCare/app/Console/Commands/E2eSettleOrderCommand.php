<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Console\Command;

class E2eSettleOrderCommand extends Command
{
    protected $signature = 'e2e:settle-order {orderNumber}';

    protected $description = 'Settle the latest payment attempt for an order in browser E2E tests';

    public function handle(PaymentSettlementService $settlement): int
    {
        if (! app()->environment('testing')) {
            $this->error('E2E settlement is restricted to the testing environment.');

            return self::FAILURE;
        }

        if (config('payment.driver') !== 'e2e') {
            $this->error('PAYMENT_DRIVER must be e2e for this command.');

            return self::FAILURE;
        }

        $orderNumber = trim((string) $this->argument('orderNumber'));
        $order = Order::query()->where('order_number', $orderNumber)->first();
        if (! $order) {
            $this->error('Order not found.');

            return self::FAILURE;
        }

        $payment = $order->payment()->first();
        $attempt = $payment?->attempts()->latest('attempt_number')->first();
        if (! $attempt || $attempt->provider !== 'e2e') {
            $this->error('No E2E payment attempt is available for this order.');

            return self::FAILURE;
        }

        $eventMaterial = 'e2e:settlement:'.$attempt->public_id;
        $settled = $settlement->settleSuccessful(
            attempt: $attempt,
            transactionId: 'E2E-TX-'.$attempt->public_id,
            dedupeKey: hash('sha256', $eventMaterial),
            payloadHash: hash('sha256', $eventMaterial.':payload'),
            metadata: ['source' => 'playwright-e2e'],
        );

        $this->info($settled->fresh()->status->value);

        return self::SUCCESS;
    }
}
