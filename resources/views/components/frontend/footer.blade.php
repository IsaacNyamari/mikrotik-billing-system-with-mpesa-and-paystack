<footer class="border-t border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900/40">
    <div class="mx-auto grid max-w-6xl gap-8 px-6 py-12 sm:grid-cols-2 lg:grid-cols-4">
        <div class="lg:col-span-2">
            <x-app-logo-full class="h-10" />
            <p class="mt-2 max-w-sm text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('Billing, HotSpot vouchers and MikroTik control for internet service providers. Built for the way ISPs here actually sell — a few shillings at a time, from a phone.') }}
            </p>
        </div>

        <div>
            <p class="text-sm font-semibold">{{ __('Platform') }}</p>
            <ul class="mt-3 space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                <li><a href="{{ route('services') }}" wire:navigate class="hover:text-zinc-900 dark:hover:text-white">{{ __('Services') }}</a></li>
                <li><a href="{{ route('services') }}#pricing" class="hover:text-zinc-900 dark:hover:text-white">{{ __('Pricing') }}</a></li>
                <li><a href="{{ route('about') }}" wire:navigate class="hover:text-zinc-900 dark:hover:text-white">{{ __('About') }}</a></li>
            </ul>
        </div>

        <div>
            <p class="text-sm font-semibold">{{ __('Talk to us') }}</p>
            <ul class="mt-3 space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                <li><a href="{{ route('contact') }}" wire:navigate class="hover:text-zinc-900 dark:hover:text-white">{{ __('Contact') }}</a></li>
                <li><a href="{{ route('login') }}" wire:navigate class="hover:text-zinc-900 dark:hover:text-white">{{ __('Log in') }}</a></li>
            </ul>
        </div>
    </div>

    <div class="border-t border-zinc-200 dark:border-zinc-800">
        <div class="mx-auto flex max-w-6xl flex-col gap-2 px-6 py-6 text-xs text-zinc-500 sm:flex-row sm:items-center sm:justify-between">
            <p>&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</p>
            <p>{{ __('Made for ISPs in Kenya.') }}</p>
        </div>
    </div>
</footer>
