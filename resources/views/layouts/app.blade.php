<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        @if (auth()->user()?->isSuperAdmin() && App\Support\Tenancy\Facades\Tenancy::has())
            {{-- Never let an administrator forget whose data this is. --}}
            <div class="-mx-6 -mt-6 mb-6 flex flex-wrap items-center justify-center gap-3 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 lg:-mx-8 lg:-mt-8 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                <flux:icon.eye variant="micro" class="size-4" />
                <span>{!! __('You are viewing <strong>:isp</strong> as a platform administrator.', ['isp' => e(App\Support\Tenancy\Facades\Tenancy::tenant()?->name)]) !!}</span>
                <form method="POST" action="{{ route('platform.exit') }}">
                    @csrf
                    <button type="submit" class="rounded-md bg-amber-900 px-2.5 py-1 text-xs font-semibold text-white hover:bg-amber-800 dark:bg-amber-200 dark:text-amber-950">{{ __('Exit to platform') }}</button>
                </form>
            </div>
        @endif

        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
