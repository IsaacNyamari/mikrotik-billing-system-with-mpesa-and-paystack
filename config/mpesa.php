<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Request Timeouts
    |--------------------------------------------------------------------------
    |
    | A customer is standing at a captive portal waiting for their phone to
    | buzz, so a slow Daraja has to give up rather than hold the page.
    |
    */

    'connect_timeout' => env('MPESA_CONNECT_TIMEOUT', 5),

    'timeout' => env('MPESA_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | How Long to Wait for a Customer
    |--------------------------------------------------------------------------
    |
    | Safaricom's prompt expires after about a minute. After this many minutes
    | with no callback the payment is treated as abandoned and the portal stops
    | polling.
    |
    */

    'abandon_after_minutes' => env('MPESA_ABANDON_AFTER_MINUTES', 3),

];
