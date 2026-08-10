<?php

namespace App\Console\Commands;

use App\Enums\HotspotUserStatus;
use App\Models\HotspotUser;
use App\Models\Tenant;
use App\Services\RouterOs\HotspotUserProvisioner;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

/**
 * Takes spent HotSpot packages off the routers.
 *
 * Runs every minute from the scheduler. The record stays in the database — it is what the
 * ISP's earnings reporting is built from — but the login stops existing on the device.
 */
class HotspotSweepCommand extends Command
{
    protected $signature = 'hotspot:sweep
        {--tenant= : Limit to one ISP by slug.}
        {--retry : Also retry packages that failed to provision.}';

    protected $description = 'Remove expired HotSpot logins from routers';

    public function handle(HotspotUserProvisioner $provisioner): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($query, $slug) => $query->where('slug', $slug))
            ->get();

        $removed = 0;
        $retried = 0;
        $failed = 0;

        foreach ($tenants as $tenant) {
            Tenancy::forTenant($tenant, function () use ($provisioner, &$removed, &$failed, &$retried): void {
                // Eager loaded because each one needs its router to be contacted.
                HotspotUser::query()->due()->with(['router', 'plan'])->chunkById(100, function ($due) use ($provisioner, &$removed, &$failed): void {
                    foreach ($due as $user) {
                        $provisioner->remove($user, HotspotUserStatus::Expired);

                        $user->status === HotspotUserStatus::Expired ? $removed++ : $failed++;
                    }
                });

                if (! $this->option('retry')) {
                    return;
                }

                // A customer who paid while the router was unreachable is still owed their
                // package, so failed provisioning is retried until it takes.
                HotspotUser::query()
                    ->where('status', HotspotUserStatus::Failed)
                    ->where('expires_at', '>', now())
                    ->with(['router', 'plan'])
                    ->chunkById(100, function ($stuck) use ($provisioner, &$retried): void {
                        foreach ($stuck as $user) {
                            $provisioner->provision($user);

                            $retried += $user->status === HotspotUserStatus::Active ? 1 : 0;
                        }
                    });
            });
        }

        $this->components->info(sprintf(
            'Swept %d expired package(s)%s%s.',
            $removed,
            $failed > 0 ? sprintf(', %d could not be reached', $failed) : '',
            $retried > 0 ? sprintf(', %d retried successfully', $retried) : '',
        ));

        return self::SUCCESS;
    }
}
