<?php

namespace App\Enums;

enum DiscountRedemptionStatus: string
{
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';
}
