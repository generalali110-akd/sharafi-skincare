<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Support\IranMobile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOrderDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource->relationLoaded('user') ? $this->user : null;
        $payment = $this->resource->relationLoaded('payment') ? $this->payment : null;

        return [
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'customer' => $user ? [
                'id' => $user->getKey(),
                'name' => $user->name,
                'mobile' => IranMobile::mask((string) $user->mobile),
            ] : null,
            'shipping_method' => $this->shipping_method,
            'address' => $this->address_snapshot,
            'coupon_code' => $this->coupon_code,
            'amounts' => [
                'subtotal_irr' => $this->subtotal_irr,
                'discount_irr' => $this->discount_irr,
                'shipping_irr' => $this->shipping_irr,
                'total_irr' => $this->total_irr,
                'currency' => 'IRR',
            ],
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'product_name' => $item->product_name,
                'variant_title' => $item->variant_title,
                'sku' => $item->sku,
                'quantity' => $item->quantity,
                'unit_price_irr' => $item->unit_price_irr,
                'discount_irr' => $item->discount_irr,
                'line_total_irr' => $item->line_total_irr,
            ])->values()),
            'payment' => $payment ? [
                'status' => $payment->status->value,
                'provider' => $payment->provider,
                'amount_irr' => $payment->amount_irr,
                'currency' => $payment->currency,
                'paid_at' => $payment->paid_at?->toISOString(),
                'refunded_at' => $payment->refunded_at?->toISOString(),
                'attempts' => $payment->relationLoaded('attempts')
                    ? $payment->attempts->map(fn ($attempt): array => [
                        'public_id' => $attempt->public_id,
                        'status' => $attempt->status->value,
                        'provider' => $attempt->provider,
                        'transaction_id' => $attempt->transaction_id,
                        'failure_code' => $attempt->failure_code,
                        'verified_at' => $attempt->verified_at?->toISOString(),
                        'created_at' => $attempt->created_at?->toISOString(),
                    ])->values()
                    : [],
            ] : null,
            'timeline' => $this->timeline(),
            'reservation_expires_at' => $this->reservation_expires_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'processing_at' => $this->processing_at?->toISOString(),
            'shipped_at' => $this->shipped_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function timeline(): array
    {
        $events = collect();

        if ($this->resource->relationLoaded('statusTransitions')) {
            foreach ($this->statusTransitions as $transition) {
                $events->push([
                    'kind' => 'order_status',
                    'at' => $transition->created_at?->toISOString(),
                    'from_status' => $transition->from_status?->value,
                    'to_status' => $transition->to_status->value,
                    'reason' => $transition->reason,
                    'actor' => $transition->relationLoaded('actor') && $transition->actor ? [
                        'id' => $transition->actor->getKey(),
                        'name' => $transition->actor->name,
                    ] : null,
                ]);
            }
        }

        $payment = $this->resource->relationLoaded('payment') ? $this->payment : null;
        if ($payment?->relationLoaded('events')) {
            foreach ($payment->events as $event) {
                $events->push([
                    'kind' => 'payment',
                    'at' => $event->occurred_at?->toISOString(),
                    'provider' => $event->provider,
                    'event_type' => $event->event_type,
                    'transaction_id' => $event->relationLoaded('attempt') ? $event->attempt?->transaction_id : null,
                ]);
            }
        }

        if ($this->resource->relationLoaded('notificationEvents')) {
            foreach ($this->resource->getRelation('notificationEvents') as $event) {
                $events->push([
                    'kind' => 'notification',
                    'at' => $event->created_at?->toISOString(),
                    'channel' => $event->topic,
                    'template' => $event->payload['template'] ?? null,
                    'status' => $event->processed_at
                        ? 'sent'
                        : ($event->failed_at ? 'failed' : 'pending'),
                    'attempts' => $event->attempts,
                    'processed_at' => $event->processed_at?->toISOString(),
                    'failed_at' => $event->failed_at?->toISOString(),
                ]);
            }
        }

        return $events
            ->sortBy(fn (array $event): string => (string) ($event['at'] ?? ''))
            ->values()
            ->all();
    }
}
