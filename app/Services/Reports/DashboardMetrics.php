<?php

namespace App\Services\Reports;

use App\Enums\CustomerStatus;
use App\Enums\HotspotUserStatus;
use App\Enums\PaymentStatus;
use App\Enums\RouterStatus;
use App\Enums\ServiceAccountStatus;
use App\Models\Customer;
use App\Models\HotspotUser;
use App\Models\Payment;
use App\Models\Router;
use App\Models\ServiceAccount;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Services\Platform\BillingCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The numbers on the operations dashboard, for the ISP that is currently resolved.
 *
 * Every figure is read from what the platform actually recorded — settled payments, live
 * packages, routers' last reported state — never estimated or padded. A dashboard that shows a
 * plausible number nobody can trace back is worse than one that shows a zero.
 *
 * All reads go through the tenant scope, so one ISP's dashboard can never sum another's money.
 */
class DashboardMetrics
{
    /** A session noted by the watcher within this long counts as online now. */
    public const ONLINE_WINDOW_MINUTES = 3;

    public function __construct(protected BillingCalculator $billing) {}

    /**
     * Where a period starts. "today" is since midnight; the others are rolling and include
     * today, so the three only ever grow from left to right.
     */
    public static function since(string $period): CarbonImmutable
    {
        return match ($period) {
            'week' => CarbonImmutable::now()->subDays(6)->startOfDay(),
            'month' => CarbonImmutable::now()->subDays(29)->startOfDay(),
            default => CarbonImmutable::now()->startOfDay(),
        };
    }

    /**
     * @return array{amount: float, count: int}
     */
    public function revenue(string $period): array
    {
        $query = Payment::query()->paid()->where('payments.paid_at', '>=', self::since($period));

        return ['amount' => (float) (clone $query)->sum('amount'), 'count' => $query->count()];
    }

    /**
     * Settled takings per day, oldest first, for the bars on the revenue card.
     *
     * @return array<int, array{date: string, amount: float}>
     */
    public function dailyRevenue(int $days = 8): array
    {
        $start = CarbonImmutable::now()->subDays($days - 1)->startOfDay();

        $totals = Payment::query()
            ->paid()
            ->where('payments.paid_at', '>=', $start)
            ->selectRaw('DATE(payments.paid_at) as day, SUM(payments.amount) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->addDays($i)->toDateString();
            $series[] = ['date' => $date, 'amount' => (float) ($totals[$date] ?? 0)];
        }

        return $series;
    }

    /**
     * Payment prompts sent and not yet answered: money a customer has been asked for and may
     * still pay.
     *
     * @return array{amount: float, count: int}
     */
    public function awaitingPayment(string $period): array
    {
        $query = Payment::query()
            ->where('payments.status', PaymentStatus::Pending)
            ->where('payments.created_at', '>=', self::since($period));

        return ['amount' => (float) (clone $query)->sum('amount'), 'count' => $query->count()];
    }

    /**
     * Customers who paid and have no working login: the router refused or was unreachable.
     * The one number on this page that is a person waiting.
     */
    public function paidNotOnline(): int
    {
        return HotspotUser::query()
            ->where('status', HotspotUserStatus::Failed)
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * HotSpot packages with time left on them right now.
     */
    public function packagesRunning(): int
    {
        return HotspotUser::query()
            ->where('status', HotspotUserStatus::Active)
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * @return array{online: int, active: int, suspended: int, disabled: int}
     */
    public function subscribers(): array
    {
        $accounts = ServiceAccount::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn (ServiceAccountStatus $status): int => (int) ($accounts[$status->value] ?? 0);

        return [
            // Seen on a router in the last few minutes, by the watcher's own sampling.
            'online' => HotspotUser::query()
                ->where('status', HotspotUserStatus::Active)
                ->where('last_online_at', '>=', now()->subMinutes(self::ONLINE_WINDOW_MINUTES))
                ->count(),
            'active' => $this->packagesRunning() + $count(ServiceAccountStatus::Active),
            'suspended' => $count(ServiceAccountStatus::Suspended),
            'disabled' => $count(ServiceAccountStatus::Terminated)
                + Customer::query()->where('status', CustomerStatus::Blocked)->count(),
        ];
    }

    /**
     * Owed to the ISP, owed by the ISP, and what needs fixing.
     *
     * @return array{receivable: float, payable: float, routerIssues: int}
     */
    public function balances(Tenant $tenant): array
    {
        return [
            // Prompts sent in the last day and still open: the rest will not be paid.
            'receivable' => $this->awaitingPayment('today')['amount'],
            // The platform's own bills to this ISP. Not tenant-scoped models, so filtered here.
            'payable' => (float) TenantInvoice::query()
                ->where('tenant_id', $tenant->id)
                ->outstanding()
                ->sum('total'),
            'routerIssues' => Router::query()->where('status', '!=', RouterStatus::Online)->count(),
        ];
    }

    /**
     * This month so far against the same days of last month: like for like, so the first of
     * the month is not compared against all of the previous one.
     *
     * @return array{income: array{now: float, before: float}, fees: array{now: float, before: float}, net: array{now: float, before: float}, collection: array{now: float|null, before: float|null}}
     */
    public function monthly(Tenant $tenant): array
    {
        $now = CarbonImmutable::now();
        $thisStart = $now->startOfMonth();
        $lastStart = $thisStart->subMonthNoOverflow();
        $lastEnd = $lastStart->addDays(min($now->day, $lastStart->daysInMonth) - 1)->endOfDay();

        $income = fn (CarbonImmutable $from, CarbonImmutable $to): float => (float) Payment::query()
            ->paid()
            ->whereBetween('payments.paid_at', [$from, $to])
            ->sum('amount');

        $thisFees = $this->billing->forTenant($tenant, $thisStart, $now)->total();
        $lastFees = $this->billing->forTenant($tenant, $lastStart, $lastEnd)->total();

        $incomeNow = $income($thisStart, $now);
        $incomeBefore = $income($lastStart, $lastEnd);

        return [
            'income' => ['now' => $incomeNow, 'before' => $incomeBefore],
            'fees' => ['now' => $thisFees, 'before' => $lastFees],
            'net' => ['now' => $incomeNow - $thisFees, 'before' => $incomeBefore - $lastFees],
            'collection' => ['now' => $this->collectionRate($thisStart, $now), 'before' => $this->collectionRate($lastStart, $lastEnd)],
        ];
    }

    /**
     * Of the payment prompts that reached an answer, the share that ended in money. Prompts
     * still waiting are left out; they have not failed yet.
     */
    protected function collectionRate(CarbonImmutable $from, CarbonImmutable $to): ?float
    {
        $outcomes = Payment::query()
            ->whereBetween('payments.created_at', [$from, $to])
            ->whereIn('payments.status', [PaymentStatus::Paid, PaymentStatus::Failed, PaymentStatus::Cancelled, PaymentStatus::Abandoned])
            // A plain row rather than a Payment: these are two counts, not a payment. toBase()
            // keeps the tenant scope, which is applied when the query is handed over.
            ->toBase()
            ->selectRaw('SUM(CASE WHEN payments.status = ? THEN 1 ELSE 0 END) as paid, COUNT(*) as total', [PaymentStatus::Paid->value])
            ->first();

        $total = (int) ($outcomes->total ?? 0);

        return $total === 0 ? null : round(((int) ($outcomes->paid ?? 0)) / $total * 100, 1);
    }

    /**
     * New people per day over the last month: first-time HotSpot buyers (a phone number never
     * seen before that day) and new accounts.
     *
     * @return array<int, array{date: string, buyers: int, accounts: int}>
     */
    public function acquisition(int $days = 30): array
    {
        $start = CarbonImmutable::now()->subDays($days - 1)->startOfDay();

        // Each phone's first purchase, then counted by the day it fell on.
        $firsts = HotspotUser::query()
            ->whereNotNull('phone')
            ->selectRaw('phone, MIN(created_at) as first_at')
            ->groupBy('phone');

        $buyers = DB::query()
            ->fromSub($firsts, 'firsts')
            ->where('first_at', '>=', $start)
            ->selectRaw('DATE(first_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $accounts = ServiceAccount::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as total')
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $start->addDays($i)->toDateString();
            $series[] = [
                'date' => $date,
                'buyers' => (int) ($buyers[$date] ?? 0),
                'accounts' => (int) ($accounts[$date] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @return array{online: int, total: int, percent: int|null}
     */
    public function connectivity(): array
    {
        $total = Router::query()->count();
        $online = Router::query()->where('status', RouterStatus::Online)->count();

        return [
            'online' => $online,
            'total' => $total,
            'percent' => $total === 0 ? null : (int) round($online / $total * 100),
        ];
    }
}
