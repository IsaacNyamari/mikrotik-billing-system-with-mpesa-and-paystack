@props([
    'title',
    'amount',
    'unit' => null,
    'currency' => 'KSh',
    'note' => null,
    'featured' => false,
    'badge' => null,
])

{{-- One line of the price list. The money is the loudest thing on it: an ISP deciding whether
     to sign up is doing arithmetic, not reading prose. --}}
<div @class([
    'relative flex flex-col rounded-2xl border p-6',
    'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900' => $featured,
    'border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900' => ! $featured,
])>
    @if ($badge)
        <span @class([
            'absolute -top-3 left-6 rounded-full px-3 py-1 text-xs font-semibold',
            'bg-white text-zinc-900 dark:bg-zinc-900 dark:text-white' => $featured,
            'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => ! $featured,
        ])>{{ $badge }}</span>
    @endif

    <h3 @class(['font-semibold', 'text-zinc-900 dark:text-white' => ! $featured])>{{ $title }}</h3>

    <p class="mt-4 flex items-baseline gap-1.5">
        <span @class(['text-sm font-medium', 'text-zinc-500 dark:text-zinc-400' => ! $featured, 'opacity-70' => $featured])>{{ $currency }}</span>
        <span class="text-4xl font-semibold tracking-tight">{{ $amount }}</span>
        @if ($unit)
            <span @class(['text-sm', 'text-zinc-500 dark:text-zinc-400' => ! $featured, 'opacity-70' => $featured])>{{ $unit }}</span>
        @endif
    </p>

    @if ($note)
        <p @class(['mt-2 text-sm', 'text-zinc-600 dark:text-zinc-400' => ! $featured, 'opacity-80' => $featured])>{{ $note }}</p>
    @endif

    <div @class(['mt-6 flex-1 text-sm', 'text-zinc-600 dark:text-zinc-400' => ! $featured, 'opacity-90' => $featured])>
        {{ $slot }}
    </div>
</div>
