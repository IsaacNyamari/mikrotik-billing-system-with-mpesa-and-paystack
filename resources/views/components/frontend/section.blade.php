@props([
    'eyebrow' => null,
    'heading' => null,
    'lead' => null,
    'align' => 'center',
    'tint' => false,
])

{{-- The wrapper every band of the site uses, so vertical rhythm and heading sizes are decided
     once rather than per page. --}}
<section {{ $attributes->class([
    'px-4 py-16 sm:px-6 sm:py-24',
    'border-y border-slate-200/70 bg-slate-50' => $tint,
]) }}>
    <div class="mx-auto max-w-6xl">
        @if ($eyebrow || $heading || $lead)
            <div @class([
                'max-w-2xl',
                'mx-auto text-center' => $align === 'center',
            ])>
                @if ($eyebrow)
                    <p class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-[0.18em] text-brand-600">
                        <span class="h-0.5 w-6 rounded-full bg-mint-400"></span>
                        {{ $eyebrow }}
                    </p>
                @endif

                @if ($heading)
                    <h2 class="mt-3 text-3xl font-bold tracking-tight text-balance text-slate-900 sm:text-4xl">
                        {{ $heading }}
                    </h2>
                @endif

                @if ($lead)
                    <p class="mt-4 text-lg text-pretty text-slate-600">{{ $lead }}</p>
                @endif
            </div>
        @endif

        <div @class(['mt-12' => $eyebrow || $heading || $lead])>
            {{ $slot }}
        </div>
    </div>
</section>
