<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class DatabaseLike
{
    public static function caseInsensitiveOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }
}
