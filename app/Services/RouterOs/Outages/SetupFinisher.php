<?php

namespace App\Services\RouterOs\Outages;

use App\Jobs\SetUpHotspot;
use App\Models\PortalSetting;
use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\HotspotInstaller;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Finishes a router's setup from the platform side, the first time it is reachable.
 *
 * Pasting the script is meant to be the last thing an ISP does with their hands. The
 * router's own first boot builds the HotSpot, but a first boot runs unwatched on hardware
 * the platform has never seen, and anything it could not finish used to become a manual job:
 * a support call, a second paste, the HotSpot setup screen. Once the router answers down the
 * tunnel there is no reason for any of that. If its HotSpot is missing or broken, the platform
 * builds it over the API with the choices already made on the setup page — and if it is there
 * and healthy, records that, so the setup page can say "ready".
 *
 * Only for routers built by the platform's own wipe-and-rebuild, so a router an ISP set up by
 * hand is never given a HotSpot it did not ask for. Tried at most once every fifteen minutes.
 */
class SetupFinisher
{
    public function __construct(protected RouterOsConnector $connector) {}

    public function finish(Router $router): void
    {
        $options = $router->setupOptions();

        if ($router->config_downloaded_at === null || $router->hotspot_ready_at !== null || ($options['with_hotspot'] ?? true) !== true) {
            return;
        }

        try {
            $connection = $this->connector->connect($router);
            $servers = $connection->get('/ip/hotspot');
            $connection->disconnect();
        } catch (Throwable) {
            return;
        }

        $healthy = collect($servers)->contains(fn (array $server): bool => ($server['invalid'] ?? 'false') !== 'true' && ($server['disabled'] ?? 'false') !== 'true');

        if ($healthy) {
            $router->hotspot_ready_at = now();
            $router->save();

            return;
        }

        if (! Cache::add('hotspot-autosetup:'.$router->id, true, now()->addMinutes(15))) {
            return;
        }

        $network = (string) ($options['hotspot_network'] ?? '10.10.10.0/24');
        $prefix = str_contains($network, '/') ? explode('/', $network)[1] : '24';
        $withWifi = ($options['with_wireless'] ?? true) === true;

        $setup = [
            'interface' => (string) ($options['lan_interface'] ?? 'ether3'),
            'address' => ((string) ($options['hotspot_gateway'] ?? '10.10.10.1')).'/'.$prefix,
            'pool' => (string) ($options['hotspot_pool'] ?? ''),
            'dns_servers' => (string) ($options['hotspot_dns_servers'] ?? '1.1.1.1,8.8.8.8'),
            'dns_name' => (string) ($options['hotspot_dns_name'] ?? ''),
            'masquerade' => true,
            'name' => (string) ($options['hotspot_name'] ?? 'billing-hotspot'),
            'wifi_name' => $withWifi
                ? HotspotInstaller::wifiName((string) ($options['ssid'] ?? PortalSetting::current()->displayName($router->tenant)))
                : '',
        ];

        Cache::put(SetUpHotspot::cacheKey($router->id), ['status' => 'running', 'started_at' => now()->toIso8601String()], now()->addHour());
        SetUpHotspot::dispatch($router->tenant_id, $router->id, $setup);
    }
}
