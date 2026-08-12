<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id', 'variant_id', 'product_name', 'variant_title', 'sku',
        'quantity', 'unit_price_irr', 'discount_irr', 'line_total_irr',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_irr' => 'integer',
            'discount_irr' => 'integer',
            'line_total_irr' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
