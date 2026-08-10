<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection Timeouts
    |--------------------------------------------------------------------------
    |
    | Provisioning runs while an operator waits, and a hotspot login runs while
    | a customer waits on a captive portal, so an unreachable router has to fail
    | quickly rather than hold the request open.
    |
    */

    'connect_timeout' => env('ROUTEROS_CONNECT_TIMEOUT', 5),

    'read_timeout' => env('ROUTEROS_READ_TIMEOUT', 10),

    'attempts' => env('ROUTEROS_ATTEMPTS', 1),

    /*
    |--------------------------------------------------------------------------
    | Default API Port
    |--------------------------------------------------------------------------
    |
    | 8728 is the plain API port and 8729 the TLS one. Both are closed on a
    | factory-default MikroTik and have to be enabled under /ip/service.
    |
    */

    'default_port' => 8728,

    'default_tls_port' => 8729,

];
