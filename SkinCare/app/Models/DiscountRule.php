<?php

namespace App\Models;

use App\Enums\DiscountKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiscountRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'kind', 'value', 'min_subtotal_irr', 'max_discount_irr',
        'starts_at', 'ends_at', 'usage_limit_total', 'usage_limit_per_user',
        'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'value' => 'integer',
            'min_subtotal_irr' => 'integer',
            'max_discount_irr' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit_total' => 'integer',
            'usage_limit_per_user' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(DiscountRedemption::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
