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
    'px-6 py-16 sm:py-24',
    'bg-zinc-50 dark:bg-zinc-900/40' => $tint,
]) }}>
    <div class="mx-auto max-w-6xl">
        @if ($eyebrow || $heading || $lead)
            <div @class([
                'max-w-2xl',
                'mx-auto text-center' => $align === 'center',
            ])>
                @if ($eyebrow)
                    <p class="text-sm font-semibold uppercase tracking-wider text-zinc-500">{{ $eyebrow }}</p>
                @endif

                @if ($heading)
                    <h2 class="mt-2 text-3xl font-semibold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
                        {{ $heading }}
                    </h2>
                @endif

                @if ($lead)
                    <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-400">{{ $lead }}</p>
                @endif
            </div>
        @endif

        <div @class(['mt-12' => $eyebrow || $heading || $lead])>
            {{ $slot }}
        </div>
    </div>
</section>
