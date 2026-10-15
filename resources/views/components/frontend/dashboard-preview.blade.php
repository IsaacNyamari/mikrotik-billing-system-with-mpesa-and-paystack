{{-- A drawing of the operations overview, in HTML rather than a screenshot: it stays sharp on
     every screen, weighs nothing, and cannot go stale against a real screen that has moved on.
     The figures are illustrative, so the whole thing is hidden from screen readers and
     summarised once instead. --}}
@php
    $bars = [38, 52, 44, 61, 57, 72, 66, 80, 74, 88, 69, 93, 85, 97];
@endphp

<div {{ $attributes->class('relative') }}>
    <p class="sr-only">{{ __('A preview of the dashboard: revenue, active packages, routers online and daily collections.') }}</p>

    <div aria-hidden="true" class="absolute -inset-x-6 -bottom-6 top-10 -z-10 rounded-[2rem] bg-mint-400/20 blur-2xl"></div>

    <div aria-hidden="true" class="overflow-hidden rounded-2xl border border-white/15 bg-white text-left shadow-2xl shadow-brand-950/50 ring-1 ring-black/5">
        {{-- Window chrome --}}
        <div class="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-4 py-2.5">
            <span class="size-2.5 rounded-full bg-[#d03b3b]/70"></span>
            <span class="size-2.5 rounded-full bg-amber-400/80"></span>
            <span class="size-2.5 rounded-full bg-[#0ca30c]/60"></span>
            <span class="ml-3 hidden rounded-md bg-white px-3 py-0.5 text-[11px] text-slate-500 ring-1 ring-slate-200 sm:inline">isp.humsols.com/dashboard</span>
        </div>

        <div class="flex">
            {{-- Sidebar --}}
            <div class="hidden w-44 shrink-0 border-r border-slate-200 bg-white p-3 md:block">
                <div class="flex items-center gap-2 px-1">
                    <img src="{{ asset('images/humsols-mark.png') }}" alt="" class="size-6">
                    <span class="text-xs font-bold text-slate-900">{{ __('Your ISP') }}</span>
                </div>
                <div class="mt-4 space-y-1 text-[11px] font-medium">
                    <div class="rounded-md bg-brand-50 px-2 py-1.5 text-brand-700">{{ __('Overview') }}</div>
                    @foreach ([__('Customers'), __('Routers'), __('Packages'), __('Payments'), __('Earnings')] as $item)
                        <div class="rounded-md px-2 py-1.5 text-slate-500">{{ $item }}</div>
                    @endforeach
                </div>
            </div>

            {{-- Body --}}
            <div class="min-w-0 flex-1 bg-slate-100/70 p-3 sm:p-5">
                <div class="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
                    @foreach ([
                        [__('Revenue this month'), 'KSh 184,250', '+12.4%', 'bg-brand-600'],
                        [__('Packages running'), '312', '+28', 'bg-mint-500'],
                        [__('Routers online'), '6 / 6', __('All up'), 'bg-[#0ca30c]'],
                        [__('PPPoE subscribers'), '148', '+5', 'bg-brand-400'],
                    ] as [$label, $value, $change, $chip])
                        <div class="rounded-xl bg-white p-3 shadow-sm ring-1 ring-slate-200/70">
                            <div class="flex items-center gap-1.5">
                                <span class="size-1.5 rounded-full {{ $chip }}"></span>
                                <span class="truncate text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ $label }}</span>
                            </div>
                            <p class="mt-1.5 text-sm font-bold text-slate-900 sm:text-lg">{{ $value }}</p>
                            <p class="text-[10px] font-semibold text-[#0ca30c]">{{ $change }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="mt-3 grid gap-3 lg:grid-cols-3">
                    <div class="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 lg:col-span-2">
                        <div class="flex items-center justify-between">
                            <p class="text-xs font-bold text-slate-900">{{ __('Daily collections') }}</p>
                            <p class="text-[10px] text-slate-500">{{ __('Last 14 days') }}</p>
                        </div>
                        <div class="mt-4 flex h-28 items-end gap-[3px] border-b border-slate-200 sm:h-32">
                            @foreach ($bars as $bar)
                                <div class="flex-1 rounded-t-[4px] bg-[#2a78d6]" style="height: {{ $bar }}%"></div>
                            @endforeach
                        </div>
                    </div>

                    <div class="hidden rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200/70 lg:block">
                        <p class="text-xs font-bold text-slate-900">{{ __('Routers') }}</p>
                        <ul class="mt-3 space-y-2.5 text-[11px]">
                            @foreach ([['Town centre', '64'], ['Market', '41'], ['Estate block B', '27'], ['Stage', '19']] as [$site, $users])
                                <li class="flex items-center justify-between gap-2">
                                    <span class="flex min-w-0 items-center gap-1.5 text-slate-700">
                                        <span class="size-1.5 shrink-0 rounded-full bg-[#0ca30c]"></span>
                                        <span class="truncate">{{ $site }}</span>
                                    </span>
                                    <span class="font-semibold text-slate-900">{{ $users }} {{ __('online') }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
