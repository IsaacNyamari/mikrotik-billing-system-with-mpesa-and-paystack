{{-- The public header. Alpine ships with Livewire, so the mobile menu needs no build step
     of its own. --}}
<header
    x-data="{ open: false, scrolled: false }"
    x-on:scroll.window="scrolled = window.scrollY > 8"
    :class="scrolled ? 'shadow-sm shadow-slate-900/5 border-slate-200' : 'border-transparent'"
    class="sticky top-0 z-50 border-b border-transparent bg-white/90 backdrop-blur-md transition"
>
    <nav class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6" aria-label="{{ __('Main') }}">
        <a href="{{ route('home') }}" wire:navigate class="flex shrink-0 items-center">
            <img src="{{ asset('images/humsols-logo.png') }}" alt="{{ config('app.name') }}" class="h-9 w-auto sm:h-10">
        </a>

        <div class="hidden items-center gap-1 rounded-xl border border-slate-200 bg-white/70 p-1 md:flex">
            <x-frontend.nav-link :href="route('home')" :active="request()->routeIs('home')">{{ __('Home') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('services')" :active="request()->routeIs('services', 'pricing')">{{ __('Services') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('about')" :active="request()->routeIs('about')">{{ __('About') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('contact')" :active="request()->routeIs('contact')">{{ __('Contact') }}</x-frontend.nav-link>
        </div>

        <div class="hidden items-center gap-2 md:flex">
            @auth
                <a href="{{ route('dashboard') }}" wire:navigate class="fe-btn-primary !px-4 !py-2.5">
                    {{ __('Dashboard') }}
                </a>
            @else
                <a href="{{ route('login') }}" wire:navigate class="rounded-lg px-3.5 py-2 text-sm font-semibold text-slate-700 hover:text-brand-700">
                    {{ __('Log in') }}
                </a>
                {{-- Straight to sign-up: there is no application to approve, so sending a
                     prospect to a contact form would invent a wait that does not exist. --}}
                <a href="{{ route('register') }}" wire:navigate class="fe-btn-primary !px-4 !py-2.5">
                    {{ __('Start free') }}
                </a>
            @endauth
        </div>

        <button
            type="button"
            class="inline-flex size-10 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-100 md:hidden"
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

    <div x-show="open" x-cloak x-transition.opacity class="border-t border-slate-200 bg-white md:hidden">
        <div class="mx-auto flex max-w-6xl flex-col gap-1 px-4 py-4">
            <x-frontend.nav-link :href="route('home')" :active="request()->routeIs('home')" block>{{ __('Home') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('services')" :active="request()->routeIs('services', 'pricing')" block>{{ __('Services') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('about')" :active="request()->routeIs('about')" block>{{ __('About') }}</x-frontend.nav-link>
            <x-frontend.nav-link :href="route('contact')" :active="request()->routeIs('contact')" block>{{ __('Contact') }}</x-frontend.nav-link>

            <div class="mt-3 grid grid-cols-2 gap-2">
                @auth
                    <a href="{{ route('dashboard') }}" wire:navigate class="fe-btn-primary col-span-2">{{ __('Dashboard') }}</a>
                @else
                    <a href="{{ route('login') }}" wire:navigate class="fe-btn-outline">{{ __('Log in') }}</a>
                    <a href="{{ route('register') }}" wire:navigate class="fe-btn-primary">{{ __('Start free') }}</a>
                @endauth
            </div>
        </div>
    </div>
</header>
