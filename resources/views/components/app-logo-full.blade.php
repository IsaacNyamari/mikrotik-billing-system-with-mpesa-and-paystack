{{-- The full HumSols wordmark. Its navy lettering disappears on a dark page, so in dark mode it
     sits on a white plate rather than being recoloured. --}}
<span {{ $attributes->merge(['class' => 'inline-flex items-center dark:rounded-lg dark:bg-white dark:px-2.5 dark:py-1.5']) }}>
    <img src="{{ asset('images/humsols-logo.png') }}" alt="{{ config('app.name') }}" class="h-full w-auto object-contain">
</span>
