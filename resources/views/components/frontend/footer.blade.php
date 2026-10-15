<footer class="relative overflow-hidden bg-brand-950 text-brand-100">
    <div aria-hidden="true" class="pointer-events-none absolute -right-40 -top-40 size-96 rounded-full bg-mint-400/10 blur-3xl"></div>

    <div class="relative mx-auto grid max-w-6xl gap-10 px-4 py-14 sm:grid-cols-2 sm:px-6 lg:grid-cols-4">
        <div class="lg:col-span-2">
            {{-- The navy lettering needs a light ground, so the wordmark sits on a white plate. --}}
            <a href="{{ route('home') }}" wire:navigate class="inline-flex rounded-xl bg-white px-3 py-2">
                <img src="{{ asset('images/humsols-logo.png') }}" alt="{{ config('app.name') }}" class="h-9 w-auto">
            </a>
            <p class="mt-5 max-w-sm text-sm leading-relaxed text-brand-200">
                {{ __('Billing, HotSpot vouchers and MikroTik control for internet service providers. Built for the way ISPs here actually sell — a few shillings at a time, from a phone.') }}
            </p>
        </div>

        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-mint-300">{{ __('Platform') }}</p>
            <ul class="mt-4 space-y-2.5 text-sm">
                <li><a href="{{ route('services') }}" wire:navigate class="hover:text-white">{{ __('Services') }}</a></li>
                <li><a href="{{ route('services') }}#pricing" class="hover:text-white">{{ __('Pricing') }}</a></li>
                <li><a href="{{ route('about') }}" wire:navigate class="hover:text-white">{{ __('About') }}</a></li>
            </ul>
        </div>

        <div>
            <p class="text-xs font-bold uppercase tracking-[0.18em] text-mint-300">{{ __('Talk to us') }}</p>
            <ul class="mt-4 space-y-2.5 text-sm">
                <li><a href="{{ route('contact') }}" wire:navigate class="hover:text-white">{{ __('Contact') }}</a></li>
                <li><a href="{{ route('login') }}" wire:navigate class="hover:text-white">{{ __('Log in') }}</a></li>
                <li><a href="{{ route('register') }}" wire:navigate class="hover:text-white">{{ __('Create an account') }}</a></li>
            </ul>
        </div>
    </div>

    <div class="relative border-t border-white/10">
        <div class="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-6 text-xs text-brand-300 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</p>
            <p>{{ __('Made for ISPs in Kenya.') }}</p>
        </div>
    </div>
</footer>
