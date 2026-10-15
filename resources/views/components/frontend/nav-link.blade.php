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
        'rounded-lg px-3.5 py-2 text-sm font-semibold transition',
        'block' => $block,
        'bg-brand-50 text-brand-700' => $active,
        'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => ! $active,
    ]) }}
>
    {{ $slot }}
</a>
