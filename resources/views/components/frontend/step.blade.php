@props([
    'number',
    'title',
])

<div {{ $attributes->class('relative rounded-2xl border border-slate-200 bg-white p-6 pl-20 shadow-sm shadow-slate-900/[0.03]') }}>
    <span class="absolute left-6 top-6 flex size-10 items-center justify-center rounded-full bg-linear-to-br from-brand-600 to-mint-500 text-sm font-bold text-white shadow-md shadow-brand-600/25">
        {{ $number }}
    </span>

    <h3 class="font-semibold text-slate-900">{{ $title }}</h3>
    <p class="mt-1.5 text-sm leading-relaxed text-slate-600">{{ $slot }}</p>
</div>
