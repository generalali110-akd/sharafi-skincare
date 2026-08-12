<?php

namespace App\Models;

use App\Enums\DiscountRedemptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiscountRedemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'discount_rule_id', 'user_id', 'order_id', 'status', 'discount_irr',
        'reserved_at', 'consumed_at', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => DiscountRedemptionStatus::class,
            'discount_irr' => 'integer',
            'reserved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(DiscountRule::class, 'discount_rule_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
