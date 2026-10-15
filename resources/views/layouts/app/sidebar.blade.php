@use('App\Support\Authorization\Permissions')
@use('App\Support\Tenancy\Facades\Tenancy')
@php
    $user = auth()->user();
    $tenant = Tenancy::tenant();
    $role = $user->isSuperAdmin() ? __('Platform admin') : ($user->roles->first()?->label ?? __('Operator'));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-slate-100 dark:bg-zinc-900">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-950">
            <flux:sidebar.header>
                {{-- The ISP's own name where there is one: an operator should never have to wonder
                     whose network they are looking at. --}}
                <a href="{{ route('dashboard') }}" wire:navigate class="flex min-w-0 items-center gap-2.5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white shadow-sm">
                        <x-app-logo-icon class="size-5 fill-current text-white" />
                    </span>
                    <span class="min-w-0 leading-tight">
                        <span class="block truncate text-sm font-bold text-zinc-900 dark:text-white">{{ $tenant?->name ?? config('app.name') }}</span>
                        <span class="block text-[10px] font-semibold uppercase tracking-wider text-zinc-500">{{ __('ISP management') }}</span>
                    </span>
                </a>
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @if ($user->isSuperAdmin())
                {{-- A platform admin can be looking at any ISP, so which one has to be on screen at
                     all times — otherwise it is far too easy to change the wrong ISP's settings. --}}
                <div class="px-2 pb-1">
                    <flux:button size="sm" variant="ghost" icon="building-office-2" icon-trailing="chevrons-up-down" :href="route('tenants.select')" wire:navigate class="w-full justify-start">
                        {{ $tenant?->name ?? __('All ISPs') }}
                    </flux:button>
                </div>
            @endif

            <flux:sidebar.nav>
                <flux:sidebar.group expandable :expanded="request()->routeIs('dashboard', 'reports.*', 'monitor.*')" icon="squares-2x2" :heading="__('Dashboards')" class="grid">
                    <flux:sidebar.item :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>{{ __('Overview') }}</flux:sidebar.item>
                    @can(Permissions::REPORTS_VIEW_REVENUE)
                        <flux:sidebar.item :href="route('reports.earnings')" :current="request()->routeIs('reports.earnings')" wire:navigate>{{ __('Business') }}</flux:sidebar.item>
                    @endcan
                    @can('viewAny', App\Models\Router::class)
                        <flux:sidebar.item :href="route('monitor.index')" :current="request()->routeIs('monitor.*')" wire:navigate>{{ __('Network health') }}</flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>

                @can('viewAny', App\Models\Router::class)
                    <flux:sidebar.group class="grid">
                        <div class="px-3 pb-1 pt-4 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-500">{{ __('Operations & network') }}</div>
                        <flux:sidebar.group expandable :expanded="request()->routeIs('routers.*') && ! request()->routeIs('routers.hotspot')" icon="server-stack" :heading="__('Network')" class="grid">
                            <flux:sidebar.item :href="route('routers.index')" :current="request()->routeIs('routers.index', 'routers.show', 'routers.create', 'routers.edit', 'routers.setup')" wire:navigate>{{ __('Routers') }}</flux:sidebar.item>
                            <flux:sidebar.item :href="route('routers.manage')" :current="request()->routeIs('routers.console') && ! in_array(request()->route('menu'), ['bridges', 'bridge-ports'], true)" wire:navigate>{{ __('Router console') }}</flux:sidebar.item>
                            <flux:sidebar.item :href="route('routers.manage', ['section' => 'bridges'])" :current="request()->routeIs('routers.console') && in_array(request()->route('menu'), ['bridges', 'bridge-ports'], true)" wire:navigate>{{ __('Bridges') }}</flux:sidebar.item>
                        </flux:sidebar.group>

                        <flux:sidebar.group expandable :expanded="request()->routeIs('plans.*', 'settings.portal', 'routers.hotspot')" icon="wifi" :heading="__('Hotspot')" class="grid">
                            @can(Permissions::ROUTERS_PROVISION)
                                <flux:sidebar.item :href="route('routers.manage', ['section' => 'hotspot'])" :current="request()->routeIs('routers.hotspot')" wire:navigate>{{ __('HotSpot setup') }}</flux:sidebar.item>
                            @endcan
                            @can('viewAny', App\Models\Plan::class)
                                <flux:sidebar.item :href="route('plans.index')" :current="request()->routeIs('plans.*')" wire:navigate>{{ __('Packages') }}</flux:sidebar.item>
                            @endcan
                            @can(Permissions::HOTSPOT_UPDATE_PORTAL)
                                <flux:sidebar.item :href="route('settings.portal')" :current="request()->routeIs('settings.portal')" wire:navigate>{{ __('Portal') }}</flux:sidebar.item>
                            @endcan
                        </flux:sidebar.group>

                        <flux:sidebar.item icon="chart-bar" :href="route('monitor.index')" :current="request()->routeIs('monitor.*')" wire:navigate>{{ __('MikroTik monitor') }}</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @can('viewAny', App\Models\Customer::class)
                    <flux:sidebar.group class="grid">
                        <div class="px-3 pb-1 pt-4 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-500">{{ __('Customers') }}</div>
                        <flux:sidebar.item icon="users" :href="route('customers.index')" :current="request()->routeIs('customers.*')" wire:navigate>{{ __('Customers') }}</flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @canany([Permissions::REPORTS_VIEW_REVENUE, Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS])
                    <flux:sidebar.group class="grid">
                        <div class="px-3 pb-1 pt-4 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-500">{{ __('Billing') }}</div>
                        @can(Permissions::REPORTS_VIEW_REVENUE)
                            <flux:sidebar.item icon="banknotes" :href="route('reports.earnings')" :current="request()->routeIs('reports.earnings')" wire:navigate>{{ __('Earnings') }}</flux:sidebar.item>
                        @endcan
                        @can(Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS)
                            <flux:sidebar.item icon="credit-card" :href="route('settings.mpesa')" :current="request()->routeIs('settings.mpesa')" wire:navigate>{{ __('M-PESA & Paystack') }}</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany

                {{-- What this ISP pays the platform. Shown to anyone signed in to an ISP: it is the
                     page they are sent to when an invoice lapses, so it must be one they can also
                     find on purpose rather than only by being redirected. --}}
                @if (Tenancy::has())
                    <flux:sidebar.group class="grid">
                        <div class="px-3 pb-1 pt-4 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-500">{{ __('Account') }}</div>
                        <flux:sidebar.item icon="receipt-percent" :href="route('billing.index')" :current="request()->routeIs('billing.*')" wire:navigate>{{ __('Subscription') }}</flux:sidebar.item>
                    </flux:sidebar.group>
                @endif

                @if ($user->isSuperAdmin())
                    <flux:sidebar.group class="grid">
                        <div class="px-3 pb-1 pt-4 text-[10px] font-semibold uppercase tracking-wider text-zinc-400 in-data-flux-sidebar-collapsed-desktop:hidden dark:text-zinc-500">{{ __('Platform admin') }}</div>
                        <flux:sidebar.item icon="building-office-2" :href="route('platform.subscriptions')" :current="request()->routeIs('platform.*')" wire:navigate>{{ __('ISP subscriptions') }}</flux:sidebar.item>
                    </flux:sidebar.group>
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            {{-- The manual, pinned to the bottom where a help link is looked for. It is a page of
                 this application rather than a link off to somebody else's website: most of it is
                 read while a router is down, which is exactly when leaving here is least useful. --}}
            <flux:sidebar.nav>
                <flux:sidebar.item icon="book-open-text" :href="route('docs.index')" :current="request()->routeIs('docs.*')" wire:navigate>{{ __('Documentation') }}</flux:sidebar.item>
            </flux:sidebar.nav>
        </flux:sidebar>

        {{-- The top bar: search, theme, help and who is signed in. One bar at every width; on a
             phone it carries the menu button and gives up the search box for room. --}}
        <flux:header sticky class="border-b border-zinc-200 bg-white/90 backdrop-blur dark:border-zinc-700 dark:bg-zinc-950/90">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            @can('viewAny', App\Models\Customer::class)
                <form method="GET" action="{{ route('customers.index') }}" class="hidden w-full max-w-md sm:block"
                      x-data @keydown.window.ctrl.k.prevent="$refs.search.focus()" @keydown.window.meta.k.prevent="$refs.search.focus()">
                    <label class="relative block">
                        <span class="sr-only">{{ __('Search customers') }}</span>
                        <flux:icon.magnifying-glass variant="micro" class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                        <input x-ref="search" type="search" name="q" placeholder="{{ __('Search customers, phones or codes') }}"
                               class="h-9 w-full rounded-lg border-0 bg-zinc-100 ps-9 pe-16 text-sm text-zinc-900 ring-1 ring-zinc-200 placeholder:text-zinc-400 focus:bg-white focus:ring-2 focus:ring-blue-500 dark:bg-zinc-800 dark:text-white dark:ring-zinc-700">
                        <span class="pointer-events-none absolute end-2 top-1/2 flex -translate-y-1/2 gap-1">
                            <kbd class="rounded border border-zinc-300 bg-white px-1.5 text-[10px] font-semibold text-zinc-500 dark:border-zinc-600 dark:bg-zinc-900">Ctrl</kbd>
                            <kbd class="rounded border border-zinc-300 bg-white px-1.5 text-[10px] font-semibold text-zinc-500 dark:border-zinc-600 dark:bg-zinc-900">K</kbd>
                        </span>
                    </label>
                </form>
            @endcan

            <flux:spacer />

            <div class="flex items-center gap-1.5 sm:gap-2">
                <flux:tooltip :content="__('Light or dark')">
                    <flux:button variant="ghost" size="sm" square x-data x-on:click="$flux.dark = ! $flux.dark" :aria-label="__('Switch light or dark theme')">
                        <flux:icon.moon variant="mini" class="size-5 dark:hidden" />
                        <flux:icon.sun variant="mini" class="hidden size-5 dark:block" />
                    </flux:button>
                </flux:tooltip>

                <flux:tooltip :content="__('Documentation')">
                    <flux:button variant="ghost" size="sm" square icon="question-mark-circle" :href="route('docs.index')" wire:navigate :aria-label="__('Documentation')" />
                </flux:tooltip>

                <flux:dropdown position="bottom" align="end">
                    <button type="button" class="flex items-center gap-2 rounded-lg px-1.5 py-1 text-start hover:bg-zinc-100 dark:hover:bg-zinc-800" data-test="sidebar-menu-button">
                        <span class="flex size-8 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700 dark:bg-blue-900/50 dark:text-blue-200">{{ $user->initials() }}</span>
                        <span class="hidden leading-tight md:block">
                            <span class="block max-w-40 truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $user->name }}</span>
                            <span class="block max-w-40 truncate text-[11px] text-zinc-500">{{ $role }}</span>
                        </span>
                    </button>

                    <flux:menu>
                        <div class="px-2 py-1.5 text-sm">
                            <p class="truncate font-semibold">{{ $user->name }}</p>
                            <p class="truncate text-xs text-zinc-500">{{ $user->email }}</p>
                        </div>
                        <flux:menu.separator />
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <flux:button type="submit" size="sm" icon="arrow-right-start-on-rectangle" data-test="logout-button">
                        <span class="hidden sm:inline">{{ __('Sign out') }}</span>
                    </flux:button>
                </form>
            </div>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
