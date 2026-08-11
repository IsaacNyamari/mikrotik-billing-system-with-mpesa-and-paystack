@props([
    'number',
    'title',
])

<div {{ $attributes->class('relative pl-14') }}>
    <span class="absolute left-0 top-0 flex size-10 items-center justify-center rounded-xl border border-zinc-200 bg-white font-semibold text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
        {{ $number }}
    </span>

    <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $title }}</h3>
    <p class="mt-1.5 text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">{{ $slot }}</p>
</div>
