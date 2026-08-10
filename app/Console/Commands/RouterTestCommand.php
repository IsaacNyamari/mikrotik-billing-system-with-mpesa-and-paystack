<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\RouterHealthProbe;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

use function Laravel\Prompts\table;

/**
 * Connection diagnostics for a MikroTik, meant to be run from the terminal while setting a
 * router up. It reports what the platform can and cannot do with the credentials given.
 */
class RouterTestCommand extends Command
{
    protected $signature = 'router:test
        {router? : Router slug. Omit to test every router.}
        {--tenant= : Tenant slug, when a slug exists in more than one ISP}';

    protected $description = 'Contact a MikroTik over the RouterOS API and report what it says';

    public function handle(RouterHealthProbe $probe, RouterOsConnector $connector): int
    {
        $routers = $this->routers();

        if ($routers === []) {
            $this->components->error('No routers found. Add one first, or check the --tenant option.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($routers as $router) {
            $this->components->info(sprintf('Contacting %s at %s:%d …', $router->name, $router->host, $router->api_port));

            $probe->probe($router);

            if (! $router->status->isReachable()) {
                $this->components->error(sprintf('%s — %s', $router->status->label(), $router->last_error));
                $failed++;

                continue;
            }

            $this->components->info('Connected.');

            table(
                ['Property', 'Value'],
                [
                    ['Identity', $router->identity ?? '—'],
                    ['Model', $router->model ?? '—'],
                    ['Serial', $router->serial_number ?? '—'],
                    ['RouterOS', $router->os_version ?? '—'],
                    ['Uptime', $router->uptimeForHumans() ?? '—'],
                    ['CPU load', $router->cpu_load !== null ? $router->cpu_load.'%' : '—'],
                    ['Memory used', $router->memoryUsedPercent() !== null ? $router->memoryUsedPercent().'%' : '—'],
                ],
            );

            $this->reportCapabilities($router, $connector);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Check the menus the platform actually writes to, so a permissions problem shows up here
     * rather than halfway through provisioning a paying customer.
     */
    protected function reportCapabilities(Router $router, RouterOsConnector $connector): void
    {
        $checks = [
            'HotSpot servers' => '/ip/hotspot',
            'HotSpot user profiles' => '/ip/hotspot/user/profile',
            'HotSpot users' => '/ip/hotspot/user',
            'HotSpot active sessions' => '/ip/hotspot/active',
            'PPP profiles' => '/ppp/profile',
            'PPP secrets' => '/ppp/secret',
            'PPPoE servers' => '/interface/pppoe-server/server',
        ];

        $connection = $connector->connect($router);
        $rows = [];

        foreach ($checks as $label => $path) {
            try {
                $rows[] = [$label, '<fg=green>readable</>', (string) count($connection->get($path))];
            } catch (Throwable $e) {
                $rows[] = [$label, '<fg=red>failed</>', $e->getMessage()];
            }
        }

        $connection->disconnect();

        table(['Menu', 'Access', 'Rows'], $rows);

        $this->components->info(
            'A zero row count is fine for users and secrets. HotSpot servers or PPPoE servers '.
            'showing zero means that service is not set up on the router yet.',
        );
    }

    /**
     * @return array<int, Router>
     */
    protected function routers(): array
    {
        $slug = $this->argument('router');
        $tenantSlug = $this->option('tenant');

        // The console has no signed-in user, so tenant scoping has to be declared here rather
        // than inherited from a request.
        if ($tenantSlug !== null) {
            $tenant = Tenant::query()->where('slug', $tenantSlug)->firstOrFail();

            return Tenancy::forTenant($tenant, fn (): array => Router::query()
                ->when($slug !== null, fn ($query) => $query->where('slug', $slug))
                ->get()
                ->all());
        }

        return Tenancy::withoutScope(fn (): array => Router::query()
            ->when($slug !== null, fn ($query) => $query->where('slug', $slug))
            ->get()
            ->all());
    }
}
