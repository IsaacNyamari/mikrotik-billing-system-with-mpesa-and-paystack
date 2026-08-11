<?php

use App\Enums\HotspotUserStatus;
use App\Enums\RouterStatus;
use App\Enums\ServiceAccountStatus;
use App\Models\Customer;
use App\Models\HotspotUser;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Models\ServiceAccount;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What an operator wants to know on opening the platform: is the network up, is money coming
 * in, and is anything waiting for them.
 *
 * Each panel is behind the permission that governs its subject, so a support agent does not
 * see revenue and a billing officer is not shown router internals.
 */
new #[Title('Dashboard')] class extends Component {
    /**
     * Whether there is an ISP to report on at all.
     *
     * An operator whose account is not attached to one has nothing to see here, and every
     * query below would throw under strict scoping. Saying so plainly beats a stack trace.
     */
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
     * Whether this ISP has done anything yet, which decides between a dashboard and a
     * getting-started list.
     */
    #[Computed]
    public function setup(): array
    {
        return [
            'routers' => Router::query()->count(),
            'plans' => Plan::query()->count(),
            'mpesa' => \App\Models\MpesaConfiguration::query()->where('is_active', true)->exists(),
        ];
    }

    #[Computed]
    public function isNew(): bool
    {
        return $this->setup['routers'] === 0 && $this->setup['plans'] === 0;
    }

    /**
     * @return array{today: string, week: string, month: string, sales: int}
     */
    #[Computed]
    public function revenue(): array
    {
        $sum = fn ($since): string => (string) Payment::query()
            ->paid()
            ->where('payments.paid_at', '>=', $since)
            ->sum('amount');

        return [
            'today' => $sum(now()->startOfDay()),
            'week' => $sum(now()->subDays(6)->startOfDay()),
            'month' => $sum(now()->subDays(29)->startOfDay()),
            'sales' => Payment::query()->paid()->where('payments.paid_at', '>=', now()->startOfDay())->count(),
        ];
    }

    /**
     * @return Collection<int, Router>
     */
    #[Computed]
    public function routers(): Collection
    {
        return Router::query()->orderBy('name')->get();
    }

    /**
     * @return array{customers: int, blocked: int, services: int, activeServices: int}
     */
    #[Computed]
    public function subscribers(): array
    {
        return [
            'customers' => Customer::query()->count(),
            'blocked' => Customer::query()->where('status', \App\Enums\CustomerStatus::Blocked)->count(),
            'services' => ServiceAccount::query()->count(),
            'activeServices' => ServiceAccount::query()->where('status', ServiceAccountStatus::Active)->count(),
        ];
    }

    /**
     * Live HotSpot packages, and the ones the sweeper could not place.
     *
     * @return array{active: int, failed: int, expiringSoon: int}
     */
    #[Computed]
    public function hotspot(): array
    {
        return [
            'active' => HotspotUser::query()->where('status', HotspotUserStatus::Active)->count(),
            // Paid for but never written to a router: someone is owed access.
            'failed' => HotspotUser::query()->where('status', HotspotUserStatus::Failed)->count(),
            'expiringSoon' => HotspotUser::query()
                ->where('status', HotspotUserStatus::Active)
                ->whereBetween('expires_at', [now(), now()->addHour()])
                ->count(),
        ];
    }

    /**
     * @return Collection<int, Payment>
     */
    #[Computed]
    public function recentPayments(): Collection
    {
        return Payment::query()->paid()->with('plan')->latest('paid_at')->limit(6)->get();
    }

    #[Computed]
    public function currency(): string
    {
        return Tenancy::tenant()?->currency ?? 'KES';
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">{{ Tenancy::tenant()?->name ?? __('Dashboard') }}</flux:heading>
            <flux:subheading>{{ now()->format('l j F') }}</flux:subheading>
        </div>

        @unless ($this->hasTenant)
            <flux:card class="py-12 text-center">
                <flux:heading size="lg">{{ __('No ISP linked to this account') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Ask an administrator to attach your account to an ISP before you can see anything here.') }}
                </flux:text>
            </flux:card>
        @else

        @if ($this->isNew)
            {{-- A brand-new ISP has nothing to chart, so it gets the list of things that have
                 to happen before any of this fills in. --}}
            <flux:card class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Getting started') }}</flux:heading>
                    <flux:subheading>{{ __('Three things stand between you and your first sale.') }}</flux:subheading>
                </div>

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
                            <flux:text class="font-medium">{{ $step['title'] }}</flux:text>
                            <flux:text size="sm" class="opacity-70">{{ $step['text'] }}</flux:text>
                        </div>

                        @unless ($step['done'])
                            @can($step['can'])
                                <flux:button size="sm" :href="route($step['route'])" wire:navigate>{{ __('Set up') }}</flux:button>
                            @endcan
                        @endunless
                    </div>
                @endforeach
            </flux:card>
        @endif

        @if ($this->canSeeMoney)
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:card>
                    <flux:text size="sm" class="opacity-60">{{ __('Today') }}</flux:text>
                    <p class="mt-1 text-3xl font-bold tabular-nums">{{ number_format((float) $this->revenue['today'], 2) }}</p>
                    <flux:text size="sm" class="opacity-60">
                        {{ trans_choice('{0}no sales yet|{1}:count sale|[2,*]:count sales', $this->revenue['sales'], ['count' => $this->revenue['sales']]) }}
                    </flux:text>
                </flux:card>

                <flux:card>
                    <flux:text size="sm" class="opacity-60">{{ __('Last 7 days') }}</flux:text>
                    <p class="mt-1 text-3xl font-bold tabular-nums">{{ number_format((float) $this->revenue['week'], 2) }}</p>
                    <flux:text size="sm" class="opacity-60">{{ $this->currency }}</flux:text>
                </flux:card>

                <flux:card>
                    <flux:text size="sm" class="opacity-60">{{ __('Last 30 days') }}</flux:text>
                    <p class="mt-1 text-3xl font-bold tabular-nums">{{ number_format((float) $this->revenue['month'], 2) }}</p>
                    <flux:text size="sm" class="opacity-60">{{ $this->currency }}</flux:text>
                </flux:card>

                <flux:card class="flex flex-col justify-between">
                    <div>
                        <flux:text size="sm" class="opacity-60">{{ __('Online now') }}</flux:text>
                        <p class="mt-1 text-3xl font-bold tabular-nums">{{ $this->hotspot['active'] }}</p>
                        <flux:text size="sm" class="opacity-60">{{ __('HotSpot packages running') }}</flux:text>
                    </div>
                </flux:card>
            </div>
        @endif

        @if ($this->hotspot['failed'] > 0)
            {{-- Someone has paid and has no access. Nothing else on this page matters more. --}}
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>
                    {{ trans_choice('{1}:count customer paid but is not online|[2,*]:count customers paid but are not online', $this->hotspot['failed'], ['count' => $this->hotspot['failed']]) }}
                </flux:callout.heading>
                <flux:callout.text>
                    {{ __('Their package could not be written to a router. The sweeper retries every minute; check the router is reachable.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="grid gap-4 lg:grid-cols-2">
            @if ($this->canSeeNetwork)
                <flux:card class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">{{ __('Routers') }}</flux:heading>
                        <flux:button size="sm" variant="ghost" icon="chart-bar" :href="route('monitor.index')" wire:navigate>
                            {{ __('Monitor') }}
                        </flux:button>
                    </div>

                    @forelse ($this->routers as $router)
                        <div class="flex items-center justify-between gap-3" wire:key="router-{{ $router->id }}">
                            <div class="min-w-0">
                                <flux:link :href="route('routers.show', $router)" wire:navigate variant="ghost">
                                    {{ $router->name }}
                                </flux:link>
                                <flux:text size="sm" class="font-mono opacity-60">{{ $router->host }}</flux:text>
                            </div>

                            <div class="flex items-center gap-3">
                                @if ($router->status === RouterStatus::Online)
                                    <flux:text size="sm" class="opacity-60">
                                        {{ __('up :time', ['time' => $router->uptimeForHumans() ?? '—']) }}
                                    </flux:text>
                                @endif
                                <flux:badge size="sm" :color="$router->status->color()">{{ $router->status->label() }}</flux:badge>
                            </div>
                        </div>
                    @empty
                        <flux:text>{{ __('No routers yet.') }}</flux:text>
                    @endforelse
                </flux:card>
            @endif

            @if ($this->canSeeCustomers)
                <flux:card class="flex flex-col gap-4">
                    <div class="flex items-center justify-between">
                        <flux:heading size="lg">{{ __('Subscribers') }}</flux:heading>
                        <flux:button size="sm" variant="ghost" :href="route('customers.index')" wire:navigate>
                            {{ __('All customers') }}
                        </flux:button>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-2xl font-bold tabular-nums">{{ number_format($this->subscribers['customers']) }}</p>
                            <flux:text size="sm" class="opacity-60">{{ __('Customers') }}</flux:text>
                        </div>
                        <div>
                            <p class="text-2xl font-bold tabular-nums">
                                {{ number_format($this->subscribers['activeServices']) }}<span class="text-base font-normal opacity-50">/{{ number_format($this->subscribers['services']) }}</span>
                            </p>
                            <flux:text size="sm" class="opacity-60">{{ __('Services active') }}</flux:text>
                        </div>
                    </div>

                    @if ($this->subscribers['blocked'] > 0)
                        <flux:text size="sm" class="opacity-70">
                            {{ trans_choice('{1}:count customer is blocked|[2,*]:count customers are blocked', $this->subscribers['blocked'], ['count' => $this->subscribers['blocked']]) }}
                        </flux:text>
                    @endif
                </flux:card>
            @endif
        </div>

        @if ($this->canSeeMoney)
            <flux:card class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Recent payments') }}</flux:heading>
                    <flux:button size="sm" variant="ghost" :href="route('reports.earnings')" wire:navigate>
                        {{ __('Earnings') }}
                    </flux:button>
                </div>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('When') }}</flux:table.column>
                        <flux:table.column>{{ __('Phone') }}</flux:table.column>
                        <flux:table.column>{{ __('Package') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($this->recentPayments as $payment)
                            <flux:table.row wire:key="pay-{{ $payment->id }}">
                                <flux:table.cell class="whitespace-nowrap">{{ $payment->paid_at?->diffForHumans() ?? '—' }}</flux:table.cell>
                                <flux:table.cell>{{ $payment->phone }}</flux:table.cell>
                                <flux:table.cell>{{ $payment->plan?->name ?? '—' }}</flux:table.cell>
                                <flux:table.cell align="end" variant="strong" class="tabular-nums">
                                    {{ number_format((float) $payment->amount, 2) }}
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center">
                                    <flux:text>{{ __('No payments yet. They appear here the moment a customer buys.') }}</flux:text>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif

        @endunless
    </div>
</section>
