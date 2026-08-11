<?php

namespace App\Services\Platform;

use App\Enums\PaymentStatus;
use App\Enums\ServiceType;
use App\Models\Payment;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Support\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;

/**
 * Works out what an ISP owes the platform for a month.
 *
 * Two charges, deliberately different in kind because the products are:
 *
 * - **HotSpot** is a share of money that actually moved. It is read from settled payments, so
 *   an ISP is never charged commission on a prompt a customer cancelled, and a month where
 *   nobody bought anything costs them nothing.
 * - **PPPoE** is per active line. A subscriber line is a standing relationship rather than a
 *   sale, so it is priced per head — and counted at the moment of billing rather than averaged,
 *   because a line that exists is a line the platform is carrying.
 */
class BillingCalculator
{
    /**
     * @param  CarbonImmutable|null  $start  Defaults to the month just ended.
     */
    public function forTenant(Tenant $tenant, ?CarbonImmutable $start = null, ?CarbonImmutable $end = null): UsageCharge
    {
        $start ??= CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth();
        $end ??= $start->endOfMonth();

        $subscription = TenantSubscription::forTenant($tenant);

        $rate = (float) $subscription->hotspot_commission_rate;
        $unitFee = (float) $subscription->pppoe_monthly_fee;

        // Every read is scoped to this one ISP. Billing runs from a console command with no
        // tenant resolved, and strict scoping would otherwise refuse the query outright —
        // which is the protection working, not an obstacle to route around.
        [$collected, $lines] = Tenancy::forTenant($tenant, fn (): array => [
            (float) Payment::query()
                ->where('status', PaymentStatus::Paid)
                ->whereBetween('paid_at', [$start->startOfDay(), $end->endOfDay()])
                ->sum('amount'),

            ServiceAccount::query()
                ->active()
                ->ofType(ServiceType::Pppoe)
                ->count(),
        ]);

        return new UsageCharge(
            periodStart: $start,
            periodEnd: $end,
            hotspotCollected: $collected,
            hotspotRate: $rate,
            // Rounded to the shilling in the platform's favour nowhere: an ordinary round, so
            // a month of small purchases does not drift.
            hotspotAmount: round($collected * $rate / 100, 2),
            pppoeLines: $lines,
            pppoeUnitFee: $unitFee,
            pppoeAmount: round($lines * $unitFee, 2),
        );
    }
}
