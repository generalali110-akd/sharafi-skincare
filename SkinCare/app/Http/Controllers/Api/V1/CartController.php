<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\ProductVariant;
use App\Services\Commerce\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->carts->get($request->user()))]);
    }

    public function setItem(Request $request, ProductVariant $variant): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.config('shop.max_item_quantity')],
            'price_irr' => ['prohibited'],
            'unit_price_irr' => ['prohibited'],
            'discount_irr' => ['prohibited'],
            'shipping_irr' => ['prohibited'],
            'total_irr' => ['prohibited'],
        ]);

        $cart = $this->carts->setQuantity($request->user(), $variant, (int) $data['quantity']);

        return response()->json(['data' => $this->payload($cart)]);
    }

    public function removeItem(Request $request, ProductVariant $variant): JsonResponse
    {
        $cart = $this->carts->remove($request->user(), $variant);

        return response()->json(['data' => $this->payload($cart)]);
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->carts->clear($request->user());

        return response()->json(['data' => $this->payload($cart)]);
    }

    private function payload(Cart $cart): array
    {
        return [
            'id' => $cart->exists ? $cart->id : null,
            'items' => $cart->items->map(fn ($item) => [
                'variant_id' => $item->variant_id,
                'quantity' => $item->quantity,
                'product' => [
                    'name' => $item->variant->product->name,
                    'slug' => $item->variant->product->slug,
                ],
                'variant' => [
                    'title' => $item->variant->title,
                    'sku' => $item->variant->sku,
                    'price_irr' => $item->variant->price_irr,
                    'in_stock' => (bool) $item->variant->inventory?->available,
                ],
            ])->values(),
        ];
    }
}
