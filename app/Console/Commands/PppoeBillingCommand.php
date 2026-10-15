<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Billing\PppoeBillingRun;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Reminds PPPoE subscribers before and on their due date, cuts lines that were not renewed,
 * and applies renewals that were paid but never reached their line.
 */
class PppoeBillingCommand extends Command
{
    protected $signature = 'pppoe:billing {--tenant= : Limit to one ISP by slug.}';

    protected $description = 'Remind, disconnect and reconnect PPPoE subscribers by their paid-until date';

    public function handle(PppoeBillingRun $run): int
    {
        $slug = $this->option('tenant');

        /** @var Collection<int, Tenant> $tenants */
        $tenants = Tenant::query()
            ->when(is_string($slug) && $slug !== '', fn ($query) => $query->where('slug', $slug))
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            $tally = Tenancy::forTenant($tenant, fn (): array => $run->run());

            if (array_sum($tally) > 0) {
                $this->components->twoColumnDetail($tenant->name, sprintf(
                    '%d before, %d due, %d cut, %d renewed',
                    $tally['before'],
                    $tally['due'],
                    $tally['suspended'],
                    $tally['renewed'],
                ));
            }
        }

        return self::SUCCESS;
    }
}
