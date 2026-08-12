<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OtpChallenge extends Model
{
    use HasUuids;

    protected $fillable = [
        'mobile',
        'purpose',
        'code_hash',
        'context',
        'attempt_count',
        'max_attempts',
        'expires_at',
        'consumed_at',
    ];

    protected $hidden = [
        'code_hash',
        'context',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'encrypted:array',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempt_count' => 'integer',
            'max_attempts' => 'integer',
        ];
    }
}
