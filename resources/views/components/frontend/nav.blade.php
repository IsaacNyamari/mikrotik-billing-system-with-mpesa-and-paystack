{{-- The public header. Alpine ships with Livewire, so the mobile menu needs no build step
     of its own. --}}
<header
    x-data="{ open: false }"
    class="sticky top-0 z-50 border-b border-zinc-200/80 bg-white/85 backdrop-blur dark:border-zinc-800 dark:bg-zinc-950/85"
>
    <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-4" aria-label="{{ __('Main') }}">
        <a href="{{ route('home') }}" wire:navigate class="flex items-center">
            <x-app-logo-full class="h-10" />
        </a>

        <div class="hidden items-center gap-1 md:flex">
            <x-frontend.nav-link :href="route('home')" :active="request()->routeIs('home')">{{ __('Home') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('services')" :active="request()->routeIs('services', 'pricing')">{{ __('Services') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('about')" :active="request()->routeIs('about')">{{ __('About') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('contact')" :active="request()->routeIs('contact')">{{ __('Contact') }}</x-frontend.nav-link>
        </div>

        <div class="hidden items-center gap-3 md:flex">
            @auth
                <a href="{{ route('dashboard') }}" wire:navigate class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                    {{ __('Dashboard') }}
                </a>
            @else
                <a href="{{ route('login') }}" wire:navigate class="text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                    {{ __('Log in') }}
                </a>
                {{-- Straight to sign-up: there is no application to approve, so sending a
                     prospect to a contact form would invent a wait that does not exist. --}}
                <a href="{{ route('register') }}" wire:navigate class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200">
                    {{ __('Start free') }}
                </a>
            @endauth
        </div>

        <button
            type="button"
            class="inline-flex size-10 items-center justify-center rounded-lg text-zinc-600 hover:bg-zinc-100 md:hidden dark:text-zinc-400 dark:hover:bg-zinc-800"
            x-on:click="open = ! open"
            :aria-expanded="open.toString()"
            aria-label="{{ __('Menu') }}"
        >
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path x-show="! open" d="M4 7h16M4 12h16M4 17h16" stroke-linecap="round"/>
                <path x-show="open" x-cloak d="m6 6 12 12M18 6 6 18" stroke-linecap="round"/>
            </svg>
        </button>
    </nav>

    <div x-show="open" x-cloak class="border-t border-zinc-200 md:hidden dark:border-zinc-800">
        <div class="mx-auto flex max-w-6xl flex-col gap-1 px-6 py-4">
            <x-frontend.nav-link :href="route('home')" :active="request()->routeIs('home')" block>{{ __('Home') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('services')" :active="request()->routeIs('services', 'pricing')" block>{{ __('Services') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('about')" :active="request()->routeIs('about')" block>{{ __('About') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('contact')" :active="request()->routeIs('contact')" block>{{ __('Contact') }}</x-frontend.nav-link>

            <a href="{{ auth()->check() ? route('dashboard') : route('login') }}" wire:navigate
               class="mt-2 rounded-lg bg-zinc-900 px-4 py-2.5 text-center text-sm font-medium text-white dark:bg-white dark:text-zinc-900">
                {{ auth()->check() ? __('Dashboard') : __('Log in') }}
            </a>
        </div>
    </div>
</header>
