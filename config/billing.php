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

];
