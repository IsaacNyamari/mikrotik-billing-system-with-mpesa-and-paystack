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

        // The port the router's own internet arrives on. Only used when the
        // script wipes the device: a reset leaves no DHCP client behind, and a
        // router with no way out never reaches us again.
        'wan_interface' => 'ether1',

        // The wifi customers actually see. A reset leaves the radio switched
        // off and nameless, so a HotSpot built without this is one nobody can
        // find: there is nothing to connect to.
        //
        // The network is deliberately open. The HotSpot is what authenticates
        // people, and a wifi password would mean turning somebody away before
        // they have had the chance to buy anything.
        'wireless_interface' => 'wlan1',
        'ssid' => null,

        // PPPoE. Its own interface by default, because a HotSpot and a PPPoE
        // server on the same segment both try to answer the same client.
        'pppoe_interface' => 'ether4',
        'pppoe_network' => '10.20.0.0/24',
        'pppoe_gateway' => '10.20.0.1',
        'pppoe_service_name' => 'isp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Provisioning Links
    |--------------------------------------------------------------------------
    |
    | A wiped router fetches its configuration from us before it has any
    | credentials, so the link is public and guarded only by its token. It is
    | single-use and lives just long enough for an operator to walk from the
    | screen to the device.
    |
    */

    'provision_link_minutes' => (int) env('WIREGUARD_PROVISION_LINK_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | Loading Peers Without a Terminal
    |--------------------------------------------------------------------------
    |
    | Adding a peer to a running tunnel needs root, which the web user must not
    | have. This is the one command it is allowed to run: a wrapper taking no
    | arguments that only syncs peers already in the database, permitted by a
    | single sudoers line. Nothing is passed to it, so there is nothing to
    | inject into.
    |
    | Empty means an operator has to run `php artisan wireguard:peers --apply`
    | on the server themselves, and the screen says so rather than offering a
    | button that quietly fails.
    |
    */

    'sync_command' => env('WIREGUARD_SYNC_COMMAND', ''),

];
