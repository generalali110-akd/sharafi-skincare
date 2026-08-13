<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Orders\UpdateOrderStatusAction;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AdminOrderIndexRequest;
use App\Http\Requests\Api\V1\Admin\UpdateOrderStatusRequest;
use App\Http\Resources\Api\V1\Admin\AdminOrderDetailResource;
use App\Http\Resources\Api\V1\Admin\AdminOrderListResource;
use App\Models\Order;
use App\Models\OutboxMessage;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AdminOrderController extends Controller
{
    public function index(AdminOrderIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $query = Order::query()
            ->with(['user:id,name,mobile', 'payment'])
            ->withCount('items');

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $search = trim((string) ($validated['q'] ?? ''));
        if ($search !== '') {
            $prefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function ($query) use ($prefix): void {
                $query->where('order_number', 'ilike', $prefix)
                    ->orWhereHas('user', function ($query) use ($prefix): void {
                        $query->where('mobile', 'ilike', $prefix)
                            ->orWhere('name', 'ilike', $prefix);
                    });
            });
        }

        $orders = $query
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return AdminOrderListResource::collection($orders);
    }

    public function show(string $orderNumber): AdminOrderDetailResource
    {
        return new AdminOrderDetailResource($this->detailOrder($orderNumber));
    }

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        string $orderNumber,
        UpdateOrderStatusAction $action,
    ): AdminOrderDetailResource {
        $validated = $request->validated();
        $order = Order::query()->where('order_number', $orderNumber)->firstOrFail();

        $action->execute(
            order: $order,
            expectedStatus: OrderStatus::from($validated['expected_status']),
            targetStatus: OrderStatus::from($validated['status']),
            actor: $request->user(),
            reason: $validated['reason'] ?? null,
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return new AdminOrderDetailResource($this->detailOrder($orderNumber));
    }

    private function detailOrder(string $orderNumber): Order
    {
        $order = Order::query()
            ->where('order_number', $orderNumber)
            ->with([
                'user:id,name,mobile',
                'items',
                'payment.attempts',
                'payment.events.attempt:id,transaction_id',
                'statusTransitions.actor:id,name',
            ])
            ->firstOrFail();

        $notifications = OutboxMessage::query()
            ->where('aggregate_type', 'order')
            ->where('aggregate_id', (string) $order->getKey())
            ->orderBy('id')
            ->get();

        $order->setRelation('notificationEvents', $notifications);

        return $order;
    }
}
