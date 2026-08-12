<?php

namespace App\Services\Commerce;

use App\Enums\OrderStatus;
use App\Exceptions\CheckoutConflictException;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class OrderReservationService
{
    public function __construct(
        private readonly DiscountService $discounts,
        private readonly OrderStateMachine $stateMachine,
    ) {}

    public function cancel(User $user, Order $order): Order
    {
        if ($order->user_id !== $user->getKey()) {
            abort(404);
        }

        return DB::transaction(function () use ($order, $user): Order {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== OrderStatus::PendingPayment) {
                throw new CheckoutConflictException('این سفارش دیگر قابل لغو نیست.');
            }

            return $this->releaseLocked($locked, OrderStatus::Cancelled, $user);
        }, attempts: 3);
    }

    public function expire(Order $order): bool
    {
        return DB::transaction(function () use ($order): bool {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();
            if (! $locked || $locked->status !== OrderStatus::PendingPayment) {
                return false;
            }

            if (! $locked->reservation_expires_at || $locked->reservation_expires_at->isFuture()) {
                return false;
            }

            $this->releaseLocked($locked, OrderStatus::Expired, null);

            return true;
        }, attempts: 3);
    }

    private function releaseLocked(Order $order, OrderStatus $target, ?User $actor): Order
    {
        $items = $order->items()->whereNotNull('variant_id')->orderBy('variant_id')->get();
        $variantIds = $items->pluck('variant_id')->all();
        $inventories = InventoryItem::query()
            ->whereIn('variant_id', $variantIds)
            ->orderBy('variant_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('variant_id');

        foreach ($items as $item) {
            $inventory = $inventories->get($item->variant_id);
            if (! $inventory || $inventory->reserved < $item->quantity) {
                throw new CheckoutConflictException('وضعیت رزرو موجودی ناسازگار است؛ عملیات متوقف شد.');
            }

            $inventory->reserved -= $item->quantity;
            $inventory->save();

            InventoryMovement::query()->create([
                'variant_id' => $item->variant_id,
                'type' => 'reservation_release',
                'quantity' => -$item->quantity,
                'reason' => $target->value,
                'actor_user_id' => $actor?->getKey(),
                'reference_type' => 'order',
                'reference_id' => $order->order_number,
                'metadata' => ['bucket' => 'reserved'],
            ]);
        }

        $this->discounts->releaseForOrder($order);
        $this->stateMachine->transition($order, $target, $actor, $target->value);

        return $order->load('items');
    }
}
