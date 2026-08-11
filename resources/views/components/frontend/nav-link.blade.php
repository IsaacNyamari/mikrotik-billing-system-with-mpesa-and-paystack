@props([
    'href',
    'active' => false,
    'block' => false,
])

<a
    href="{{ $href }}"
    wire:navigate
    @if ($active) aria-current="page" @endif
    {{ $attributes->class([
        'rounded-lg px-3 py-2 text-sm font-medium transition',
        'block' => $block,
        'text-zinc-900 dark:text-white' => $active,
        'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white' => ! $active,
    ]) }}
>
    {{ $slot }}
</a>
