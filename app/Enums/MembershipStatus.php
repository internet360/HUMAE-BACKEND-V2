<?php

declare(strict_types=1);

namespace App\Enums;

enum MembershipStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
}
