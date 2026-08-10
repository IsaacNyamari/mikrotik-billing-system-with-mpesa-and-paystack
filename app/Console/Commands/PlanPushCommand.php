<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterOs\PlanProfileSynchroniser;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

use function Laravel\Prompts\table;

/**
 * Pushes plans onto routers as RouterOS profiles.
 */
class PlanPushCommand extends Command
{
    protected $signature = 'plan:push
        {plan? : Plan code. Omit to push every active plan.}
        {--router= : Router slug. Omit to push to every router.}
        {--tenant= : Tenant slug. Required when more than one ISP exists.}';

    protected $description = 'Copy plans onto MikroTik routers as HotSpot or PPP profiles';

    public function handle(PlanProfileSynchroniser $sync): int
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return self::FAILURE;
        }

        return Tenancy::forTenant($tenant, function () use ($sync): int {
            $plans = Plan::query()
                ->when($this->argument('plan'), fn ($query, $code) => $query->where('code', $code))
                ->when(! $this->argument('plan'), fn ($query) => $query->where('is_active', true))
                ->get();

            $routers = Router::query()
                ->when($this->option('router'), fn ($query, $slug) => $query->where('slug', $slug))
                ->get();

            if ($plans->isEmpty() || $routers->isEmpty()) {
                $this->components->error('Nothing to do — check that the plan and router exist for this ISP.');

                return self::FAILURE;
            }

            $rows = [];
            $failed = 0;

            foreach ($plans as $plan) {
                foreach ($routers as $router) {
                    $pairing = $sync->sync($plan, $router);

                    $rows[] = [
                        $plan->name,
                        $router->name,
                        $pairing->profile_name,
                        $pairing->isSynced()
                            ? '<fg=green>pushed</>'
                            : '<fg=red>'.$pairing->sync_error.'</>',
                    ];

                    $failed += $pairing->isSynced() ? 0 : 1;
                }
            }

            table(['Plan', 'Router', 'Profile', 'Result'], $rows);

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        });
    }

    protected function tenant(): ?Tenant
    {
        $slug = $this->option('tenant');

        if ($slug !== null) {
            return Tenant::query()->where('slug', $slug)->first()
                ?? tap(null, fn () => $this->components->error("No ISP with slug [{$slug}]."));
        }

        $tenants = Tenant::query()->limit(2)->get();

        if ($tenants->count() === 1) {
            return $tenants->first();
        }

        $this->components->error(
            $tenants->isEmpty()
                ? 'No ISPs exist yet.'
                : 'More than one ISP exists — say which with --tenant.',
        );

        return null;
    }
}
