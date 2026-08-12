<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'address_id', 'order_number', 'idempotency_key', 'status',
        'shipping_method', 'address_snapshot', 'subtotal_irr', 'discount_irr',
        'shipping_irr', 'total_irr', 'reservation_expires_at', 'paid_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'address_snapshot' => 'array',
            'subtotal_irr' => 'integer',
            'discount_irr' => 'integer',
            'shipping_irr' => 'integer',
            'total_irr' => 'integer',
            'reservation_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
