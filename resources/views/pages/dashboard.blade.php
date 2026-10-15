<?php

use App\Models\MpesaConfiguration;
use App\Models\Plan;
use App\Models\Router;
use App\Services\Reports\DashboardMetrics;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Operations overview: what an operator wants to know on opening the platform.
 *
 * Is money coming in, is anybody waiting, is the network up. Every figure comes from
 * DashboardMetrics and so from something the platform recorded; each panel sits behind the
 * permission for its subject, so a support agent does not see revenue and a billing officer is
 * not shown router internals.
 *
 * Re-read every minute. The watcher samples routers once a minute, so polling faster would
 * redraw the same numbers.
 */
new #[Title('Operations overview')] class extends Component {
    /** today, week or month — the revenue card's window. */
    public string $revenuePeriod = 'today';

    /** The awaiting-payment card's window. */
    public string $duePeriod = 'today';

    #[Computed]
    public function hasTenant(): bool
    {
        return Tenancy::has();
    }

    #[Computed]
    public function canSeeMoney(): bool
    {
        return Auth::user()?->hasPermissionTo(Permissions::REPORTS_VIEW_REVENUE) ?? false;
    }

    #[Computed]
    public function canSeeNetwork(): bool
    {
        return Auth::user()?->hasPermissionTo(Permissions::ROUTERS_VIEW) ?? false;
    }

    #[Computed]
    public function canSeeCustomers(): bool
    {
        return Auth::user()?->hasPermissionTo(Permissions::CUSTOMERS_VIEW) ?? false;
    }

    /**
     * @return array{routers: int, plans: int, mpesa: bool}
     */
    #[Computed]
    public function setup(): array
    {
        return [
            'routers' => Router::query()->count(),
            'plans' => Plan::query()->count(),
            'mpesa' => MpesaConfiguration::query()->where('is_active', true)->exists(),
        ];
    }

    /**
     * A brand-new ISP has nothing to chart, so it is shown the steps to its first sale instead.
     */
    #[Computed]
    public function isNew(): bool
    {
        return $this->setup['routers'] === 0 && $this->setup['plans'] === 0;
    }

    #[Computed]
    public function metrics(): DashboardMetrics
    {
        return app(DashboardMetrics::class);
    }

    #[Computed]
    public function currency(): string
    {
        return Tenancy::tenant()?->currency ?? 'KES';
    }

    public function updatedRevenuePeriod(): void
    {
        $this->revenuePeriod = in_array($this->revenuePeriod, ['today', 'week', 'month'], true) ? $this->revenuePeriod : 'today';
    }

    public function updatedDuePeriod(): void
    {
        $this->duePeriod = in_array($this->duePeriod, ['today', 'week', 'month'], true) ? $this->duePeriod : 'today';
    }

    /**
     * The words for a window, as the cards say them.
     */
    public function periodLabel(string $period): string
    {
        return match ($period) {
            'week' => __('Last 7 days'),
            'month' => __('Last 30 days'),
            default => __('Today'),
        };
    }

    /**
     * Change against the prior period, as a signed percentage, or null when there is nothing
     * to compare against — "+∞%" helps nobody.
     */
    public function change(float|int|null $now, float|int|null $before): ?float
    {
        if ($now === null || $before === null || (float) $before === 0.0) {
            return null;
        }

        return round(((float) $now - (float) $before) / abs((float) $before) * 100, 1);
    }
}; ?>

@php
    $currency = $this->currency;
    $money = fn (float $amount): string => number_format($amount, 2);
@endphp

<section
    class="ops-dashboard w-full min-h-full bg-slate-100/80 dark:bg-zinc-900"
    wire:poll.60s
    x-data="{
        hidden: (() => { try { return JSON.parse(localStorage.getItem('dashboard.hidden') || '[]') } catch (e) { return [] } })(),
        toggle(key) {
            this.hidden = this.hidden.includes(key) ? this.hidden.filter(k => k !== key) : [...this.hidden, key];
            try { localStorage.setItem('dashboard.hidden', JSON.stringify(this.hidden)) } catch (e) {}
        },
        shows(key) { return ! this.hidden.includes(key) },
    }"
>
    <style>
        /* Chart roles, light and dark. Values are the validated reference palette: slot 1
           (blue) and slot 2 (orange) for the two acquisition series, the fixed status pair for
           connectivity. Text never wears a series colour. */
        .ops-dashboard {
            --series-1: #2a78d6;
            --series-2: #eb6834;
            --status-good: #0ca30c;
            --status-critical: #d03b3b;
            --grid: #e4e7ec;
        }
        .dark .ops-dashboard {
            --series-1: #3987e5;
            --series-2: #d95926;
            --grid: #3f3f46;
        }
    </style>

    <div class="mx-auto flex max-w-[1500px] flex-col gap-5 p-4 sm:p-6">
        {{-- Heading ----------------------------------------------------------------- --}}
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="flex size-9 items-center justify-center rounded-xl bg-blue-600 text-white shadow-sm">
                    <flux:icon.chart-pie variant="micro" class="size-5" />
                </span>
                <div>
                    <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">{{ __('Operations overview') }}</h1>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ Tenancy::tenant()?->name }} · {{ now()->format('l j F, H:i') }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                @can('create', App\Models\Customer::class)
                    <flux:button variant="primary" icon="plus" :href="route('customers.create')" wire:navigate>
                        {{ __('Add customer') }}
                    </flux:button>
                @endcan

                <flux:dropdown position="bottom" align="end">
                    <flux:button icon="adjustments-horizontal">{{ __('Customize') }}</flux:button>

                    <flux:menu class="min-w-56">
                        <flux:menu.heading>{{ __('Show on the dashboard') }}</flux:menu.heading>
                        @foreach ([
                            'headline' => __('Revenue and alerts'),
                            'subscribers' => __('Subscriber status'),
                            'balances' => __('Balances and support'),
                            'actions' => __('Quick actions'),
                            'monthly' => __('Monthly performance'),
                            'trends' => __('Performance trends'),
                        ] as $key => $label)
                            <label class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700">
                                <input type="checkbox" class="rounded border-zinc-300" :checked="shows('{{ $key }}')" @change="toggle('{{ $key }}')">
                                {{ $label }}
                            </label>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            </div>
        </div>

        @unless ($this->hasTenant)
            <div class="rounded-2xl bg-white p-12 text-center shadow-sm ring-1 ring-zinc-200/70 dark:bg-zinc-800 dark:ring-zinc-700">
                <h2 class="text-lg font-semibold">{{ __('No ISP linked to this account') }}</h2>
                <p class="mt-1 text-sm text-zinc-500">{{ __('Ask an administrator to attach your account to an ISP before you can see anything here.') }}</p>
            </div>
        @else
            @php($m = $this->metrics)

            @if ($this->isNew)
                <div class="rounded-2xl bg-white p-6 shadow-sm ring-1 ring-zinc-200/70 dark:bg-zinc-800 dark:ring-zinc-700">
                    <h2 class="text-base font-semibold">{{ __('Getting started') }}</h2>
                    <p class="text-sm text-zinc-500">{{ __('Three things stand between you and your first sale.') }}</p>

                    <div class="mt-4 flex flex-col gap-3">
                        @foreach ([
                            ['done' => $this->setup['routers'] > 0, 'title' => __('Add your MikroTik'), 'text' => __('The platform provisions customers straight onto it.'), 'route' => 'routers.create', 'can' => 'routers.create'],
                            ['done' => $this->setup['plans'] > 0, 'title' => __('Create your packages'), 'text' => __('Price, speed and how long each one lasts.'), 'route' => 'plans.create', 'can' => 'plans.create'],
                            ['done' => $this->setup['mpesa'], 'title' => __('Connect M-PESA'), 'text' => __('Customers cannot pay from the portal until this is set.'), 'route' => 'settings.mpesa', 'can' => 'settings.manage_payment_credentials'],
                        ] as $step)
                            <div class="flex items-start gap-3">
                                @if ($step['done'])
                                    <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-green-600" />
                                @else
                                    <div class="mt-0.5 size-5 shrink-0 rounded-full border-2 border-zinc-300 dark:border-zinc-600"></div>
                                @endif
                                <div class="flex-1">
                                    <p class="text-sm font-medium">{{ $step['title'] }}</p>
                                    <p class="text-xs text-zinc-500">{{ $step['text'] }}</p>
                                </div>
                                @unless ($step['done'])
                                    @can($step['can'])
                                        <flux:button size="sm" :href="route($step['route'])" wire:navigate>{{ __('Set up') }}</flux:button>
                                    @endcan
                                @endunless
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Row 1: revenue, awaiting payment, alerts --------------------------------- --}}
            @if ($this->canSeeMoney)
                @php($revenue = $m->revenue($revenuePeriod))
                @php($bars = $m->dailyRevenue(8))
                @php($barMax = max(1, max(array_column($bars, 'amount'))))
                @php($due = $m->awaitingPayment($duePeriod))
                @php($stuck = $m->paidNotOnline())
                @php($running = $m->packagesRunning())

                <div x-show="shows('headline')" class="grid gap-4 lg:grid-cols-12">
                    {{-- Revenue: the one dark card, because it is the number people open this page for. --}}
                    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-zinc-900 via-slate-900 to-zinc-800 p-5 text-white shadow-md lg:col-span-5">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="flex size-8 items-center justify-center rounded-lg bg-emerald-500/90">
                                    <flux:icon.banknotes variant="micro" class="size-4 text-white" />
                                </span>
                                <span class="text-sm font-semibold">{{ __('Revenue') }}</span>
                            </div>
                            <select wire:model.live="revenuePeriod" class="rounded-lg border-0 bg-white/10 py-1 pe-7 ps-2.5 text-xs font-semibold text-white ring-1 ring-white/15 focus:ring-white/40">
                                <option value="today" class="text-zinc-900">{{ __('Today') }}</option>
                                <option value="week" class="text-zinc-900">{{ __('7 days') }}</option>
                                <option value="month" class="text-zinc-900">{{ __('30 days') }}</option>
                            </select>
                        </div>

                        <div class="mt-6 flex items-end justify-between gap-4">
                            <div>
                                <p class="text-4xl font-bold tracking-tight tabular-nums sm:text-5xl">
                                    {{ $money($revenue['amount']) }}<span class="ms-1 text-sm font-semibold text-white/70">{{ $currency }}</span>
                                </p>
                                <p class="mt-2 flex items-center gap-1.5 text-xs text-white/70">
                                    <span class="size-1.5 rounded-full bg-emerald-400"></span>
                                    {{ trans_choice('{0}No collections :when|{1}:count successful collection :when|[2,*]:count successful collections :when', $revenue['count'], [
                                        'count' => $revenue['count'],
                                        'when' => mb_strtolower($this->periodLabel($revenuePeriod)),
                                    ]) }}
                                </p>
                            </div>

                            {{-- Eight days of takings. One series, so the card's title names it; each
                                 bar answers on hover with its day and amount. --}}
                            <div class="flex h-20 items-end gap-1.5" role="img" aria-label="{{ __('Daily revenue, last 8 days') }}">
                                @foreach ($bars as $bar)
                                    @php($height = max(4, (int) round($bar['amount'] / $barMax * 80)))
                                    <div class="group relative flex h-20 w-3.5 items-end">
                                        <div class="w-full rounded-t-[4px] {{ $loop->last ? 'bg-white' : 'bg-white/45 group-hover:bg-white/80' }}" style="height: {{ $height }}px"></div>
                                        <div class="pointer-events-none absolute bottom-full right-0 z-10 mb-2 hidden whitespace-nowrap rounded-md bg-white px-2 py-1 text-[11px] text-zinc-900 shadow-lg group-hover:block">
                                            <span class="font-semibold">{{ \Carbon\CarbonImmutable::parse($bar['date'])->format('D j M') }}</span><br>
                                            {{ $money($bar['amount']) }} {{ $currency }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    {{-- Awaiting payment --}}
                    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200/70 lg:col-span-4 dark:bg-zinc-800 dark:ring-zinc-700">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="flex size-8 items-center justify-center rounded-full bg-blue-600 text-white">
                                    <flux:icon.arrow-down-circle variant="micro" class="size-4" />
                                </span>
                                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __('Awaiting payment') }}</span>
                            </div>
                            <select wire:model.live="duePeriod" class="rounded-lg border-0 bg-zinc-50 py-1 pe-7 ps-2.5 text-xs font-semibold text-zinc-700 ring-1 ring-zinc-200 dark:bg-zinc-700 dark:text-zinc-100 dark:ring-zinc-600">
                                <option value="today">{{ __('Today') }}</option>
                                <option value="week">{{ __('7 days') }}</option>
                                <option value="month">{{ __('30 days') }}</option>
                            </select>
                        </div>

                        <p class="mt-6 text-4xl font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">
                            {{ $money($due['amount']) }}<span class="ms-1 text-sm font-semibold text-zinc-500">{{ $currency }}</span>
                        </p>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Payment prompts sent to customers and not yet answered.') }}</p>

                        <div class="mt-4 grid grid-cols-2 gap-3 border-t border-zinc-100 pt-3 dark:border-zinc-700">
                            <div>
                                <p class="text-[11px] text-zinc-500">{{ __('Window') }}</p>
                                <p class="text-sm font-semibold">{{ $this->periodLabel($duePeriod) }}</p>
                            </div>
                            <div>
                                <p class="text-[11px] text-zinc-500">{{ __('Prompts') }}</p>
                                <p class="text-sm font-semibold">{{ trans_choice('{0}None open|{1}:count open|[2,*]:count open', $due['count'], ['count' => $due['count']]) }}</p>
                            </div>
                        </div>
                    </div>

                    {{-- Alerts --}}
                    <div class="flex flex-col gap-4 lg:col-span-3">
                        <a href="{{ route('customers.index') }}" wire:navigate class="block flex-1 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200/70 transition hover:ring-red-300 dark:bg-zinc-800 dark:ring-zinc-700">
                            <div class="flex items-center gap-2">
                                <span class="flex size-8 items-center justify-center rounded-full bg-red-600 text-white">
                                    <flux:icon.exclamation-triangle variant="micro" class="size-4" />
                                </span>
                                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __('Paid, not yet online') }}</span>
                            </div>
                            <p class="mt-3 text-3xl font-bold tabular-nums {{ $stuck > 0 ? 'text-red-600' : 'text-zinc-900 dark:text-white' }}">{{ $stuck }}</p>
                            <p class="text-xs text-zinc-500">
                                {{ $stuck > 0 ? __('Customers waiting: their router could not be reached. Retried every minute.') : __('Nobody is waiting for access.') }}
                            </p>
                        </a>

                        <div class="flex-1 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200/70 dark:bg-zinc-800 dark:ring-zinc-700">
                            <div class="flex items-center gap-2">
                                <span class="flex size-8 items-center justify-center rounded-full bg-sky-500 text-white">
                                    <flux:icon.wifi variant="micro" class="size-4" />
                                </span>
                                <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __('Packages running') }}</span>
                            </div>
                            <p class="mt-3 text-3xl font-bold text-zinc-900 tabular-nums dark:text-white">{{ number_format($running) }}</p>
                            <p class="text-xs text-zinc-500">{{ __('HotSpot packages with time left right now') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- Row 2: subscribers, balances, quick actions ------------------------------ --}}
            <div class="grid gap-4 lg:grid-cols-12">
                @if ($this->canSeeCustomers)
                    @php($subs = $m->subscribers())

                    <div x-show="shows('subscribers')" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200/70 lg:col-span-4 dark:bg-zinc-800 dark:ring-zinc-700">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="border-s-4 border-blue-600 ps-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __('Subscriber status') }}</h2>
                            <a href="{{ route('customers.index') }}" wire:navigate class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-200">
                                {{ __('View customers') }} <flux:icon.chevron-right variant="micro" class="size-3.5" />
                            </a>
                        </div>

                        <div class="mt-4 grid grid-cols-2 gap-3">
                            @foreach ([
                                ['label' => __('Online'), 'value' => $subs['online'], 'icon' => 'signal', 'tone' => 'bg-sky-500', 'hint' => __('Seen in the last :n minutes', ['n' => App\Services\Reports\DashboardMetrics::ONLINE_WINDOW_MINUTES])],
                                ['label' => __('Active'), 'value' => $subs['active'], 'icon' => 'check-badge', 'tone' => 'bg-emerald-600', 'hint' => __('Packages and accounts')],
                                ['label' => __('Suspended'), 'value' => $subs['suspended'], 'icon' => 'pause-circle', 'tone' => 'bg-amber-500', 'hint' => __('Accounts on hold')],
                                ['label' => __('Disabled'), 'value' => $subs['disabled'], 'icon' => 'no-symbol', 'tone' => 'bg-slate-500', 'hint' => __('Ended or blocked')],
                            ] as $tile)
                                <div class="rounded-xl bg-zinc-50 p-3.5 ring-1 ring-zinc-100 dark:bg-zinc-900/60 dark:ring-zinc-700" title="{{ $tile['hint'] }}">
                                    <span class="flex size-7 items-center justify-center rounded-full {{ $tile['tone'] }} text-white">
                                        <flux:icon :name="$tile['icon']" variant="micro" class="size-3.5" />
                                    </span>
                                    <p class="mt-3 text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ $tile['label'] }}</p>
                                    <p class="text-2xl font-bold text-zinc-900 tabular-nums dark:text-white">{{ number_format($tile['value']) }}</p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($this->canSeeMoney)
                    @php($balances = $m->balances(Tenancy::tenant()))

                    <div x-show="shows('balances')" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200/70 lg:col-span-5 dark:bg-zinc-800 dark:ring-zinc-700">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="border-s-4 border-blue-600 ps-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __('Open balances & support') }}</h2>
                            <a href="{{ route('reports.earnings') }}" wire:navigate class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-200">
                                {{ __('Finance dashboard') }} <flux:icon.chevron-right variant="micro" class="size-3.5" />
                            </a>
                        </div>

                        <div class="mt-4 grid gap-3 sm:grid-cols-2">
                            <div class="rounded-xl bg-emerald-50 p-3.5 ring-1 ring-emerald-100 dark:bg-emerald-950/30 dark:ring-emerald-900">
                                <div class="flex items-start justify-between">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-900 dark:text-emerald-200">{{ __('Accounts receivable') }}</p>
                                    <flux:icon.arrow-down-circle variant="micro" class="size-4 text-emerald-700 dark:text-emerald-300" />
                                </div>
                                <p class="mt-2 text-lg font-bold text-zinc-900 tabular-nums dark:text-white">{{ $currency }} {{ $money($balances['receivable']) }}</p>
                                <p class="text-[11px] text-zinc-500">{{ __('Open payment prompts today') }}</p>
                            </div>

                            <a href="{{ route('billing.index') }}" wire:navigate class="block rounded-xl bg-amber-50 p-3.5 ring-1 ring-amber-100 hover:ring-amber-300 dark:bg-amber-950/30 dark:ring-amber-900">
                                <div class="flex items-start justify-between">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-900 dark:text-amber-200">{{ __('Accounts payable') }}</p>
                                    <flux:icon.arrow-up-circle variant="micro" class="size-4 text-amber-700 dark:text-amber-300" />
                                </div>
                                <p class="mt-2 text-lg font-bold text-zinc-900 tabular-nums dark:text-white">{{ $currency }} {{ $money($balances['payable']) }}</p>
                                <p class="text-[11px] text-zinc-500">{{ __('Unpaid platform invoices') }}</p>
                            </a>

                            <a href="{{ route('routers.index') }}" wire:navigate class="block rounded-xl bg-red-50/70 p-3.5 ring-1 ring-red-100 hover:ring-red-300 sm:col-span-2 dark:bg-red-950/20 dark:ring-red-900">
                                <div class="flex items-start justify-between">
                                    <p class="text-[11px] font-semibold uppercase tracking-wide text-red-900 dark:text-red-200">{{ __('Routers needing attention') }}</p>
                                    <flux:icon.server-stack variant="micro" class="size-4 text-red-700 dark:text-red-300" />
                                </div>
                                <p class="mt-2 text-lg font-bold tabular-nums {{ $balances['routerIssues'] > 0 ? 'text-red-600' : 'text-zinc-900 dark:text-white' }}">{{ $balances['routerIssues'] }}</p>
                                <p class="text-[11px] text-zinc-500">{{ $balances['routerIssues'] > 0 ? __('Offline, erroring or never reached') : __('Every router is answering') }}</p>
                            </a>
                        </div>
                    </div>
                @endif

                <div x-show="shows('actions')" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200/70 lg:col-span-3 dark:bg-zinc-800 dark:ring-zinc-700">
                    <h2 class="border-s-4 border-blue-600 ps-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __('Quick actions') }}</h2>

                    <div class="mt-4 flex flex-col gap-2">
                        @foreach (array_filter([
                            $this->canSeeCustomers ? ['label' => __('Customer list'), 'route' => route('customers.index'), 'icon' => 'users', 'tone' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300'] : null,
                            $this->canSeeNetwork ? ['label' => __('Routers'), 'route' => route('routers.index'), 'icon' => 'server-stack', 'tone' => 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300'] : null,
                            $this->canSeeMoney ? ['label' => __('Income records'), 'route' => route('reports.earnings'), 'icon' => 'banknotes', 'tone' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'] : null,
                            $this->canSeeNetwork ? ['label' => __('Router console'), 'route' => route('routers.manage'), 'icon' => 'wrench-screwdriver', 'tone' => 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300'] : null,
                        ]) as $action)
                            <a href="{{ $action['route'] }}" wire:navigate class="flex items-center gap-3 rounded-xl bg-zinc-50 px-3 py-2.5 text-sm font-semibold text-zinc-800 ring-1 ring-zinc-100 transition hover:bg-zinc-100 dark:bg-zinc-900/60 dark:text-zinc-100 dark:ring-zinc-700 dark:hover:bg-zinc-900">
                                <span class="flex size-7 items-center justify-center rounded-lg {{ $action['tone'] }}">
                                    <flux:icon :name="$action['icon']" variant="micro" class="size-4" />
                                </span>
                                <span class="flex-1">{{ $action['label'] }}</span>
                                <flux:icon.chevron-right variant="micro" class="size-4 text-zinc-400" />
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Row 3: monthly performance, trends --------------------------------------- --}}
            <div class="grid gap-4 lg:grid-cols-12">
                @if ($this->canSeeMoney)
                    @php($month = $m->monthly(Tenancy::tenant()))

                    <div x-show="shows('monthly')" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200/70 lg:col-span-4 dark:bg-zinc-800 dark:ring-zinc-700">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="border-s-4 border-blue-600 ps-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __('Monthly performance') }}</h2>
                            <a href="{{ route('reports.earnings') }}" wire:navigate class="inline-flex items-center gap-1 rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-200">
                                {{ __('Business dashboard') }} <flux:icon.chevron-right variant="micro" class="size-3.5" />
                            </a>
                        </div>
                        <p class="mt-1 text-[11px] text-zinc-500">{{ __(':month so far, against the same days of :previous', ['month' => now()->format('F'), 'previous' => now()->subMonthNoOverflow()->format('F')]) }}</p>

                        <div class="mt-3 grid grid-cols-2 gap-3">
                            @foreach ([
                                ['label' => __('Income'), 'icon' => 'presentation-chart-line', 'value' => $currency.' '.number_format($month['income']['now'], 2), 'delta' => $this->change($month['income']['now'], $month['income']['before']), 'good' => 'up', 'unit' => '%'],
                                ['label' => __('Platform fees'), 'icon' => 'receipt-percent', 'value' => $currency.' '.number_format($month['fees']['now'], 2), 'delta' => $this->change($month['fees']['now'], $month['fees']['before']), 'good' => 'down', 'unit' => '%'],
                                ['label' => __('Net result'), 'icon' => 'banknotes', 'value' => $currency.' '.number_format($month['net']['now'], 2), 'delta' => $this->change($month['net']['now'], $month['net']['before']), 'good' => 'up', 'unit' => '%'],
                                ['label' => __('Collection rate'), 'icon' => 'chart-pie', 'value' => $month['collection']['now'] === null ? '—' : $month['collection']['now'].'%', 'delta' => ($month['collection']['now'] !== null && $month['collection']['before'] !== null) ? round($month['collection']['now'] - $month['collection']['before'], 1) : null, 'good' => 'up', 'unit' => ' pts'],
                            ] as $tile)
                                <div class="rounded-xl bg-zinc-50 p-3.5 ring-1 ring-zinc-100 dark:bg-zinc-900/60 dark:ring-zinc-700">
                                    <div class="flex items-center gap-2">
                                        <span class="flex size-6 items-center justify-center rounded-md bg-white text-zinc-600 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-600">
                                            <flux:icon :name="$tile['icon']" variant="micro" class="size-3.5" />
                                        </span>
                                        <p class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ $tile['label'] }}</p>
                                    </div>
                                    <p class="mt-2 text-base font-bold text-zinc-900 tabular-nums sm:text-lg dark:text-white">{{ $tile['value'] }}</p>
                                    <p class="text-[11px] text-zinc-500">
                                        @if ($tile['delta'] === null)
                                            {{ __('nothing to compare yet') }}
                                        @else
                                            @php($better = $tile['good'] === 'up' ? $tile['delta'] >= 0 : $tile['delta'] <= 0)
                                            <span class="font-semibold {{ $better ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-700 dark:text-red-400' }}">
                                                {{ $tile['delta'] >= 0 ? '▲ +' : '▼ ' }}{{ $tile['delta'] }}{{ $tile['unit'] }}
                                            </span>
                                            {{ __('vs last month') }}
                                        @endif
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div x-show="shows('trends')" class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200/70 lg:col-span-8 dark:bg-zinc-800 dark:ring-zinc-700">
                    <h2 class="border-s-4 border-blue-600 ps-2 text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ __('Performance trends') }}</h2>

                    <div class="mt-4 grid gap-4 xl:grid-cols-5">
                        {{-- Acquisition --}}
                        @if ($this->canSeeCustomers)
                            @php($series = $m->acquisition(30))
                            @php($peak = max(4, max(array_map(fn ($d) => max($d['buyers'], $d['accounts']), $series))))
                            @php($top = (int) (ceil($peak / 4) * 4))
                            @php($w = 600)
                            @php($h = 220)
                            @php($pad = ['l' => 28, 'r' => 8, 't' => 10, 'b' => 24])
                            @php($x = fn (int $i) => $pad['l'] + $i * (($w - $pad['l'] - $pad['r']) / max(1, count($series) - 1)))
                            @php($y = fn (int $v) => $pad['t'] + ($h - $pad['t'] - $pad['b']) * (1 - $v / $top))
                            @php($line = fn (string $key) => collect($series)->map(fn ($d, $i) => round($x($i), 1).','.round($y($d[$key]), 1))->implode(' '))

                            <div class="rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-100 xl:col-span-3 dark:bg-zinc-900/60 dark:ring-zinc-700"
                                 x-data="{ at: null, points: @js(collect($series)->map(fn ($d) => ['day' => \Carbon\CarbonImmutable::parse($d['date'])->format('D j M'), 'buyers' => $d['buyers'], 'accounts' => $d['accounts']])) }">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <span class="flex size-8 items-center justify-center rounded-lg bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300">
                                            <flux:icon.user-plus variant="micro" class="size-4" />
                                        </span>
                                        <span class="text-sm font-semibold">{{ __('Acquisition') }}</span>
                                    </div>
                                    {{-- Two series, so a legend; the dash pattern repeats it for anyone
                                         who cannot rely on the colour. --}}
                                    <div class="flex items-center gap-3 text-[11px] text-zinc-600 dark:text-zinc-300">
                                        <span class="flex items-center gap-1.5"><svg width="16" height="4" aria-hidden="true"><line x1="0" y1="2" x2="16" y2="2" stroke="var(--series-1)" stroke-width="2"/></svg>{{ __('New HotSpot buyers') }}</span>
                                        <span class="flex items-center gap-1.5"><svg width="16" height="4" aria-hidden="true"><line x1="0" y1="2" x2="16" y2="2" stroke="var(--series-2)" stroke-width="2" stroke-dasharray="4 3"/></svg>{{ __('New accounts') }}</span>
                                    </div>
                                </div>

                                <div class="relative mt-3" @mouseleave="at = null">
                                    <svg viewBox="0 0 {{ $w }} {{ $h }}" class="h-56 w-full" role="img" aria-label="{{ __('New customers per day, last 30 days') }}">
                                        @foreach ([0, 0.25, 0.5, 0.75, 1] as $f)
                                            @php($gy = $y((int) round($top * $f)))
                                            <line x1="{{ $pad['l'] }}" x2="{{ $w - $pad['r'] }}" y1="{{ $gy }}" y2="{{ $gy }}" stroke="var(--grid)" stroke-width="1" />
                                            <text x="{{ $pad['l'] - 6 }}" y="{{ $gy + 3 }}" text-anchor="end" class="fill-zinc-500 text-[10px]">{{ (int) round($top * $f) }}</text>
                                        @endforeach

                                        @foreach ($series as $i => $d)
                                            @if ($i % 5 === 0 || $loop->last)
                                                <text x="{{ $x($i) }}" y="{{ $h - 6 }}" text-anchor="middle" class="fill-zinc-500 text-[10px]">{{ \Carbon\CarbonImmutable::parse($d['date'])->format('j M') }}</text>
                                            @endif
                                        @endforeach

                                        <polyline points="{{ $line('accounts') }}" fill="none" stroke="var(--series-2)" stroke-width="2" stroke-dasharray="5 4" stroke-linejoin="round" stroke-linecap="round" />
                                        <polyline points="{{ $line('buyers') }}" fill="none" stroke="var(--series-1)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                                        {{-- Crosshair for the hovered day. x-show rather than a template:
                                             a <template> inside SVG is not parsed as one. --}}
                                        @php($step = ($w - $pad['l'] - $pad['r']) / max(1, count($series) - 1))
                                        <line x-show="at !== null" :x1="{{ $pad['l'] }} + (at ?? 0) * {{ $step }}" :x2="{{ $pad['l'] }} + (at ?? 0) * {{ $step }}" y1="{{ $pad['t'] }}" y2="{{ $h - $pad['b'] }}" stroke="currentColor" class="text-zinc-400" stroke-width="1" />
                                        <circle x-show="at !== null" r="4" :cx="{{ $pad['l'] }} + (at ?? 0) * {{ $step }}" :cy="at === null ? 0 : {{ $pad['t'] }} + {{ $h - $pad['t'] - $pad['b'] }} * (1 - points[at].buyers / {{ $top }})" fill="var(--series-1)" stroke="white" stroke-width="2" />
                                        <circle x-show="at !== null" r="4" :cx="{{ $pad['l'] }} + (at ?? 0) * {{ $step }}" :cy="at === null ? 0 : {{ $pad['t'] }} + {{ $h - $pad['t'] - $pad['b'] }} * (1 - points[at].accounts / {{ $top }})" fill="var(--series-2)" stroke="white" stroke-width="2" />

                                        @foreach ($series as $i => $d)
                                            <rect x="{{ $x($i) - (($w - $pad['l'] - $pad['r']) / max(1, count($series) - 1)) / 2 }}" y="{{ $pad['t'] }}" width="{{ ($w - $pad['l'] - $pad['r']) / max(1, count($series) - 1) }}" height="{{ $h - $pad['t'] - $pad['b'] }}" fill="transparent" @mouseenter="at = {{ $i }}" />
                                        @endforeach
                                    </svg>

                                    <div x-show="at !== null" x-cloak
                                         class="pointer-events-none absolute top-1 rounded-lg bg-white px-2.5 py-1.5 text-[11px] shadow-lg ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-600"
                                         :style="`left: clamp(0px, calc(${(at ?? 0) / {{ max(1, count($series) - 1) }} * 100}% - 60px), calc(100% - 140px))`">
                                        <p class="font-semibold text-zinc-900 dark:text-white" x-text="at !== null ? points[at].day : ''"></p>
                                        <p class="text-zinc-600 dark:text-zinc-300"><span class="me-1 inline-block size-2 rounded-full" style="background: var(--series-1)"></span>{{ __('Buyers') }}: <span class="font-semibold" x-text="at !== null ? points[at].buyers : ''"></span></p>
                                        <p class="text-zinc-600 dark:text-zinc-300"><span class="me-1 inline-block size-2 rounded-full" style="background: var(--series-2)"></span>{{ __('Accounts') }}: <span class="font-semibold" x-text="at !== null ? points[at].accounts : ''"></span></p>
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- Connectivity --}}
                        @if ($this->canSeeNetwork)
                            @php($c = $m->connectivity())
                            @php($radius = 70)
                            @php($circumference = 2 * M_PI * $radius)
                            @php($onlineLength = $c['total'] > 0 ? $circumference * $c['online'] / $c['total'] : 0)

                            <div class="rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-100 xl:col-span-2 dark:bg-zinc-900/60 dark:ring-zinc-700">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="flex items-center gap-2">
                                        <span class="flex size-8 items-center justify-center rounded-lg bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">
                                            <flux:icon.signal variant="micro" class="size-4" />
                                        </span>
                                        <span class="text-sm font-semibold">{{ __('Connectivity') }}</span>
                                    </div>
                                    <a href="{{ route('monitor.index') }}" wire:navigate class="rounded-full bg-white px-3 py-1 text-[11px] font-semibold text-zinc-700 ring-1 ring-zinc-200 hover:bg-zinc-100 dark:bg-zinc-800 dark:text-zinc-200 dark:ring-zinc-600">{{ __('Network health') }}</a>
                                </div>

                                <div class="mt-3 flex flex-col items-center">
                                    <svg viewBox="0 0 180 180" class="size-48" role="img" aria-label="{{ __(':online of :total routers online', ['online' => $c['online'], 'total' => $c['total']]) }}">
                                        <circle cx="90" cy="90" r="{{ $radius }}" fill="none" stroke="{{ $c['total'] > 0 ? 'var(--status-critical)' : 'var(--grid)' }}" stroke-width="22" />
                                        @if ($c['total'] > 0)
                                            {{-- The online share, drawn over the offline ring with a 2px gap at each end. --}}
                                            <circle cx="90" cy="90" r="{{ $radius }}" fill="none" stroke="var(--status-good)" stroke-width="22"
                                                    stroke-dasharray="{{ max(0, $onlineLength - ($c['online'] < $c['total'] ? 2 : 0)) }} {{ $circumference }}"
                                                    transform="rotate(-90 90 90)">
                                                <title>{{ __(':count online', ['count' => $c['online']]) }}</title>
                                            </circle>
                                        @endif
                                        <text x="90" y="88" text-anchor="middle" class="fill-zinc-900 text-[28px] font-bold dark:fill-white">{{ $c['percent'] === null ? '—' : $c['percent'].'%' }}</text>
                                        <text x="90" y="108" text-anchor="middle" class="fill-zinc-500 text-[11px] font-semibold uppercase tracking-wide">{{ __('online') }}</text>
                                    </svg>

                                    <div class="mt-2 flex items-center gap-4 text-[11px] text-zinc-600 dark:text-zinc-300">
                                        <span class="flex items-center gap-1.5"><flux:icon.check-circle variant="micro" class="size-3.5" style="color: var(--status-good)" />{{ __(':n online', ['n' => $c['online']]) }}</span>
                                        <span class="flex items-center gap-1.5"><flux:icon.x-circle variant="micro" class="size-3.5" style="color: var(--status-critical)" />{{ __(':n down', ['n' => $c['total'] - $c['online']]) }}</span>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endunless
    </div>
</section>
