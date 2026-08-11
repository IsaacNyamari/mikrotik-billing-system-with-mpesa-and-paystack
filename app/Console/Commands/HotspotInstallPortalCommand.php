<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterOs\Contracts\RouterOsConnection;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\table;

/**
 * Points a MikroTik's HotSpot at the platform's captive portal.
 *
 * Two things have to be true before a customer ever sees the portal: the walled garden must
 * let an unauthenticated phone reach it, and the router must serve a login page that hands
 * the customer over. This does both, pulling the pages from the platform with /tool/fetch so
 * no file has to be uploaded by hand.
 */
class HotspotInstallPortalCommand extends Command
{
    protected $signature = 'hotspot:install-portal
        {router? : Router slug. Omit when the ISP has one.}
        {--tenant= : ISP slug. Required when more than one exists.}
        {--url= : Base URL the router should fetch from. Defaults to APP_URL.}
        {--dns-ip= : Resolve the portal hostname to this address on the router itself.}
        {--hotspot-dns= : Give the HotSpot itself a name, e.g. wifi.my-isp, instead of a bare IP.}';

    protected $description = 'Install the captive portal redirect and walled garden onto a router';

    public function handle(RouterOsConnector $connector): int
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        return Tenancy::forTenant($tenant, function () use ($connector): int {
            $router = Router::query()
                ->when($this->argument('router'), fn ($query, $slug) => $query->where('slug', $slug))
                ->first();

            if ($router === null) {
                $this->components->error('No router found for this ISP.');

                return self::FAILURE;
            }

            $base = rtrim((string) ($this->option('url') ?: config('app.url')), '/');
            $host = parse_url($base, PHP_URL_HOST);

            if (! is_string($host) || $host === '') {
                $this->components->error("Could not read a hostname out of [{$base}].");

                return self::FAILURE;
            }

            $this->components->info("Installing the portal onto {$router->name} from {$base} …");

            try {
                $connection = $connector->connect($router);
            } catch (Throwable $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $rows = [];

            // 0. A hostname is worth more than an address here: the portal URL is written into
            //    the router's login page, so pinning it to a DHCP-assigned laptop address means
            //    everything breaks the next time that lease changes. The router already answers
            //    DNS for HotSpot clients, so one static entry keeps the name pointing home.
            if ($this->option('dns-ip') !== null) {
                $rows[] = ["DNS {$host}", $this->resolveHostOnRouter($connection, $host, (string) $this->option('dns-ip'))];
            }

            // 1. An unauthenticated phone can reach nothing until the walled garden allows it,
            //    so without this the redirect lands on a page that will not load.
            $rows[] = ['Walled garden', $this->allowThrough($connection, $host)];

            // Safaricom's endpoints too, or the STK push confirmation cannot come back to a
            // customer who is still sitting unauthenticated on the portal.
            $rows[] = ['Walled garden (M-PESA)', $this->allowThrough($connection, '*.safaricom.co.ke')];

            // 2. The login page itself. /file was empty on this device, which is exactly why
            //    no sign-in prompt appeared: the router had nothing to serve.
            foreach (['login.html', 'alogin.html'] as $page) {
                $url = route('portal.mikrotik', ['portalKey' => $router->portal_key, 'page' => $page]);
                $url = str_replace(rtrim(config('app.url'), '/'), $base, $url);

                $rows[] = ["hotspot/{$page}", $this->fetch($connection, $url, "hotspot/{$page}")];
            }

            // 3. Point the HotSpot server profile at that directory, and optionally give the
            //    HotSpot a name of its own.
            $rows[] = ['HotSpot profile', $this->pointProfileAtDirectory($connection)];

            $connection->disconnect();

            table(['Step', 'Result'], $rows);

            $this->newLine();
            $this->line('  Customers will be sent to: <options=bold>'.$router->portalUrl().'</>');
            $this->newLine();
            $this->components->warn(
                'That URL has to be reachable from a phone on the HotSpot. A localhost address '.
                'will not be — use this machine\'s LAN address or a tunnel, and pass it with --url.',
            );

            return self::SUCCESS;
        });
    }

    /**
     * Point a hostname at an address in the router's own DNS.
     *
     * HotSpot clients have their DNS redirected to the router whatever resolver they were
     * handed, so a static entry here is what every phone on the network will see.
     */
    protected function resolveHostOnRouter(RouterOsConnection $connection, string $host, string $address): string
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return '<fg=yellow>skipped — the URL is already an address</>';
        }

        if (! filter_var($address, FILTER_VALIDATE_IP)) {
            return "<fg=red>{$address} is not an IP address</>";
        }

        try {
            $existing = $connection->first('/ip/dns/static', ['name' => $host]);

            if ($existing !== null) {
                $connection->update('/ip/dns/static', $existing['.id'], ['address' => $address]);

                return "<fg=green>updated → {$address}</>";
            }

            $connection->add('/ip/dns/static', [
                'name' => $host,
                'address' => $address,
                'comment' => 'ISP billing portal',
            ]);

            return "<fg=green>added → {$address}</>";
        } catch (Throwable $e) {
            return '<fg=red>'.$e->getMessage().'</>';
        }
    }

    protected function allowThrough(RouterOsConnection $connection, string $host): string
    {
        try {
            $existing = $connection->first('/ip/hotspot/walled-garden', ['dst-host' => $host]);

            if ($existing !== null) {
                return '<fg=yellow>already allowed</>';
            }

            $connection->add('/ip/hotspot/walled-garden', [
                'dst-host' => $host,
                'action' => 'allow',
                'comment' => 'ISP billing portal',
            ]);

            return '<fg=green>allowed</>';
        } catch (Throwable $e) {
            return '<fg=red>'.$e->getMessage().'</>';
        }
    }

    /**
     * Pull a file onto the router. /tool/fetch runs on the device, so the URL has to be
     * reachable from the router rather than from here.
     */
    protected function fetch(RouterOsConnection $connection, string $url, string $destination): string
    {
        try {
            $connection->execute('/tool/fetch', [
                'url' => $url,
                'dst-path' => $destination,
                'mode' => str_starts_with($url, 'https') ? 'https' : 'http',
                // The router validates certificates by default and a dev tunnel's chain may
                // not check out; the file is public HTML either way.
                'check-certificate' => 'no',
            ]);

            return '<fg=green>installed</>';
        } catch (Throwable $e) {
            return '<fg=red>'.$e->getMessage().'</>';
        }
    }

    protected function pointProfileAtDirectory(RouterOsConnection $connection): string
    {
        try {
            $servers = $connection->get('/ip/hotspot');

            if ($servers === []) {
                return '<fg=red>no HotSpot server on this device</>';
            }

            $profileName = $servers[0]['profile'] ?? 'default';
            $profile = $connection->first('/ip/hotspot/profile', ['name' => $profileName]);

            if ($profile === null) {
                return '<fg=red>profile '.$profileName.' not found</>';
            }

            $attributes = [
                'html-directory' => 'hotspot',
                // The portal posts a plain username and password, so PAP has to be on the list.
                'login-by' => 'http-chap,http-pap',
            ];

            $dnsName = $this->option('hotspot-dns');

            if ($dnsName !== null) {
                // With a dns-name set, RouterOS serves the HotSpot under that name and hands
                // it to clients as link-login-only, instead of a bare 10.10.10.1. It also
                // registers the name in its own DNS, so phones on the HotSpot resolve it.
                $attributes['dns-name'] = $dnsName;
            }

            $connection->update('/ip/hotspot/profile', $profile['.id'], $attributes);

            return '<fg=green>'.$profileName.' → hotspot/'
                .($dnsName !== null ? ", dns-name={$dnsName}" : '').'</>';
        } catch (Throwable $e) {
            return '<fg=red>'.$e->getMessage().'</>';
        }
    }

    protected function tenant(): ?Tenant
    {
        $slug = $this->option('tenant');

        if ($slug !== null) {
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if ($tenant === null) {
                $this->components->error("No ISP with slug [{$slug}].");
            }

            return $tenant;
        }

        $tenants = Tenant::query()->limit(2)->get();

        if ($tenants->count() === 1) {
            return $tenants->first();
        }

        $this->components->error(
            $tenants->isEmpty() ? 'No ISPs exist yet.' : 'More than one ISP exists — say which with --tenant.',
        );

        return null;
    }
}
