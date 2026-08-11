<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\Provisioning\RouterSetupScript;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

/**
 * Prints the setup script for a router, for an operator who lives in a terminal.
 *
 * The same generator the screen uses, so there is one description of what a ready router
 * looks like rather than two that drift.
 */
class RouterSetupScriptCommand extends Command
{
    protected $signature = 'router:setup-script
        {router : Router slug.}
        {--tenant= : ISP slug. Required when more than one exists.}
        {--lan= : The interface customers connect to, e.g. ether3 or wlan1.}
        {--no-hotspot : Set up the tunnel and API only, leaving the network alone.}';

    protected $description = 'Print the paste-once setup script for a router';

    public function handle(RouterSetupScript $scripts): int
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        return Tenancy::forTenant($tenant, function () use ($scripts): int {
            $router = Router::query()->where('slug', $this->argument('router'))->first();

            if ($router === null) {
                $this->components->error('No router with that slug for this ISP.');

                return self::FAILURE;
            }

            $options = ['with_hotspot' => ! $this->option('no-hotspot')];

            if (is_string($this->option('lan')) && $this->option('lan') !== '') {
                $options['lan_interface'] = (string) $this->option('lan');
            }

            // Straight to stdout with no decoration: this gets piped, redirected and pasted.
            $this->output->writeln($scripts->for($router, $options));

            return self::SUCCESS;
        });
    }

    protected function tenant(): ?Tenant
    {
        $slug = $this->option('tenant');

        $tenants = Tenancy::withoutScope(fn () => Tenant::query()
            ->when(is_string($slug) && $slug !== '', fn ($query) => $query->where('slug', $slug))
            ->get());

        if ($tenants->count() === 1) {
            return $tenants->first();
        }

        $this->components->error($tenants->isEmpty()
            ? 'No ISP found.'
            : 'More than one ISP exists — say which with --tenant.');

        return null;
    }
}
