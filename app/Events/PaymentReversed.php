<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Payment;

/**
 * Dispatched synchronously, inside the reversal transaction, once a payment has
 * been revoked (full refund or lost dispute). Listeners therefore run in the same
 * transaction and must stay cheap and idempotent.
 */
final class PaymentReversed
{
    public function __construct(
        public readonly Payment $payment,
        public readonly string $reason,
    ) {}
}
