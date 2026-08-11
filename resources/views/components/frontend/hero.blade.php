@props([
    'eyebrow' => null,
    'heading',
    'lead' => null,
    'compact' => false,
])

<section class="relative overflow-hidden px-6 {{ $compact ? 'py-16 sm:py-20' : 'py-20 sm:py-28' }}">
    {{-- A soft wash behind the headline rather than a photograph: it costs nothing to load
         and cannot be the reason a page looks broken on a slow connection. --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(60%_60%_at_50%_0%,theme(colors.zinc.200)_0%,transparent_70%)] dark:bg-[radial-gradient(60%_60%_at_50%_0%,theme(colors.zinc.800)_0%,transparent_70%)]"></div>

    <div class="mx-auto max-w-3xl text-center">
        @if ($eyebrow)
            <p class="inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-3 py-1 text-xs font-medium text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">
                {{ $eyebrow }}
            </p>
        @endif

        <h1 class="mt-5 text-4xl font-semibold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
            {{ $heading }}
        </h1>

        @if ($lead)
            <p class="mx-auto mt-5 max-w-2xl text-lg text-zinc-600 dark:text-zinc-400">{{ $lead }}</p>
        @endif

        @isset($actions)
            <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
                {{ $actions }}
            </div>
        @endisset
    </div>

    @isset($below)
        <div class="mx-auto mt-14 max-w-6xl">
            {{ $below }}
        </div>
    @endisset
</section>
