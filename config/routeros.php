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
    | The connect timeout is short because a router that is genuinely down is
    | down now, not in five seconds. What it must not do is give up on a router
    | that is merely slow to answer the first packet — a WireGuard tunnel whose
    | session keys have expired re-handshakes before the TCP SYN gets through,
    | and that first packet is sometimes simply lost. So the timeout is short
    | and there is a second attempt behind it: a live router answers the retry
    | in milliseconds, and a dead one has still given up inside eight seconds.
    |
    */

    'connect_timeout' => env('ROUTEROS_CONNECT_TIMEOUT', 3),

    'read_timeout' => env('ROUTEROS_READ_TIMEOUT', 8),

    'attempts' => env('ROUTEROS_ATTEMPTS', 2),

    // Seconds between attempts. One is enough for a tunnel to finish a
    // handshake that was in flight when the first attempt went out.
    'retry_delay' => env('ROUTEROS_RETRY_DELAY', 1),

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
