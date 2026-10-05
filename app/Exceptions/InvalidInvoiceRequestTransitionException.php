<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\InvoiceRequestStatus;
use RuntimeException;

/** The invoice request cannot move from its current status to the requested one. */
class InvalidInvoiceRequestTransitionException extends RuntimeException
{
    public function __construct(public readonly InvoiceRequestStatus $from, public readonly InvoiceRequestStatus $to)
    {
        parent::__construct("Invoice request cannot move from {$from->value} to {$to->value}.");
    }
}
