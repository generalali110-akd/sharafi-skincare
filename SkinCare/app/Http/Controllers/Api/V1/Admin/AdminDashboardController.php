<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AdminDashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $timezone = (string) config('shop.timezone', 'Asia/Tehran');
        $now = CarbonImmutable::now($timezone);
        $todayStart = $now->startOfDay()->utc();
        $todayEnd = $now->endOfDay()->utc();
        $sevenDayStart = $now->startOfDay()->subDays(6)->utc();

        $paidPayments = Payment::query()
            ->where('status', PaymentStatus::Paid->value)
            ->whereBetween('paid_at', [$sevenDayStart, $todayEnd])
            ->get(['amount_irr', 'paid_at']);

        $salesByDay = collect(range(6, 0))
            ->mapWithKeys(function (int $daysAgo) use ($now): array {
                $date = $now->subDays($daysAgo)->toDateString();

                return [$date => 0];
            });

        foreach ($paidPayments as $payment) {
            $date = $payment->paid_at?->timezone($timezone)->toDateString();
            if ($date !== null && $salesByDay->has($date)) {
                $salesByDay[$date] += (int) $payment->amount_irr;
            }
        }

        $recentOrders = Order::query()
            ->latest('id')
            ->limit(5)
            ->get(['id', 'order_number', 'status', 'total_irr', 'created_at'])
            ->map(fn (Order $order) => [
                'order_number' => $order->order_number,
                'status' => $order->status->value,
                'total_irr' => $order->total_irr,
                'created_at' => $order->created_at?->toISOString(),
            ])
            ->values();

        $lowStock = InventoryItem::query()
            ->with('variant.product:id,name')
            ->whereRaw('(on_hand - reserved) <= reorder_level')
            ->orderByRaw('(on_hand - reserved) asc')
            ->limit(5)
            ->get()
            ->map(fn (InventoryItem $item) => [
                'variant_id' => $item->variant_id,
                'product_name' => $item->variant?->product?->name,
                'sku' => $item->variant?->sku,
                'available' => $item->available,
                'reorder_level' => $item->reorder_level,
            ])
            ->values();

        return response()->json([
            'data' => [
                'timezone' => $timezone,
                'today' => [
                    'paid_sales_irr' => (int) Payment::query()
                        ->where('status', PaymentStatus::Paid->value)
                        ->whereBetween('paid_at', [$todayStart, $todayEnd])
                        ->sum('amount_irr'),
                    'new_orders' => Order::query()
                        ->whereBetween('created_at', [$todayStart, $todayEnd])
                        ->count(),
                    'new_customers' => User::query()
                        ->whereDoesntHave('roles')
                        ->whereBetween('created_at', [$todayStart, $todayEnd])
                        ->count(),
                    'low_stock_variants' => InventoryItem::query()
                        ->whereRaw('(on_hand - reserved) <= reorder_level')
                        ->count(),
                ],
                'sales_7d' => $salesByDay
                    ->map(fn (int $amount, string $date) => [
                        'date' => $date,
                        'paid_sales_irr' => $amount,
                    ])
                    ->values(),
                'recent_orders' => $recentOrders,
                'low_stock' => $lowStock,
            ],
        ]);
    }
}
