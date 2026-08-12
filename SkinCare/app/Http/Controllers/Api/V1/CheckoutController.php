<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Commerce\CheckoutPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CheckoutController extends Controller
{
    public function __construct(private readonly CheckoutPricingService $pricing) {}

    public function quote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipping_method' => ['required', Rule::in(['standard', 'courier'])],
            'subtotal_irr' => ['prohibited'],
            'discount_irr' => ['prohibited'],
            'shipping_irr' => ['prohibited'],
            'total_irr' => ['prohibited'],
        ]);

        return response()->json([
            'data' => $this->pricing->quote($request->user(), $data['shipping_method']),
        ]);
    }
}
