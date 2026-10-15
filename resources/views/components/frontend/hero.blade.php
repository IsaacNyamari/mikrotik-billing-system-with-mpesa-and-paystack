@props([
    'eyebrow' => null,
    'heading',
    'lead' => null,
    'compact' => false,
])

{{-- The brand's navy, with a teal glow for the logo's second figure. Drawn in CSS rather than a
     photograph: it costs nothing to load and cannot be the reason a page looks broken on a slow
     connection. --}}
<section @class([
    'relative isolate overflow-hidden bg-brand-950 px-4 text-white sm:px-6',
    'pb-16 pt-14 sm:pb-20 sm:pt-20' => $compact,
    'pb-16 pt-16 sm:pb-24 sm:pt-24' => ! $compact,
])>
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="absolute inset-0 bg-linear-to-br from-brand-900 via-brand-800 to-brand-950"></div>
        <div class="absolute -right-32 -top-40 size-[34rem] rounded-full bg-mint-400/25 blur-3xl"></div>
        <div class="absolute -bottom-48 -left-24 size-[30rem] rounded-full bg-brand-500/40 blur-3xl"></div>
        <div class="absolute inset-0 bg-[linear-gradient(to_right,rgba(255,255,255,.06)_1px,transparent_1px),linear-gradient(to_bottom,rgba(255,255,255,.06)_1px,transparent_1px)] bg-[size:48px_48px] [mask-image:radial-gradient(70%_60%_at_50%_30%,black,transparent)]"></div>
    </div>

    <div class="mx-auto max-w-3xl text-center">
        @if ($eyebrow)
            <p class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3.5 py-1.5 text-xs font-semibold text-mint-100 backdrop-blur">
                <span class="size-1.5 rounded-full bg-mint-400 shadow-[0_0_0_3px_rgba(0,218,206,.25)]"></span>
                {{ $eyebrow }}
            </p>
        @endif

        <h1 @class([
            'mt-6 font-bold tracking-tight text-balance text-white',
            'text-4xl sm:text-5xl' => $compact,
            'text-4xl sm:text-6xl' => ! $compact,
        ])>
            {{ $heading }}
        </h1>

        @if ($lead)
            <p class="mx-auto mt-5 max-w-2xl text-base text-pretty text-brand-100/90 sm:text-lg">{{ $lead }}</p>
        @endif

        @isset($actions)
            <div class="mt-9 flex flex-wrap items-center justify-center gap-3">
                {{ $actions }}
            </div>
        @endisset
    </div>

    @isset($below)
        <div class="relative mx-auto mt-14 max-w-6xl sm:mt-16">
            {{ $below }}
        </div>
    @endisset
</section>
