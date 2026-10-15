<?php

namespace App\Console\Commands;

use App\Jobs\WatchRouter;
use App\Models\Router;
use App\Models\Tenant;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

/**
 * Checks every router once: records outages, compensates customers, logs them back in.
 *
 * Scheduled every minute. Each router is a queued job of its own; --sync runs them here
 * instead, for a laptop with no queue worker or for watching one router from a terminal.
 */
class RoutersWatchCommand extends Command
{
    protected $signature = 'routers:watch
        {--sync : Run in this process instead of queueing a job per router}
        {--tenant= : Only this ISP (slug)}';

    protected $description = 'Watch every router for outages, compensate customers and log them back in';

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, $slug) => $query->where('slug', $slug))
            ->get();

        $count = 0;

        foreach ($tenants as $tenant) {
            $ids = Tenancy::forTenant($tenant, fn () => Router::query()->pluck('id')->all());

            foreach ($ids as $id) {
                $job = new WatchRouter($tenant->id, (int) $id);

                $this->option('sync') ? dispatch_sync($job) : dispatch($job);

                $count++;
            }
        }

        $this->components->info(sprintf('%s %d router(s).', $this->option('sync') ? 'Watched' : 'Queued', $count));

        return self::SUCCESS;
    }
}
