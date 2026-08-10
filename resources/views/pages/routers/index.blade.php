<?php

use App\Models\Router;
use App\Services\RouterOs\RouterHealthProbe;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Routers')] class extends Component {
    public function mount(): void
    {
        Gate::authorize('viewAny', Router::class);
    }

    /**
     * @return Collection<int, Router>
     */
    #[Computed]
    public function routers(): Collection
    {
        return Router::query()->orderBy('name')->get();
    }

    public function probe(int $routerId, RouterHealthProbe $probe): void
    {
        $router = Router::query()->findOrFail($routerId);

        Gate::authorize('command', $router);

        $probe->probe($router);

        unset($this->routers);

        $router->status->isReachable()
            ? Flux::toast(variant: 'success', text: __('Connected to :name.', ['name' => $router->name]))
            : Flux::toast(variant: 'danger', text: $router->last_error ?? __('Could not reach the router.'));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Routers') }}</flux:heading>
                <flux:subheading>{{ __('The MikroTik devices this ISP provisions HotSpot and PPPoE users onto.') }}</flux:subheading>
            </div>

            @can('create', App\Models\Router::class)
                <flux:button variant="primary" icon="plus" :href="route('routers.create')" wire:navigate>
                    {{ __('Add router') }}
                </flux:button>
            @endcan
        </div>

        @if ($this->routers->isEmpty())
            <flux:card class="flex flex-col items-center gap-3 py-12 text-center">
                <flux:icon.server class="size-10 opacity-40" />
                <flux:heading size="lg">{{ __('No routers yet') }}</flux:heading>
                <flux:text class="max-w-md">
                    {{ __('Add your MikroTik and the platform will connect over the RouterOS API to read its identity and provision users onto it.') }}
                </flux:text>
                @can('create', App\Models\Router::class)
                    <flux:button variant="primary" :href="route('routers.create')" wire:navigate>
                        {{ __('Add your first router') }}
                    </flux:button>
                @endcan
            </flux:card>
        @else
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->routers as $router)
                    <flux:card class="flex flex-col gap-4" wire:key="router-{{ $router->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <flux:link :href="route('routers.show', $router)" wire:navigate variant="ghost">
                                    <flux:heading size="lg">{{ $router->name }}</flux:heading>
                                </flux:link>
                                <flux:text size="sm" class="font-mono opacity-70">
                                    {{ $router->host }}:{{ $router->api_port }}
                                </flux:text>
                            </div>

                            <flux:badge size="sm" :color="$router->status->color()">
                                {{ $router->status->label() }}
                            </flux:badge>
                        </div>

                        @if ($router->status->isReachable())
                            <div class="grid grid-cols-3 gap-2 text-sm">
                                <div>
                                    <flux:text size="sm" class="opacity-60">{{ __('RouterOS') }}</flux:text>
                                    <flux:text size="sm">{{ $router->os_version ?? '—' }}</flux:text>
                                </div>
                                <div>
                                    <flux:text size="sm" class="opacity-60">{{ __('CPU') }}</flux:text>
                                    <flux:text size="sm">{{ $router->cpu_load !== null ? $router->cpu_load.'%' : '—' }}</flux:text>
                                </div>
                                <div>
                                    <flux:text size="sm" class="opacity-60">{{ __('Uptime') }}</flux:text>
                                    <flux:text size="sm">{{ $router->uptimeForHumans() ?? '—' }}</flux:text>
                                </div>
                            </div>
                        @elseif ($router->last_error)
                            <flux:text size="sm" class="text-red-600 dark:text-red-400">
                                {{ Str::limit($router->last_error, 120) }}
                            </flux:text>
                        @endif

                        <div class="flex items-center gap-2">
                            @can('command', $router)
                                <flux:button
                                    size="sm"
                                    icon="signal"
                                    wire:click="probe({{ $router->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="probe({{ $router->id }})"
                                >
                                    {{ __('Test connection') }}
                                </flux:button>
                            @endcan

                            <flux:button size="sm" variant="ghost" :href="route('routers.show', $router)" wire:navigate>
                                {{ __('Details') }}
                            </flux:button>
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    </div>
</section>
