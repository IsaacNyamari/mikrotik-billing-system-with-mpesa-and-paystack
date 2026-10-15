@props([
    'heading' => null,
    'lead' => null,
])

<section class="px-4 py-16 sm:px-6 sm:py-20">
    <div class="relative isolate mx-auto max-w-6xl overflow-hidden rounded-3xl bg-brand-900 px-6 py-14 text-center shadow-2xl shadow-brand-900/30 sm:px-16 sm:py-16">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
            <div class="absolute inset-0 bg-linear-to-br from-brand-700 via-brand-800 to-brand-950"></div>
            <div class="absolute -right-24 -top-24 size-80 rounded-full bg-mint-400/30 blur-3xl"></div>
            <div class="absolute -bottom-32 -left-16 size-80 rounded-full bg-brand-400/30 blur-3xl"></div>
        </div>

        <h2 class="text-3xl font-bold tracking-tight text-balance text-white sm:text-4xl">
            {{ $heading ?? __('Start selling internet this week') }}
        </h2>

        <p class="mx-auto mt-4 max-w-xl text-pretty text-brand-100">
            {{ $lead ?? __('Open your account now — nobody has to approve you. One setup fee, then you only pay us when your customers pay you.') }}
        </p>

        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('register') }}" wire:navigate class="fe-btn-mint">
                {{ __('Create your account') }}
            </a>
            <a href="{{ route('contact') }}" wire:navigate class="fe-btn-glass">
                {{ __('Talk to us first') }}
            </a>
        </div>
    </div>
</section>
