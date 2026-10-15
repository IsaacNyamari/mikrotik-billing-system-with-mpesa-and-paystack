<?php

namespace App\Jobs;

use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterOs\Outages\RouterWatcher;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One router's minute: is it up, did it just come back, who is online on it.
 *
 * Queued, one job per router, because a router that is down costs the connect timeout twice
 * over before it is declared so. Watched in a single loop, a handful of dead sites would push
 * the healthy ones past their minute — and the healthy ones are where customers are paying.
 *
 * Carries ids rather than the model: restoring a tenant-owned model before a tenant is bound
 * is exactly what strict scoping refuses.
 */
class WatchRouter implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** A run that has not finished in this long has stalled; the next minute's can have it. */
    public int $uniqueFor = 120;

    public int $timeout = 110;

    public int $tries = 1;

    public function __construct(public int $tenantId, public int $routerId) {}

    public function uniqueId(): string
    {
        return (string) $this->routerId;
    }

    public function handle(RouterWatcher $watcher): void
    {
        $tenant = Tenant::query()->find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        Tenancy::forTenant($tenant, function () use ($watcher): void {
            $router = Router::query()->find($this->routerId);

            if ($router !== null) {
                $watcher->watch($router);
            }
        });
    }
}
