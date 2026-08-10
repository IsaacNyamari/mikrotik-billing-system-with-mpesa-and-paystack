<?php

use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\RouterHealthProbe;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Router')] class extends Component {
    public Router $router;

    public function mount(Router $router): void
    {
        Gate::authorize('view', $router);

        $this->router = $router;
    }

    public function probe(RouterHealthProbe $probe): void
    {
        Gate::authorize('command', $this->router);

        $probe->probe($this->router);

        unset($this->services);

        $this->router->status->isReachable()
            ? Flux::toast(variant: 'success', text: __('Connected.'))
            : Flux::toast(variant: 'danger', text: $this->router->last_error ?? __('Could not reach the router.'));
    }

    /**
     * What the router currently has configured for the services we care about.
     *
     * Read live rather than stored, because the operator may well have changed something on
     * the device since the last probe.
     *
     * @return array<string, array{count: int|null, error: string|null}>
     */
    #[Computed]
    public function services(): array
    {
        if (! $this->router->status->isReachable()) {
            return [];
        }

        $menus = [
            'HotSpot servers' => '/ip/hotspot',
            'HotSpot profiles' => '/ip/hotspot/user/profile',
            'HotSpot users' => '/ip/hotspot/user',
            'Active HotSpot sessions' => '/ip/hotspot/active',
            'PPPoE servers' => '/interface/pppoe-server/server',
            'PPP profiles' => '/ppp/profile',
            'PPP secrets' => '/ppp/secret',
            'Active PPP sessions' => '/ppp/active',
        ];

        try {
            $connection = app(RouterOsConnector::class)->connect($this->router);
        } catch (\Throwable $e) {
            return ['Connection' => ['count' => null, 'error' => $e->getMessage()]];
        }

        $results = [];

        foreach ($menus as $label => $path) {
            try {
                $results[$label] = ['count' => count($connection->get($path)), 'error' => null];
            } catch (\Throwable $e) {
                $results[$label] = ['count' => null, 'error' => $e->getMessage()];
            }
        }

        $connection->disconnect();

        return $results;
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <flux:heading size="xl">{{ $router->name }}</flux:heading>
                    <flux:badge size="sm" :color="$router->status->color()">{{ $router->status->label() }}</flux:badge>
                </div>
                <flux:subheading class="font-mono">{{ $router->host }}:{{ $router->api_port }}</flux:subheading>
            </div>

            <div class="flex items-center gap-2">
                <flux:button variant="ghost" icon="arrow-left" :href="route('routers.index')" wire:navigate>
                    {{ __('All routers') }}
                </flux:button>

                @can('command', $router)
                    <flux:button icon="signal" wire:click="probe" wire:loading.attr="disabled" wire:target="probe">
                        <span wire:loading.remove wire:target="probe">{{ __('Test connection') }}</span>
                        <span wire:loading wire:target="probe">{{ __('Connecting…') }}</span>
                    </flux:button>
                @endcan

                @can('update', $router)
                    <flux:button icon="pencil-square" :href="route('routers.edit', $router)" wire:navigate>
                        {{ __('Edit') }}
                    </flux:button>
                @endcan
            </div>
        </div>

        @if ($router->last_error && ! $router->status->isReachable())
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Cannot reach this router') }}</flux:callout.heading>
                <flux:callout.text>{{ $router->last_error }}</flux:callout.text>
                <flux:callout.text>
                    {{ __('Check that the API service is enabled under /ip/service on the device, that the port is reachable from this server, and that the API user credentials are correct.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <div class="grid gap-4 md:grid-cols-2">
            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Device') }}</flux:heading>

                <div class="grid grid-cols-2 gap-4">
                    @foreach ([
                        __('Identity') => $router->identity,
                        __('Model') => $router->model,
                        __('Serial') => $router->serial_number,
                        __('RouterOS') => $router->os_version,
                        __('Uptime') => $router->uptimeForHumans(),
                        __('CPU load') => $router->cpu_load !== null ? $router->cpu_load.'%' : null,
                        __('Memory used') => $router->memoryUsedPercent() !== null ? $router->memoryUsedPercent().'%' : null,
                        __('Last seen') => $router->last_seen_at?->diffForHumans(),
                    ] as $label => $value)
                        <div>
                            <flux:text size="sm" class="opacity-60">{{ $label }}</flux:text>
                            <flux:text>{{ $value ?? '—' }}</flux:text>
                        </div>
                    @endforeach
                </div>

                @if ($router->location)
                    <flux:separator />
                    <div>
                        <flux:text size="sm" class="opacity-60">{{ __('Location') }}</flux:text>
                        <flux:text>{{ $router->location }}</flux:text>
                    </div>
                @endif
            </flux:card>

            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Services on the device') }}</flux:heading>

                @if (! $router->status->isReachable())
                    <flux:text>{{ __('Connect to the router to read what it has configured.') }}</flux:text>
                @else
                    <flux:table>
                        <flux:table.rows>
                            @foreach ($this->services as $label => $result)
                                <flux:table.row :key="$label">
                                    <flux:table.cell>{{ $label }}</flux:table.cell>
                                    <flux:table.cell class="text-end">
                                        @if ($result['error'] !== null)
                                            <flux:badge size="sm" color="red">{{ __('no access') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" :color="$result['count'] > 0 ? 'green' : 'zinc'">
                                                {{ $result['count'] }}
                                            </flux:badge>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>

                    <flux:text size="sm" class="opacity-70">
                        {{ __('HotSpot or PPPoE servers showing zero means that service is not configured on the router yet — set it up there before provisioning users.') }}
                    </flux:text>
                @endif
            </flux:card>
        </div>

        @if ($router->notes)
            <flux:card>
                <flux:heading size="lg">{{ __('Notes') }}</flux:heading>
                <flux:text class="mt-2 whitespace-pre-line">{{ $router->notes }}</flux:text>
            </flux:card>
        @endif
    </div>
</section>
