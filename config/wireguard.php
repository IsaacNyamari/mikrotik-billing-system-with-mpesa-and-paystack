<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The Management Tunnel
    |--------------------------------------------------------------------------
    |
    | Routers live on other people's connections: behind NAT, behind CGNAT,
    | on dynamic addresses, sometimes on a 4G dongle. None of them can be
    | reached from here, and asking an ISP to forward a port on every site is
    | both a security problem and a support burden.
    |
    | So the router dials out to us instead. WireGuard is connectionless and
    | keeps no state a NAT can time out, provided the router keeps the hole
    | open — which is what persistent-keepalive below is for.
    |
    */

    'enabled' => env('WIREGUARD_ENABLED', true),

    // Where routers dial. A hostname is better than an address: the server can
    // move without every deployed router needing its script re-pasted.
    'endpoint' => env('WIREGUARD_ENDPOINT', 'isp.solforbs.com'),
    'listen_port' => (int) env('WIREGUARD_PORT', 51820),

    // The platform's public key, from the server's own interface. Not a secret
    // — it goes into every router's configuration by design.
    'public_key' => env('WIREGUARD_PUBLIC_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Addressing
    |--------------------------------------------------------------------------
    |
    | One /24 across the whole platform, with the management server on .1.
    | Router addresses are allocated from .2 upwards and never reissued while
    | a router still holds one — a reused address would point provisioning at
    | somebody else's device.
    |
    */

    'subnet' => env('WIREGUARD_SUBNET', '10.8.0.0/24'),
    'server_address' => env('WIREGUARD_SERVER_ADDRESS', '10.8.0.1'),
    'first_host' => (int) env('WIREGUARD_FIRST_HOST', 2),
    'last_host' => (int) env('WIREGUARD_LAST_HOST', 254),

    // How often the router pokes the tunnel. NAT mappings commonly expire in
    // 30 seconds, so anything much longer means the platform can reach a
    // router only while it happens to be talking.
    'keepalive_seconds' => (int) env('WIREGUARD_KEEPALIVE', 25),

    /*
    |--------------------------------------------------------------------------
    | What the Generated Script Sets Up
    |--------------------------------------------------------------------------
    |
    | Defaults for the HotSpot the script builds. Every one of them is
    | overridable per router when it is generated, because an operator with an
    | existing network has already decided most of this.
    |
    */

    'defaults' => [
        'interface_name' => 'wg-platform',
        'bridge_name' => 'bridge-hotspot',
        'hotspot_name' => 'billing-hotspot',
        'pool_name' => 'hotspot-pool',
        'hotspot_network' => '10.10.10.0/24',
        'hotspot_gateway' => '10.10.10.1',
        'api_username' => 'platform',
        'api_group' => 'platform-api',
        // The interface customers plug into or associate with. Deliberately
        // not guessed from the model: putting the wrong port in a bridge takes
        // a router off the internet.
        'lan_interface' => 'ether3',
    ],

];
