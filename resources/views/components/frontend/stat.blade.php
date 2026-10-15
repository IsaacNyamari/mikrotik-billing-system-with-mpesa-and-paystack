@props([
    'value',
    'label',
])

<div {{ $attributes->class('relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm shadow-slate-900/[0.03]') }}>
    <span aria-hidden="true" class="absolute inset-x-0 top-0 h-1 bg-linear-to-r from-brand-600 to-mint-400"></span>
    <p class="text-3xl font-bold tracking-tight text-brand-700">{{ $value }}</p>
    <p class="mt-1 text-sm text-slate-600">{{ $label }}</p>
</div>
