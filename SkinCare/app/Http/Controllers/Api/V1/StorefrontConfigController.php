<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class StorefrontConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'currency' => (string) config('shop.currency'),
                'cart' => [
                    'max_item_quantity' => (int) config('shop.max_item_quantity'),
                ],
                'shipping' => [
                    'free_threshold_irr' => (int) config('shop.free_shipping_threshold_irr'),
                    'standard_irr' => (int) config('shop.standard_shipping_irr'),
                    'courier_irr' => (int) config('shop.courier_shipping_irr'),
                ],
            ],
        ]);
    }
}
