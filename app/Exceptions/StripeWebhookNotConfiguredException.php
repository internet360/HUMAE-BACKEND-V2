<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/** The webhook signing secret is missing: a deployment problem, not a bad request. */
class StripeWebhookNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Stripe webhook secret is not configured.');
    }
}
