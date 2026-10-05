<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Payment;

/**
 * Dispatched synchronously, inside the reversal transaction, once a payment has
 * been revoked (full refund or lost dispute). Listeners therefore run in the same
 * transaction and must stay cheap and idempotent. `stripeEventId` is the webhook
 * event that caused it (null when revoked outside a webhook), for audit trails.
 */
final class PaymentReversed
{
    public function __construct(
        public readonly Payment $payment,
        public readonly string $reason,
        public readonly ?string $stripeEventId = null,
    ) {}
}
