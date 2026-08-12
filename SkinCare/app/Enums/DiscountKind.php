<?php

namespace App\Enums;

enum DiscountKind: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
