<?php

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\RouterStatus;
use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Enums\TenantStatus;
use App\Models\HotspotUser;
use App\Models\Router;
use App\Models\RouterOutage;
use App\Models\ServiceAccount;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Support\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What the platform's owner looks at: the system as a whole, and what it earned.
 *
 * Deliberately not an ISP's dashboard. Nothing here is one ISP's customers or routers in
 * detail — that is reached by opening an ISP from the ISPs page, which is recorded. What is
 * here spans every ISP in aggregate: how many there are, whether their routers are up, how
 * much the platform collected each month.
 */
new #[Title('Platform overview')] class extends Component {
    public function mount(): void
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function isps(): array
    {
        $byStatus = Tenant::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus[TenantStatus::Active->value] ?? 0),
            'pending' => (int) ($byStatus[TenantStatus::Pending->value] ?? 0),
            'suspended' => (int) ($byStatus[TenantStatus::Suspended->value] ?? 0) + (int) ($byStatus[TenantStatus::Cancelled->value] ?? 0),
            'new' => Tenant::query()->where('created_at', '>=', now()->startOfMonth())->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function network(): array
    {
        return Tenancy::withoutScope(function (): array {
            $routers = Router::query()->withoutGlobalScopes()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

            return [
                'routers' => (int) $routers->sum(),
                'online' => (int) ($routers[RouterStatus::Online->value] ?? 0),
                'down' => (int) ($routers[RouterStatus::Offline->value] ?? 0) + (int) ($routers[RouterStatus::Error->value] ?? 0),
                'outages' => RouterOutage::query()->withoutGlobalScopes()->whereNull('ended_at')->count(),
                'pppoe' => ServiceAccount::query()->withoutGlobalScopes()->where('type', ServiceType::Pppoe)->where('status', ServiceAccountStatus::Active)->count(),
                'hotspot' => HotspotUser::query()->withoutGlobalScopes()->where('expires_at', '>', now())->count(),
                'sms_failed' => SmsMessage::query()->withoutGlobalScopes()->where('status', SmsMessage::FAILED)->where('created_at', '>=', now()->subDay())->count(),
                'failed_jobs' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
            ];
        });
    }

    /**
     * What ISPs paid the platform, month by month, over the last year. A month is shown as
     * closed once it has ended; the current one is "so far".
     *
     * @return list<array{month: CarbonImmutable, total: float, payments: int}>
     */
    #[Computed]
    public function revenue(): array
    {
        $from = now()->startOfMonth()->subMonths(11);

        $rows = TenantPayment::query()
            ->where('status', PaymentStatus::Paid)
            ->where('paid_at', '>=', $from)
            ->get(['amount', 'paid_at'])
            ->groupBy(fn (TenantPayment $payment): string => $payment->paid_at->format('Y-m'));

        $months = [];

        for ($i = 0; $i < 12; $i++) {
            $month = CarbonImmutable::parse($from)->addMonths($i);
            $group = $rows->get($month->format('Y-m'));

            $months[] = [
                'month' => $month,
                'total' => (float) ($group?->sum(fn (TenantPayment $payment): float => (float) $payment->amount) ?? 0),
                'payments' => $group?->count() ?? 0,
            ];
        }

        return $months;
    }

    /**
     * The last closed month, by ISP: who paid what.
     *
     * @return list<array{name: string, total: float}>
     */
    #[Computed]
    public function lastMonthByIsp(): array
    {
        $start = now()->subMonthNoOverflow()->startOfMonth();

        return TenantPayment::query()
            ->where('status', PaymentStatus::Paid)
            ->whereBetween('paid_at', [$start, $start->endOfMonth()])
            ->get(['tenant_id', 'amount'])
            ->groupBy('tenant_id')
            ->map(fn ($payments, $tenantId): array => [
                'name' => (string) (Tenant::query()->find($tenantId)?->name ?? __('Removed ISP')),
                'total' => (float) $payments->sum(fn (TenantPayment $payment): float => (float) $payment->amount),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    #[Computed]
    public function outstanding(): float
    {
        return (float) TenantInvoice::query()
            ->whereIn('status', [InvoiceStatus::Pending, InvoiceStatus::Overdue])
            ->sum('total');
    }
}; ?>

@php($revenue = $this->revenue)
@php($closed = array_slice($revenue, 0, 11))
@php($lastClosed = $closed[10])
@php($current = $revenue[11])
@php($peak = max(1, ...array_map(fn ($m) => $m['total'], $revenue)))
@php($yearTotal = array_sum(array_map(fn ($m) => $m['total'], $closed)))

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Platform overview') }}</flux:heading>
                <flux:subheading>{{ __('Every ISP on :platform, in aggregate. Open an ISP from the ISPs page to see inside it.', ['platform' => config('app.name')]) }}</flux:subheading>
            </div>
            <flux:button icon="building-office-2" :href="route('tenants.select')" wire:navigate>{{ __('ISPs') }}</flux:button>
        </div>

        {{-- Revenue: what the platform collected, by month --}}
        <div class="grid gap-4 lg:grid-cols-3">
            <flux:card class="flex flex-col gap-1">
                <flux:text size="sm" class="opacity-70">{{ __('Collected in :month', ['month' => $lastClosed['month']->format('F Y')]) }}</flux:text>
                <p class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">KES {{ number_format($lastClosed['total'], 0) }}</p>
                <flux:text size="sm">{{ trans_choice(':count payment|:count payments', $lastClosed['payments'], ['count' => $lastClosed['payments']]) }} · {{ __('last closed month') }}</flux:text>
            </flux:card>
            <flux:card class="flex flex-col gap-1">
                <flux:text size="sm" class="opacity-70">{{ __(':month so far', ['month' => $current['month']->format('F')]) }}</flux:text>
                <p class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">KES {{ number_format($current['total'], 0) }}</p>
                <flux:text size="sm">{{ __('Closes :date', ['date' => $current['month']->endOfMonth()->format('j M')]) }}</flux:text>
            </flux:card>
            <flux:card class="flex flex-col gap-1">
                <flux:text size="sm" class="opacity-70">{{ __('Owed by ISPs') }}</flux:text>
                <p class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">KES {{ number_format($this->outstanding, 0) }}</p>
                <flux:text size="sm">{{ __('Unpaid platform invoices') }} · <a href="{{ route('platform.subscriptions') }}" wire:navigate class="font-medium text-blue-600 dark:text-blue-400">{{ __('View') }}</a></flux:text>
            </flux:card>
        </div>

        <flux:card>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading size="lg">{{ __('Platform revenue by month') }}</flux:heading>
                <flux:text size="sm">{{ __('Last 11 closed months: KES :total', ['total' => number_format($yearTotal, 0)]) }}</flux:text>
            </div>

            <div class="mt-6 flex h-48 items-end gap-1.5 border-b border-zinc-200 dark:border-zinc-700" x-data="{ tip: null }" role="img"
                 aria-label="{{ __('Monthly platform revenue for the last twelve months') }}">
                @foreach ($revenue as $i => $month)
                    @php($isCurrent = $i === 11)
                    <div class="group relative flex h-full flex-1 items-end"
                         x-on:mouseenter="tip = {{ $i }}" x-on:mouseleave="tip = null">
                        <div @class([
                                'w-full rounded-t-[4px] transition',
                                'bg-[#2a78d6] dark:bg-[#3987e5]' => ! $isCurrent,
                                'bg-[#2a78d6]/35 dark:bg-[#3987e5]/40' => $isCurrent,
                             ])
                             style="height: {{ $month['total'] > 0 ? max(2, round($month['total'] / $peak * 100, 1)) : 0 }}%"></div>
                        <div x-show="tip === {{ $i }}" x-cloak
                             class="pointer-events-none absolute bottom-full left-1/2 z-10 mb-2 -translate-x-1/2 whitespace-nowrap rounded-lg bg-zinc-900 px-2.5 py-1.5 text-xs text-white shadow-lg dark:bg-white dark:text-zinc-900">
                            <span class="font-semibold">{{ $month['month']->format('M Y') }}{{ $isCurrent ? ' · '.__('so far') : '' }}</span><br>
                            KES {{ number_format($month['total'], 0) }} · {{ trans_choice(':count payment|:count payments', $month['payments'], ['count' => $month['payments']]) }}
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="mt-2 flex gap-1.5">
                @foreach ($revenue as $i => $month)
                    <span class="flex-1 text-center text-[10px] text-zinc-500">{{ $month['month']->format('M') }}</span>
                @endforeach
            </div>

            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-medium text-zinc-600 dark:text-zinc-300">{{ __('Show as a table') }}</summary>
                <flux:table class="mt-2">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Month') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Payments') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Collected') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach (array_reverse($revenue) as $month)
                            <flux:table.row>
                                <flux:table.cell>{{ $month['month']->format('F Y') }}{{ $month['month']->isSameMonth(now()) ? ' ('.__('so far').')' : '' }}</flux:table.cell>
                                <flux:table.cell align="end">{{ $month['payments'] }}</flux:table.cell>
                                <flux:table.cell align="end" variant="strong">KES {{ number_format($month['total'], 0) }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </details>
        </flux:card>

        <div class="grid gap-4 lg:grid-cols-3">
            <flux:card class="lg:col-span-1">
                <flux:heading size="lg">{{ __('By ISP, :month', ['month' => $lastClosed['month']->format('F')]) }}</flux:heading>
                <ul class="mt-4 space-y-2.5 text-sm">
                    @forelse ($this->lastMonthByIsp as $row)
                        <li class="flex justify-between gap-3">
                            <span class="truncate text-zinc-700 dark:text-zinc-300">{{ $row['name'] }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">KES {{ number_format($row['total'], 0) }}</span>
                        </li>
                    @empty
                        <li><flux:text>{{ __('Nothing collected that month.') }}</flux:text></li>
                    @endforelse
                </ul>
            </flux:card>

            {{-- System stats --}}
            <flux:card class="lg:col-span-2">
                <flux:heading size="lg">{{ __('System') }}</flux:heading>
                @php($isps = $this->isps)
                @php($network = $this->network)
                <dl class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ([
                        [__('ISPs'), $isps['total'], __(':active active · :new new this month', ['active' => $isps['active'], 'new' => $isps['new']]), null],
                        [__('Awaiting setup fee'), $isps['pending'], __('signed up, not yet paying'), null],
                        [__('Suspended ISPs'), $isps['suspended'], __('suspended or cancelled'), $isps['suspended'] > 0 ? 'warn' : null],
                        [__('Routers'), $network['routers'], __(':online online', ['online' => $network['online']]), null],
                        [__('Routers down'), $network['down'], __(':n open outages', ['n' => $network['outages']]), $network['down'] > 0 ? 'bad' : 'good'],
                        [__('PPPoE lines'), $network['pppoe'], __('active, all ISPs'), null],
                        [__('HotSpot packages'), $network['hotspot'], __('running right now'), null],
                        [__('Failures, 24 h'), $network['failed_jobs'] + $network['sms_failed'], __(':jobs jobs · :sms texts', ['jobs' => $network['failed_jobs'], 'sms' => $network['sms_failed']]), ($network['failed_jobs'] + $network['sms_failed']) > 0 ? 'warn' : 'good'],
                    ] as [$label, $value, $note, $tone])
                        <div class="rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">
                            <dt class="flex items-center gap-1.5 text-xs font-medium text-zinc-500">
                                @if ($tone === 'bad')
                                    <flux:icon.exclamation-triangle variant="micro" class="size-3.5 text-[#d03b3b]" />
                                @elseif ($tone === 'warn')
                                    <flux:icon.exclamation-circle variant="micro" class="size-3.5 text-amber-500" />
                                @elseif ($tone === 'good')
                                    <flux:icon.check-circle variant="micro" class="size-3.5 text-[#0ca30c]" />
                                @endif
                                {{ $label }}
                            </dt>
                            <dd class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">{{ number_format($value) }}</dd>
                            <dd class="text-[11px] text-zinc-500">{{ $note }}</dd>
                        </div>
                    @endforeach
                </dl>
            </flux:card>
        </div>
    </div>
</section>
