@props([
    'heading' => null,
    'lead' => null,
])

<section class="px-6 py-16 sm:py-20">
    <div class="mx-auto max-w-6xl overflow-hidden rounded-3xl bg-zinc-900 px-6 py-14 text-center sm:px-16 dark:bg-white">
        <h2 class="text-3xl font-semibold tracking-tight text-white sm:text-4xl dark:text-zinc-900">
            {{ $heading ?? __('Start selling internet this week') }}
        </h2>

        <p class="mx-auto mt-4 max-w-xl text-zinc-300 dark:text-zinc-600">
            {{ $lead ?? __('Open your account now — nobody has to approve you. One setup fee, then you only pay us when your customers pay you.') }}
        </p>

        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            <a href="{{ route('register') }}" wire:navigate class="rounded-xl bg-white px-6 py-3 text-sm font-semibold text-zinc-900 hover:bg-zinc-200 dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-700">
                {{ __('Create your account') }}
            </a>
            <a href="{{ route('contact') }}" wire:navigate class="rounded-xl border border-zinc-700 px-6 py-3 text-sm font-semibold text-white hover:bg-zinc-800 dark:border-zinc-300 dark:text-zinc-900 dark:hover:bg-zinc-100">
                {{ __('Talk to us first') }}
            </a>
        </div>
    </div>
</section>
