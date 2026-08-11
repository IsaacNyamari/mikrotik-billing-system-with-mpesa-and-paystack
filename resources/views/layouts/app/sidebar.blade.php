<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @if (auth()->user()->isSuperAdmin())
                {{-- A platform admin can be looking at any ISP, so which one has to be on
                     screen at all times — otherwise it is far too easy to change the wrong
                     ISP's settings. --}}
                <div class="px-2 pb-2">
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="building-office-2"
                        icon-trailing="chevrons-up-down"
                        :href="route('tenants.select')"
                        wire:navigate
                        class="w-full justify-start"
                    >
                        {{ App\Support\Tenancy\Facades\Tenancy::tenant()?->name ?? __('All ISPs') }}
                    </flux:button>
                </div>
            @endif

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Platform')" class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>

                @can('viewAny', App\Models\Customer::class)
                    <flux:sidebar.group :heading="__('Subscribers')" class="grid">
                        <flux:sidebar.item icon="users" :href="route('customers.index')" :current="request()->routeIs('customers.*')" wire:navigate>
                            {{ __('Customers') }}
                        </flux:sidebar.item>
                    </flux:sidebar.group>
                @endcan

                @can('viewAny', App\Models\Router::class)
                    <flux:sidebar.group :heading="__('Network')" class="grid">
                        <flux:sidebar.item icon="server" :href="route('routers.index')" :current="request()->routeIs('routers.*')" wire:navigate>
                            {{ __('Routers') }}
                        </flux:sidebar.item>

                        <flux:sidebar.item icon="chart-bar" :href="route('monitor.index')" :current="request()->routeIs('monitor.*')" wire:navigate>
                            {{ __('MikroTik Monitor') }}
                        </flux:sidebar.item>

                        @can('viewAny', App\Models\Plan::class)
                            <flux:sidebar.item icon="wifi" :href="route('plans.index')" :current="request()->routeIs('plans.*')" wire:navigate>
                                {{ __('Plans') }}
                            </flux:sidebar.item>
                        @endcan

                        @can(App\Support\Authorization\Permissions::HOTSPOT_UPDATE_PORTAL)
                            <flux:sidebar.item icon="paint-brush" :href="route('settings.portal')" :current="request()->routeIs('settings.portal')" wire:navigate>
                                {{ __('Portal') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcan

                @canany([App\Support\Authorization\Permissions::REPORTS_VIEW_REVENUE, App\Support\Authorization\Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS])
                    <flux:sidebar.group :heading="__('Money')" class="grid">
                        @can(App\Support\Authorization\Permissions::REPORTS_VIEW_REVENUE)
                            <flux:sidebar.item icon="banknotes" :href="route('reports.earnings')" :current="request()->routeIs('reports.earnings')" wire:navigate>
                                {{ __('Earnings') }}
                            </flux:sidebar.item>
                        @endcan

                        @can(App\Support\Authorization\Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS)
                            <flux:sidebar.item icon="credit-card" :href="route('settings.mpesa')" :current="request()->routeIs('settings.mpesa')" wire:navigate>
                                {{ __('M-PESA') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endcanany
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('Repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="https://laravel.com/docs/starter-kits#livewire" target="_blank">
                    {{ __('Documentation') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
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
