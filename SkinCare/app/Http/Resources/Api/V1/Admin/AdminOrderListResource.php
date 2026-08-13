<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Support\IranMobile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource->relationLoaded('user') ? $this->user : null;
        $payment = $this->resource->relationLoaded('payment') ? $this->payment : null;

        return [
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'customer' => $user ? [
                'name' => $user->name,
                'mobile' => IranMobile::mask((string) $user->mobile),
            ] : null,
            'item_count' => (int) ($this->items_count ?? 0),
            'shipping_method' => $this->shipping_method,
            'amounts' => [
                'subtotal_irr' => $this->subtotal_irr,
                'discount_irr' => $this->discount_irr,
                'shipping_irr' => $this->shipping_irr,
                'total_irr' => $this->total_irr,
                'currency' => 'IRR',
            ],
            'payment' => $payment ? [
                'status' => $payment->status->value,
                'provider' => $payment->provider,
                'paid_at' => $payment->paid_at?->toISOString(),
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
