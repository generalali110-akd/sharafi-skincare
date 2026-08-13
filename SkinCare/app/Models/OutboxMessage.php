<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'topic', 'event_key', 'aggregate_type', 'aggregate_id', 'payload', 'attempts',
        'available_at', 'expires_at', 'locked_at', 'processed_at', 'failed_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'expires_at' => 'datetime',
            'locked_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
