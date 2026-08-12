<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'recipient_name', 'mobile', 'province', 'city',
        'postal_code', 'address_line', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function snapshot(): array
    {
        return $this->only([
            'title', 'recipient_name', 'mobile', 'province', 'city', 'postal_code', 'address_line',
        ]);
    }
}
