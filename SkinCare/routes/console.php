<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Commerce\OrderReservationService;
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

Schedule::command('orders:expire-reservations')
    ->everyMinute()
    ->withoutOverlapping();
