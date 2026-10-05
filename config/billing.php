<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Billing contact
    |--------------------------------------------------------------------------
    | Mailbox that receives billing alerts (refund/dispute flags). When empty
    | the alert is only logged; reversals never fail because of it.
    */
    'email' => env('BILLING_EMAIL'),

    /*
    |--------------------------------------------------------------------------
    | Reversal retry window
    |--------------------------------------------------------------------------
    | A refund/dispute for one of our charges that matches no payment is
    | answered with 500 (Stripe retries) while the Stripe event is younger than
    | this many hours, to cover events that arrive before the checkout
    | completion. Past it, billing is alerted and the event is acknowledged.
    */
    'reversal_retry_window_hours' => (int) env('BILLING_REVERSAL_RETRY_WINDOW_HOURS', 24),

];
