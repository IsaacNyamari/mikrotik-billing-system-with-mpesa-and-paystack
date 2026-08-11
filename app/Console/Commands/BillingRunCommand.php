<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Platform\PlatformBilling;
use App\Support\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Bills every ISP for the month just ended, then ages the bills that went unpaid.
 *
 * Runs daily rather than monthly: a scheduler that missed the first of the month would
 * otherwise skip a month's income entirely, and generating an invoice that already exists is
 * a no-op. The overdue sweep needs to run daily regardless.
 */
class BillingRunCommand extends Command
{
    protected $signature = 'billing:run
        {--tenant= : Limit to one ISP by slug.}
        {--month= : The month to bill, as YYYY-MM. Defaults to the one just ended.}
        {--invoices-only : Raise invoices without ageing the unpaid ones.}';

    protected $description = 'Invoice each ISP for the month just ended and age unpaid bills';

    public function handle(PlatformBilling $billing): int
    {
        $month = $this->month();

        if ($month === null) {
            $this->components->error('Give --month as YYYY-MM.');

            return self::FAILURE;
        }

        $slug = $this->option('tenant');

        // Billing spans every ISP by design, and says so rather than relying on there being
        // no tenant resolved.
        $tenants = Tenancy::withoutScope(fn () => Tenant::query()
            ->whereIn('status', [TenantStatus::Active, TenantStatus::Suspended])
            ->when(is_string($slug) && $slug !== '', fn ($query) => $query->where('slug', $slug))
            ->orderBy('id')
            ->get());

        $raised = 0;

        foreach ($tenants as $tenant) {
            $invoice = $billing->invoiceForPeriod($tenant, $month);

            if ($invoice === null) {
                continue;
            }

            $raised++;

            $this->components->twoColumnDetail(
                sprintf('%s — %s', $tenant->name, $invoice->number),
                sprintf('%s %s', config('platform.pricing.currency'), $invoice->total),
            );
        }

        $this->components->info(sprintf('%s: %d ISPs, %d invoices raised.', $month->format('F Y'), $tenants->count(), $raised));

        if ($this->option('invoices-only')) {
            return self::SUCCESS;
        }

        $swept = $billing->sweepOverdue();

        $this->components->info(sprintf('%d marked overdue, %d suspended.', $swept['overdue'], $swept['suspended']));

        return self::SUCCESS;
    }

    protected function month(): ?CarbonImmutable
    {
        $option = $this->option('month');

        if (! is_string($option) || $option === '') {
            return CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $option.'-01')->startOfMonth();
        } catch (\Throwable) {
            return null;
        }
    }
}
