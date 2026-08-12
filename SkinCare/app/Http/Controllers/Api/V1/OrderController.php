<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Orders\CreateOrderAction;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Commerce\OrderReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends Controller
{
    public function __construct(
        private readonly CreateOrderAction $createOrder,
        private readonly OrderReservationService $reservations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('user_id', $request->user()->getKey())
            ->with('items')
            ->latest('id')
            ->paginate(20)
            ->through(fn (Order $order) => $this->payload($order));

        return response()->json($orders);
    }

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = $this->ownedOrder($request, $orderNumber)->load('items');

        return response()->json(['data' => $this->payload($order)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address_id' => ['required', 'integer'],
            'shipping_method' => ['required', Rule::in(['standard', 'courier'])],
            'coupon_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'price_irr' => ['prohibited'],
            'subtotal_irr' => ['prohibited'],
            'discount_irr' => ['prohibited'],
            'shipping_irr' => ['prohibited'],
            'total_irr' => ['prohibited'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if (! preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['هدر Idempotency-Key معتبر و الزامی است.'],
            ]);
        }

        [$order, $created] = $this->createOrder->execute(
            $request->user(),
            (int) $data['address_id'],
            $data['shipping_method'],
            $idempotencyKey,
            $data['coupon_code'] ?? null,
        );

        return response()->json(
            ['data' => $this->payload($order)],
            $created ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function cancel(Request $request, string $orderNumber): JsonResponse
    {
        $order = $this->ownedOrder($request, $orderNumber);
        $order = $this->reservations->cancel($request->user(), $order);

        return response()->json(['data' => $this->payload($order)]);
    }

    private function ownedOrder(Request $request, string $orderNumber): Order
    {
        return Order::query()
            ->where('user_id', $request->user()->getKey())
            ->where('order_number', $orderNumber)
            ->firstOrFail();
    }

    private function payload(Order $order): array
    {
        $order->loadMissing('items');

        return [
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'shipping_method' => $order->shipping_method,
            'coupon_code' => $order->coupon_code,
            'address' => $order->address_snapshot,
            'currency' => (string) config('shop.currency'),
            'subtotal_irr' => $order->subtotal_irr,
            'discount_irr' => $order->discount_irr,
            'shipping_irr' => $order->shipping_irr,
            'total_irr' => $order->total_irr,
            'reservation_expires_at' => $order->reservation_expires_at?->toISOString(),
            'paid_at' => $order->paid_at?->toISOString(),
            'cancelled_at' => $order->cancelled_at?->toISOString(),
            'items' => $order->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'variant_title' => $item->variant_title,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price_irr' => $item->unit_price_irr,
                'discount_irr' => $item->discount_irr,
                'line_total_irr' => $item->line_total_irr,
            ])->values(),
        ];
    }
}
