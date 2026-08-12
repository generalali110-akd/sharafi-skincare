<?php

namespace App\Models;

use App\Enums\PaymentAttemptStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id', 'attempt_number', 'public_id', 'idempotency_key_hash', 'provider',
        'status', 'amount_irr', 'authority', 'transaction_id', 'redirect_url', 'failure_code',
        'failure_message', 'requested_at', 'verified_at', 'failed_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'status' => PaymentAttemptStatus::class,
            'amount_irr' => 'integer',
            'requested_at' => 'datetime',
            'verified_at' => 'datetime',
            'failed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class, 'attempt_id');
    }
}
