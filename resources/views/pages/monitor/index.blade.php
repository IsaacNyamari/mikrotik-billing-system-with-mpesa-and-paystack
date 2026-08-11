<?php

use App\Models\Router;
use App\Services\RouterOs\RouterMonitor;
use App\Support\Authorization\Permissions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A live look at one MikroTik.
 *
 * Everything is read on a poll rather than stored: this is a window onto the device, not a
 * history, and keeping samples only for the life of the page avoids writing a row a second
 * for something nobody looks at afterwards.
 */
new #[Title('MikroTik Monitor')] class extends Component {
    /** How many samples the chart keeps. At 5s a poll this is about five minutes. */
    protected const HISTORY = 60;

    #[Url(except: '')]
    public string $routerSlug = '';

    public string $interface = '';

    public bool $paused = false;

    /**
     * Rolling traffic history, oldest first: [['t' => epoch, 'rx' => bps, 'tx' => bps], …].
     *
     * @var array<int, array{t: int, rx: float, tx: float}>
     */
    public array $history = [];

    /**
     * The previous counter reading, so a rate can be worked out from the next one.
     *
     * @var array{bytes_rx: int, bytes_tx: int, at: float}|null
     */
    public ?array $previous = null;

    /** @var array<string, mixed>|null */
    public ?array $snapshot = null;

    public function mount(): void
    {
        Gate::authorize(Permissions::ROUTERS_VIEW);

        $this->routerSlug = $this->routerSlug !== '' && $this->routerExists($this->routerSlug)
            ? $this->routerSlug
            : (string) Router::query()->orderBy('name')->value('slug');

        $this->refresh();
    }

    protected function routerExists(string $slug): bool
    {
        return Router::query()->where('slug', $slug)->exists();
    }

    #[Computed(persist: false)]
    public function router(): ?Router
    {
        return $this->routerSlug === ''
            ? null
            : Router::query()->where('slug', $this->routerSlug)->first();
    }

    /**
     * @return Collection<int, Router>
     */
    #[Computed]
    public function routers(): Collection
    {
        return Router::query()->orderBy('name')->get();
    }

    public function updatedRouterSlug(): void
    {
        // A different device means the old rates describe nothing.
        $this->reset(['history', 'previous', 'interface', 'snapshot']);
        $this->refresh();
    }

    public function updatedInterface(): void
    {
        $this->reset(['history', 'previous']);
    }

    /**
     * Called by wire:poll, and once on mount.
     */
    public function refresh(RouterMonitor $monitor = null): void
    {
        if ($this->paused) {
            return;
        }

        $router = $this->router;

        if ($router === null) {
            return;
        }

        $monitor ??= app(RouterMonitor::class);
        $snapshot = $monitor->snapshot($router);

        $this->snapshot = $snapshot;

        if (! $snapshot['online']) {
            return;
        }

        $this->trackTraffic($snapshot);
    }

    /**
     * Turn two counter readings into a point on the chart.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function trackTraffic(array $snapshot): void
    {
        $interfaces = collect($snapshot['interfaces']);

        if ($this->interface === '') {
            // Default to whatever is actually carrying traffic, which is the one an operator
            // would have picked anyway. A device whose interfaces cannot be read leaves this
            // empty rather than throwing — the rest of the monitor is still worth showing.
            $busiest = $interfaces
                ->sortByDesc(fn (array $row): int => (int) ($row['rx-byte'] ?? 0))
                ->first();

            $this->interface = (string) ($busiest['name'] ?? '');
        }

        $current = $this->interface === ''
            ? null
            : $interfaces->firstWhere('name', $this->interface);

        if ($current === null) {
            return;
        }

        $rxBytes = (int) ($current['rx-byte'] ?? 0);
        $txBytes = (int) ($current['tx-byte'] ?? 0);
        $now = (float) $snapshot['sampledAt'];

        if ($this->previous !== null) {
            $seconds = $now - $this->previous['at'];

            $this->history[] = [
                't' => (int) $now,
                'rx' => RouterMonitor::rate($this->previous['bytes_rx'], $rxBytes, $seconds),
                'tx' => RouterMonitor::rate($this->previous['bytes_tx'], $txBytes, $seconds),
            ];

            $this->history = array_slice($this->history, -self::HISTORY);
        }

        $this->previous = ['bytes_rx' => $rxBytes, 'bytes_tx' => $txBytes, 'at' => $now];
    }

    public function togglePause(): void
    {
        $this->paused = ! $this->paused;
    }

    /**
     * The chart's vertical scale, shared by both series so they stay comparable.
     */
    #[Computed]
    public function peak(): float
    {
        $peak = collect($this->history)->flatMap(fn (array $point): array => [$point['rx'], $point['tx']])->max();

        // A floor stops an idle link drawing noise as if it were a spike.
        return max((float) $peak, 64_000);
    }

    /**
     * Points for one series as an SVG polyline, oldest at the left.
     */
    public function polyline(string $key): string
    {
        $points = $this->history;
        $count = count($points);

        if ($count < 2) {
            return '';
        }

        $peak = $this->peak;

        return collect($points)
            ->map(function (array $point, int $index) use ($count, $peak, $key): string {
                $x = round($index / ($count - 1) * 100, 2);
                $y = round(100 - ($point[$key] / $peak * 100), 2);

                return "{$x},{$y}";
            })
            ->implode(' ');
    }

    #[Computed]
    public function latest(): array
    {
        return $this->history === [] ? ['rx' => 0.0, 'tx' => 0.0] : end($this->history);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6" @if (! $paused) wire:poll.5s="refresh" @endif>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('MikroTik Monitor') }}</flux:heading>
                {{-- Always name the device. With one router the selector is hidden, and an
                     operator should never have to guess which box these numbers describe. --}}
                <flux:subheading>
                    @if ($this->router)
                        {{ $this->router->name }} &middot; <span class="font-mono">{{ $this->router->host }}</span> &middot;
                    @endif
                    {{ __('live, and not stored') }}
                </flux:subheading>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                @if ($this->routers->count() > 1)
                    <flux:select wire:model.live="routerSlug" class="max-w-56">
                        @foreach ($this->routers as $router)
                            <flux:select.option value="{{ $router->slug }}">{{ $router->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                <flux:button
                    size="sm"
                    :icon="$paused ? 'play' : 'pause'"
                    wire:click="togglePause"
                    :variant="$paused ? 'primary' : 'ghost'"
                >
                    {{ $paused ? __('Resume') : __('Pause') }}
                </flux:button>
            </div>
        </div>

        @if ($this->router === null)
            <flux:card class="py-12 text-center">
                <flux:heading size="lg">{{ __('No routers yet') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Add a MikroTik and its live activity shows up here.') }}</flux:text>
                @can('create', App\Models\Router::class)
                    <flux:button class="mt-4" variant="primary" :href="route('routers.create')" wire:navigate>
                        {{ __('Add router') }}
                    </flux:button>
                @endcan
            </flux:card>
        @elseif ($snapshot && ! $snapshot['online'])
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Cannot reach :name', ['name' => $this->router->name]) }}</flux:callout.heading>
                <flux:callout.text>{{ $snapshot['error'] ?? __('The router did not answer.') }}</flux:callout.text>
                <flux:callout.text>{{ __('Monitoring keeps trying — this page updates by itself once it comes back.') }}</flux:callout.text>
            </flux:callout>
        @elseif ($snapshot)
            @php($resource = $snapshot['resource'])
            @php($totalMemory = (int) ($resource['total-memory'] ?? 0))
            @php($freeMemory = (int) ($resource['free-memory'] ?? 0))
            @php($memoryUsed = $totalMemory > 0 ? (int) round(($totalMemory - $freeMemory) / $totalMemory * 100) : null)

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:card>
                    <flux:text size="sm" class="opacity-60">{{ __('Download') }}</flux:text>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ App\Services\RouterOs\RouterMonitor::formatBits($this->latest['rx']) }}
                    </p>
                </flux:card>

                <flux:card>
                    <flux:text size="sm" class="opacity-60">{{ __('Upload') }}</flux:text>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ App\Services\RouterOs\RouterMonitor::formatBits($this->latest['tx']) }}
                    </p>
                </flux:card>

                <flux:card>
                    <flux:text size="sm" class="opacity-60">{{ __('CPU') }}</flux:text>
                    <p class="mt-1 text-2xl font-bold tabular-nums">{{ $resource['cpu-load'] ?? '—' }}%</p>
                    <flux:text size="sm" class="opacity-60">{{ $resource['version'] ?? '' }}</flux:text>
                </flux:card>

                <flux:card>
                    <flux:text size="sm" class="opacity-60">{{ __('Memory') }}</flux:text>
                    <p class="mt-1 text-2xl font-bold tabular-nums">{{ $memoryUsed !== null ? $memoryUsed.'%' : '—' }}</p>
                    <flux:text size="sm" class="opacity-60">
                        {{ App\Services\RouterOs\RouterMonitor::formatBytes($freeMemory) }} {{ __('free') }}
                    </flux:text>
                </flux:card>
            </div>

            <flux:card class="flex flex-col gap-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <flux:heading size="lg">{{ __('Traffic') }}</flux:heading>
                        <flux:subheading>
                            {{ __('Peak on this view: :peak', ['peak' => App\Services\RouterOs\RouterMonitor::formatBits($this->peak)]) }}
                        </flux:subheading>
                    </div>

                    <div class="flex items-center gap-3">
                        <flux:select wire:model.live="interface" class="max-w-44">
                            @foreach ($snapshot['interfaces'] as $row)
                                <flux:select.option value="{{ $row['name'] }}">{{ $row['name'] }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        {{-- Both series are named here and again on the chart, so the two are
                             never told apart by colour alone. --}}
                        <div class="flex items-center gap-3 text-sm">
                            <span class="flex items-center gap-1.5">
                                <span class="size-2.5 rounded-full" style="background:#2563eb"></span>
                                {{ __('Down') }}
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span class="size-2.5 rounded-full" style="background:#d97706"></span>
                                {{ __('Up') }}
                            </span>
                        </div>
                    </div>
                </div>

                @if (count($history) < 2)
                    <div class="flex h-56 items-center justify-center">
                        <flux:text>{{ __('Collecting the first readings…') }}</flux:text>
                    </div>
                @else
                    <div class="relative h-56 w-full overflow-hidden rounded-lg bg-zinc-50 dark:bg-zinc-900">
                        <svg viewBox="0 0 100 100" preserveAspectRatio="none" class="h-full w-full"
                             role="img" aria-label="{{ __('Traffic over the last few minutes') }}">
                            {{-- Recessive gridlines: present enough to read a level against,
                                 quiet enough not to compete with the data. --}}
                            @foreach ([25, 50, 75] as $line)
                                <line x1="0" y1="{{ $line }}" x2="100" y2="{{ $line }}"
                                      stroke="currentColor" stroke-width="0.2" class="text-zinc-300 dark:text-zinc-700" />
                            @endforeach

                            <polyline points="{{ $this->polyline('rx') }}" fill="none" stroke="#2563eb"
                                      stroke-width="0.8" vector-effect="non-scaling-stroke"
                                      stroke-linejoin="round" stroke-linecap="round" />
                            <polyline points="{{ $this->polyline('tx') }}" fill="none" stroke="#d97706"
                                      stroke-width="0.8" vector-effect="non-scaling-stroke"
                                      stroke-linejoin="round" stroke-linecap="round" />
                        </svg>

                        <span class="absolute left-2 top-1 text-xs text-zinc-400">
                            {{ App\Services\RouterOs\RouterMonitor::formatBits($this->peak) }}
                        </span>
                        <span class="absolute bottom-1 left-2 text-xs text-zinc-400">0</span>
                    </div>
                @endif
            </flux:card>

            <div class="grid gap-4 lg:grid-cols-3">
                <flux:card>
                    <flux:text size="sm" class="opacity-60">{{ __('HotSpot sessions') }}</flux:text>
                    <p class="mt-1 text-3xl font-bold tabular-nums">{{ count($snapshot['hotspotActive']) }}</p>
                    <flux:text size="sm" class="opacity-60">
                        {{ __(':count device(s) seen', ['count' => $snapshot['hotspotHosts']]) }}
                    </flux:text>
                </flux:card>

                <flux:card>
                    <flux:text size="sm" class="opacity-60">{{ __('PPPoE sessions') }}</flux:text>
                    <p class="mt-1 text-3xl font-bold tabular-nums">{{ count($snapshot['pppActive']) }}</p>
                </flux:card>

                <flux:card>
                    <flux:text size="sm" class="opacity-60">{{ __('Wireless clients') }}</flux:text>
                    <p class="mt-1 text-3xl font-bold tabular-nums">{{ count($snapshot['wireless']) }}</p>
                    <flux:text size="sm" class="opacity-60">
                        {{ __(':count DHCP lease(s)', ['count' => $snapshot['leases']]) }}
                    </flux:text>
                </flux:card>
            </div>

            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Active HotSpot users') }}</flux:heading>

                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                        <flux:table.column>{{ __('Address') }}</flux:table.column>
                        <flux:table.column>{{ __('Uptime') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('In / Out') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @forelse ($snapshot['hotspotActive'] as $session)
                            <flux:table.row wire:key="hs-{{ $session['.id'] ?? $loop->index }}">
                                <flux:table.cell variant="strong" class="font-mono text-sm">
                                    {{ $session['user'] ?? '—' }}
                                </flux:table.cell>
                                <flux:table.cell class="font-mono text-xs">
                                    {{ $session['address'] ?? '—' }}
                                    <span class="opacity-50">{{ $session['mac-address'] ?? '' }}</span>
                                </flux:table.cell>
                                <flux:table.cell>{{ $session['uptime'] ?? '—' }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums text-sm">
                                    {{ App\Services\RouterOs\RouterMonitor::formatBytes((int) ($session['bytes-in'] ?? 0)) }}
                                    /
                                    {{ App\Services\RouterOs\RouterMonitor::formatBytes((int) ($session['bytes-out'] ?? 0)) }}
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="4" class="text-center">
                                    <flux:text>{{ __('Nobody is logged in to the HotSpot right now.') }}</flux:text>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>

            @if ($snapshot['pppActive'] !== [])
                <flux:card class="flex flex-col gap-4">
                    <flux:heading size="lg">{{ __('Active PPPoE sessions') }}</flux:heading>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('User') }}</flux:table.column>
                            <flux:table.column>{{ __('Address') }}</flux:table.column>
                            <flux:table.column>{{ __('Uptime') }}</flux:table.column>
                            <flux:table.column>{{ __('Service') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($snapshot['pppActive'] as $session)
                                <flux:table.row wire:key="ppp-{{ $session['.id'] ?? $loop->index }}">
                                    <flux:table.cell variant="strong" class="font-mono text-sm">{{ $session['name'] ?? '—' }}</flux:table.cell>
                                    <flux:table.cell class="font-mono text-xs">{{ $session['address'] ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>{{ $session['uptime'] ?? '—' }}</flux:table.cell>
                                    <flux:table.cell>{{ $session['service'] ?? '—' }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            @endif
        @endif
    </div>
</section>
