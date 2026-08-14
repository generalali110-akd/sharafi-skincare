<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Commerce\OrderReservationService;
use App\Services\Outbox\SmsOutboxDispatcher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('about:sharafi', function (): void {
    $this->info('Sharafi Skin Care backend');
})->purpose('Show the Sharafi backend identifier');

Artisan::command('orders:expire-reservations {--limit=500}', function (): void {
    $limit = max(1, min(5000, (int) $this->option('limit')));
    $service = app(OrderReservationService::class);
    $expired = 0;

    Order::query()
        ->where('status', OrderStatus::PendingPayment->value)
        ->whereNotNull('reservation_expires_at')
        ->where('reservation_expires_at', '<=', now())
        ->orderBy('id')
        ->limit($limit)
        ->get()
        ->each(function (Order $order) use ($service, &$expired): void {
            if ($service->expire($order)) {
                $expired++;
            }
        });

    $this->info("Expired {$expired} pending order reservation(s).");
})->purpose('Release expired pending-payment inventory reservations');

Artisan::command('outbox:dispatch-sms {--limit=100}', function (): void {
    if (config('sms.driver') === 'null') {
        $this->warn('SMS driver is not configured; queued notifications were left untouched.');

        return;
    }

    $limit = max(1, min(1000, (int) $this->option('limit')));
    $dispatcher = app(SmsOutboxDispatcher::class);
    $processed = 0;
    $failed = 0;

    for ($i = 0; $i < $limit; $i++) {
        $result = $dispatcher->dispatchOne();
        if ($result === SmsOutboxDispatcher::RESULT_EMPTY) {
            break;
        }

        if ($result === SmsOutboxDispatcher::RESULT_PROCESSED) {
            $processed++;
        } else {
            $failed++;
        }
    }

    $this->info("SMS outbox: {$processed} processed, {$failed} deferred/failed.");
})->purpose('Dispatch committed SMS notifications from the transactional outbox');

Schedule::command('ops:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('orders:expire-reservations')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('outbox:dispatch-sms --limit=100')
    ->everyMinute()
    ->withoutOverlapping();
