<?php

namespace App\Enums;

enum PaymentAttemptStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
