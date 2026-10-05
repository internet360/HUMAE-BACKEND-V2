<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A payment cannot back an invoice request. `reason` is one of: not_found,
 * not_eligible (refunded, disputed, unpaid), claimed, deadline.
 */
class PaymentNotEligibleException extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("Payment not eligible for an invoice request: {$reason}");
    }
}
