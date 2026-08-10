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

    /*
    |--------------------------------------------------------------------------
    | Credentials for mpesa:configure
    |--------------------------------------------------------------------------
    |
    | Only read by the setup command, which writes them into the tenant's own
    | encrypted configuration row. Credentials live here rather than being
    | passed as command arguments so they stay out of shell history, and are
    | read through config() rather than env() so a cached config does not
    | silently turn them into nulls.
    |
    */

    'credentials' => [
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'short_code' => env('MPESA_SHORT_CODE'),
        'passkey' => env('MPESA_PASSKEY'),
        'account_reference' => env('MPESA_ACCOUNT_REFERENCE', 'INTERNET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Safaricom's Published Sandbox Values
    |--------------------------------------------------------------------------
    |
    | These are documented publicly by Safaricom and are the same for everyone,
    | so only a consumer key and secret are needed to start testing. The test
    | subscriber always approves the prompt.
    |
    */

    'sandbox' => [
        'short_code' => '174379',
        'passkey' => 'bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919',
        'test_msisdn' => '254708374149',
    ],

];
