<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What the Platform Charges an ISP
    |--------------------------------------------------------------------------
    |
    | Defaults only. Every subscription copies these at the moment it is
    | created, so changing a price here never silently re-prices an ISP who
    | signed up under the old one — the rate an ISP agreed to is the rate on
    | their own row.
    |
    */

    'pricing' => [

        // One-off, and the gate on the whole system: an ISP cannot operate
        // until it is settled.
        'onboarding_fee' => env('PLATFORM_ONBOARDING_FEE', 2000),

        // Share of what an ISP collects through its HotSpot portal, charged
        // monthly in arrears on money that has actually moved. Held between
        // the published bounds so a typo cannot bill someone 50%.
        'hotspot_commission_rate' => env('PLATFORM_HOTSPOT_RATE', 5.0),
        'hotspot_commission_min' => 3.0,
        'hotspot_commission_max' => 5.0,

        // Per active PPPoE line per month. A subscriber line is a standing
        // relationship, so it is priced per head rather than per shilling.
        'pppoe_monthly_fee' => env('PLATFORM_PPPOE_FEE', 15),

        'currency' => env('PLATFORM_CURRENCY', 'KES'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Terms
    |--------------------------------------------------------------------------
    |
    | An invoice is issued for the month just ended and falls due after this
    | many days. The grace period runs from the due date: an ISP is warned
    | throughout it and only loses the console when it expires, because a
    | platform that locks an operator out the morning a payment is late costs
    | their customers service over a matter of hours.
    |
    */

    'terms' => [
        'due_after_days' => env('PLATFORM_DUE_AFTER_DAYS', 7),
        'grace_days' => env('PLATFORM_GRACE_DAYS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | The Platform's Own Till
    |--------------------------------------------------------------------------
    |
    | Distinct from the per-ISP credentials in `mpesa_configurations`: those
    | collect an ISP's money from its own customers, these collect the
    | platform's money from the ISP. One set for the whole platform, so unlike
    | tenant credentials they belong in the environment.
    |
    | The callback token is the only thing authenticating Safaricom's callback,
    | exactly as it is for tenant collections, so it must be unguessable.
    |
    */

    'mpesa' => [
        'enabled' => env('PLATFORM_MPESA_ENABLED', false),
        'environment' => env('PLATFORM_MPESA_ENV', 'sandbox'),
        'consumer_key' => env('PLATFORM_MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('PLATFORM_MPESA_CONSUMER_SECRET'),
        'short_code' => env('PLATFORM_MPESA_SHORT_CODE'),
        'passkey' => env('PLATFORM_MPESA_PASSKEY'),
        'transaction_type' => env('PLATFORM_MPESA_TRANSACTION_TYPE', 'CustomerPayBillOnline'),
        'account_reference' => env('PLATFORM_MPESA_ACCOUNT_REFERENCE', 'ISPPLATFORM'),
        'callback_token' => env('PLATFORM_MPESA_CALLBACK_TOKEN'),
    ],

];
