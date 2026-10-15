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
    'relative flex flex-col overflow-hidden rounded-2xl border p-7',
    'border-brand-700 bg-linear-to-br from-brand-700 to-brand-900 text-white shadow-xl shadow-brand-900/25 sm:-my-3 sm:py-10' => $featured,
    'border-slate-200 bg-white shadow-sm shadow-slate-900/[0.03]' => ! $featured,
])>
    @if ($featured)
        <span aria-hidden="true" class="pointer-events-none absolute -right-16 -top-16 size-48 rounded-full bg-mint-400/25 blur-2xl"></span>
    @endif

    @if ($badge)
        <span @class([
            'relative mb-4 inline-flex w-fit rounded-full px-3 py-1 text-xs font-bold',
            'bg-mint-400 text-brand-950' => $featured,
            'bg-brand-50 text-brand-700' => ! $featured,
        ])>{{ $badge }}</span>
    @endif

    <h3 @class(['relative font-semibold', 'text-slate-900' => ! $featured, 'text-mint-100' => $featured])>{{ $title }}</h3>

    <p class="relative mt-4 flex items-baseline gap-1.5">
        <span @class(['text-sm font-semibold', 'text-slate-500' => ! $featured, 'text-brand-100' => $featured])>{{ $currency }}</span>
        <span @class(['text-5xl font-bold tracking-tight', 'text-slate-900' => ! $featured])>{{ $amount }}</span>
        @if ($unit)
            <span @class(['text-sm font-medium', 'text-slate-500' => ! $featured, 'text-brand-100' => $featured])>{{ $unit }}</span>
        @endif
    </p>

    @if ($note)
        <p @class(['relative mt-2 text-sm font-medium', 'text-brand-600' => ! $featured, 'text-mint-300' => $featured])>{{ $note }}</p>
    @endif

    <div @class(['relative mt-6 flex-1 border-t pt-5 text-sm leading-relaxed', 'border-slate-100 text-slate-600' => ! $featured, 'border-white/15 text-brand-100' => $featured])>
        {{ $slot }}
    </div>
</div>
