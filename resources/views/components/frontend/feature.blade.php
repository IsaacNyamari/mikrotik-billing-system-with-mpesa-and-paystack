@props([
    'title',
    'icon' => 'check',
])

{{-- A capability, stated plainly. The icon set is inline rather than a package: three shapes
     do not justify a dependency. --}}
<div {{ $attributes->class('group relative rounded-2xl border border-slate-200 bg-white p-6 shadow-sm shadow-slate-900/[0.03] transition duration-200 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-lg hover:shadow-brand-900/[0.07]') }}>
    <span class="flex size-11 items-center justify-center rounded-xl bg-linear-to-br from-brand-500 to-brand-700 text-white shadow-md shadow-brand-600/25 ring-4 ring-brand-50 transition group-hover:to-mint-500">
        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
            @switch($icon)
                @case('router')
                    <rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 16.5h.01M11 16.5h.01M12 10V6m0 0-2.5 2M12 6l2.5 2" stroke-linecap="round"/>
                    @break
                @case('phone')
                    <rect x="7" y="2.5" width="10" height="19" rx="2"/><path d="M11 18.5h2" stroke-linecap="round"/>
                    @break
                @case('cash')
                    <rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>
                    @break
                @case('users')
                    <circle cx="9" cy="8" r="3.2"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0M16 5.5a3 3 0 0 1 0 5M18 19a5 5 0 0 0-2-4" stroke-linecap="round"/>
                    @break
                @case('shield')
                    <path d="M12 3l7 3v5.5c0 4-3 7.5-7 9-4-1.5-7-5-7-9V6l7-3z" stroke-linejoin="round"/><path d="m9 12 2 2 4-4" stroke-linecap="round" stroke-linejoin="round"/>
                    @break
                @case('chart')
                    <path d="M4 19V5M4 19h16M8 16V11M12.5 16V7M17 16v-6" stroke-linecap="round"/>
                    @break
                @case('clock')
                    <circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2" stroke-linecap="round" stroke-linejoin="round"/>
                    @break
                @default
                    <circle cx="12" cy="12" r="8.5"/><path d="m8.5 12.5 2.5 2.5 4.5-5" stroke-linecap="round" stroke-linejoin="round"/>
            @endswitch
        </svg>
    </span>

    <h3 class="mt-5 font-semibold text-slate-900">{{ $title }}</h3>
    <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ $slot }}</p>
</div>
