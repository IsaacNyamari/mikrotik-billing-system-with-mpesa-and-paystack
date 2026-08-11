@props([
    'value',
    'label',
])

<div {{ $attributes->class('rounded-2xl border border-zinc-200 bg-white p-6 text-center dark:border-zinc-800 dark:bg-zinc-900') }}>
    <p class="text-3xl font-semibold tracking-tight text-zinc-900 dark:text-white">{{ $value }}</p>
    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ $label }}</p>
</div>
