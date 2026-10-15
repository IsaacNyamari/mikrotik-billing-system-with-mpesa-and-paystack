<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // The platform's own HostPinnacle account: used for an ISP that has not entered one of its
    // own under Settings → SMS.
    'hostpinnacle' => [
        'api_key' => env('HOSTPINNACLE_API_KEY'),
        'sender_id' => env('HOSTPINNACLE_SENDER_ID'),
        'username' => env('HOSTPINNACLE_LOGIN_USERNAME'),
        'password' => env('HOSTPINNACLE_LOGIN_PASSWORD'),
        'base_url' => env('HOSTPINNACLE_BASE_URL', 'https://smsportal.hostpinnacle.co.ke/SMSApi'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
