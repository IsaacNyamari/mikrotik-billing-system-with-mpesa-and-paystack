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
        {--url= : Base URL the router should fetch from. Defaults to APP_URL.}';

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

            // 3. Point the HotSpot server profile at that directory.
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

            $connection->update('/ip/hotspot/profile', $profile['.id'], [
                'html-directory' => 'hotspot',
                // The portal posts a plain username and password, so PAP has to be on the list.
                'login-by' => 'http-chap,http-pap',
            ]);

            return '<fg=green>'.$profileName.' → hotspot/</>';
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
