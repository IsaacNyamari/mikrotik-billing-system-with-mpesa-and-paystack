<?php

use App\Jobs\SetUpPppoe;
use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\HotspotInstaller;
use App\Services\RouterOs\PppoeServerInstaller;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * PPPoE server setup: the router answers PPPoE on the subscribers' interface, hands each one
 * an address and routes them out — so the credentials the platform writes for every line
 * actually authenticate something.
 */
new #[Title('PPPoE setup')] class extends Component {
    public Router $router;

    public string $interface = '';

    public string $address = '10.20.0.1/24';

    public string $pool = '';

    public bool $poolEdited = false;

    public string $dnsServers = '1.1.1.1,8.8.8.8';

    public string $serviceName = 'isp';

    public bool $masquerade = true;

    public function mount(Router $router): void
    {
        Gate::authorize('provision', $router);

        $this->router = $router;

        // What was built last time, so running it again is "fix my PPPoE".
        $options = $router->setupOptions();
        $this->interface = ($options['with_pppoe'] ?? false) === true ? (string) ($options['pppoe_interface'] ?? '') : '';
        $gateway = (string) ($options['pppoe_gateway'] ?? '10.20.0.1');
        $prefix = str_contains((string) ($options['pppoe_network'] ?? ''), '/') ? explode('/', (string) $options['pppoe_network'])[1] : '24';
        $this->address = $gateway.'/'.$prefix;
        $this->pool = (string) ($options['pppoe_pool'] ?? HotspotInstaller::defaultPool($gateway, (int) $prefix));
        $this->dnsServers = (string) ($options['pppoe_dns_servers'] ?? '1.1.1.1,8.8.8.8');
        $this->serviceName = (string) ($options['pppoe_service_name'] ?? 'isp');
    }

    public function updatedAddress(): void
    {
        if ($this->poolEdited) {
            return;
        }

        if (preg_match('#^(\d{1,3}(?:\.\d{1,3}){3})/(\d{1,2})$#', trim($this->address), $match)) {
            $this->pool = HotspotInstaller::defaultPool($match[1], (int) $match[2]);
        }
    }

    public function updatedPool(): void
    {
        $this->poolEdited = true;
    }

    /**
     * Ports, bridges and VLANs worth offering, and the PPPoE servers already on the device.
     *
     * @return array{interfaces: array<int, array<string, string>>, servers: array<int, array<string, string>>, error: string|null}
     */
    #[Computed]
    public function device(): array
    {
        try {
            $connection = app(RouterOsConnector::class)->connect($this->router);
            $interfaces = $connection->get('/interface');
            $bridged = collect($connection->get('/interface/bridge/port'))->pluck('interface')->all();
            $servers = $connection->get('/interface/pppoe-server/server');
            $connection->disconnect();
        } catch (Throwable $e) {
            return ['interfaces' => [], 'servers' => [], 'error' => $e->getMessage()];
        }

        $options = $this->router->setupOptions();
        $excluded = array_filter([$options['wan_interface'] ?? null, $options['interface_name'] ?? null, 'bridge-tech']);

        $usable = array_values(array_filter($interfaces, fn (array $row): bool => ! in_array($row['name'] ?? '', $excluded, true)
            && ! in_array($row['name'] ?? '', $bridged, true)
            && in_array($row['type'] ?? '', ['ether', 'bridge', 'vlan'], true)
            && ($row['dynamic'] ?? 'false') !== 'true'));

        return ['interfaces' => $usable, 'servers' => $servers, 'error' => null];
    }

    /**
     * @return array{status: string, report?: array<int, array{step: string, status: string, detail: string}>, finished_at?: string}|null
     */
    #[Computed]
    public function run(): ?array
    {
        return Cache::get(SetUpPppoe::cacheKey($this->router->id));
    }

    public function install(PppoeServerInstaller $installer): void
    {
        Gate::authorize('provision', $this->router);

        $this->validate([
            'interface' => ['required', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'address' => ['required', 'regex:#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#'],
            'pool' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,3}){3}-\d{1,3}(\.\d{1,3}){3}(,\d{1,3}(\.\d{1,3}){3}-\d{1,3}(\.\d{1,3}){3})*$/'],
            'dnsServers' => ['required', 'regex:/^[0-9.,\s]+$/'],
            'serviceName' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-]*$/', 'max:40'],
        ]);

        $setup = [
            'interface' => $this->interface,
            'address' => $this->address,
            'pool' => $this->pool,
            'dns_servers' => $this->dnsServers,
            'service_name' => $this->serviceName,
            'masquerade' => $this->masquerade,
        ];

        // Checked here too, so a clash is shown beside the field rather than in a report.
        try {
            $installer->plan($this->router, $setup);
        } catch (InvalidArgumentException $e) {
            $this->addError('address', $e->getMessage());

            return;
        }

        Cache::put(SetUpPppoe::cacheKey($this->router->id), ['status' => 'running', 'started_at' => now()->toIso8601String()], now()->addHour());

        $job = new SetUpPppoe((int) Tenancy::id(), $this->router->id, $setup, Auth::id());

        config('queue.default') === 'sync' ? dispatch_sync($job) : dispatch($job);

        unset($this->run, $this->device);
    }

    public function poll(): void
    {
        unset($this->run);

        if (($this->run['status'] ?? null) === 'done') {
            unset($this->device);
        }
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('PPPoE setup') }}</flux:heading>
                <flux:subheading>
                    {{ __(':router — the interface subscribers dial in on, the addresses they get, and the server that checks their username and password.', ['router' => $router->name]) }}
                </flux:subheading>
            </div>

            <div class="flex items-center gap-2">
                <flux:button variant="ghost" icon="signal" :href="route('routers.console', ['router' => $router, 'menu' => 'ppp-active'])" wire:navigate>
                    {{ __('Who is online') }}
                </flux:button>
                <flux:button variant="ghost" icon="arrow-left" :href="route('routers.show', $router)" wire:navigate>
                    {{ __('Back to router') }}
                </flux:button>
            </div>
        </div>

        @if ($this->device['error'] !== null)
            <flux:callout variant="danger" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('The platform cannot reach this router') }}</flux:callout.heading>
                <flux:callout.text>{{ $this->device['error'] }}</flux:callout.text>
                <flux:callout.text>{{ __('A router the platform cannot reach yet is set up by pasting its setup script.') }}</flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" :href="route('routers.setup', $router)" wire:navigate>{{ __('Setup script') }}</flux:button>
                </x-slot>
            </flux:callout>
        @elseif ($this->device['servers'] !== [])
            <flux:callout variant="secondary" icon="information-circle">
                <flux:callout.heading>{{ __('PPPoE is already running on this router') }}</flux:callout.heading>
                <flux:callout.text>
                    @foreach ($this->device['servers'] as $server)
                        {{ __('":name" on :interface:state', ['name' => $server['service-name'] ?? '?', 'interface' => $server['interface'] ?? '?', 'state' => ($server['disabled'] ?? 'false') === 'true' ? ' ('.__('disabled').')' : '']) }}@if (! $loop->last), @endif
                    @endforeach
                    · {{ __('Running this again updates it.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <form
            wire:submit="install"
            wire:confirm="{{ __('Set up PPPoE? Subscribers on the chosen interface will be able to dial in with the usernames and passwords on their lines.') }}"
            class="flex flex-col gap-6"
        >
            <flux:card class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('1. PPPoE interface') }}</flux:heading>
                    <flux:subheading>{{ __('The port or bridge subscribers\' routers are cabled to. It can be the same bridge as the HotSpot. Never the uplink.') }}</flux:subheading>
                </div>

                <flux:select wire:model="interface" :label="__('Interface')" :placeholder="__('Choose an interface…')">
                    @foreach ($this->device['interfaces'] as $row)
                        <flux:select.option :value="$row['name']">
                            {{ $row['name'] }} · {{ $row['type'] ?? '' }}{{ ($row['comment'] ?? '') !== '' ? ' · '.$row['comment'] : '' }}
                        </flux:select.option>
                    @endforeach
                    @if ($interface !== '' && ! collect($this->device['interfaces'])->contains('name', $interface))
                        <flux:select.option :value="$interface">{{ $interface }}</flux:select.option>
                    @endif
                </flux:select>
                <flux:error name="interface" />

                <flux:input wire:model="serviceName" :label="__('Service name')" :description="__('What subscribers\' routers see when they look for a PPPoE server. Most never need to change it.')" />
            </flux:card>

            <flux:card class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('2. Addresses') }}</flux:heading>
                    <flux:subheading>{{ __('The router\'s side of every subscriber\'s connection, and the addresses subscribers are given. Keep it apart from the HotSpot network.') }}</flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.500ms="address" :label="__('Gateway')" placeholder="10.20.0.1/24" :description="__('With its prefix.')" />
                    <flux:input wire:model.live.debounce.500ms="pool" :label="__('Address pool')" placeholder="10.20.0.10-10.20.0.254" :description="__('One address per connected subscriber.')" />
                </div>
                <flux:error name="address" />

                <flux:input wire:model="dnsServers" :label="__('DNS servers')" placeholder="1.1.1.1,8.8.8.8" />

                <flux:switch wire:model="masquerade" :label="__('Masquerade network')" :description="__('Lets subscribers out to the internet. Leave on unless another rule already does this.')" />
            </flux:card>

            @php($run = $this->run)

            <div class="flex items-center justify-end gap-3">
                @if (($run['status'] ?? null) === 'running')
                    <flux:text>{{ __('Setting up… this takes a few seconds.') }}</flux:text>
                @endif

                <flux:button type="submit" variant="primary" icon="bolt"
                    :disabled="($run['status'] ?? null) === 'running' || $this->device['error'] !== null">
                    {{ __('Set up PPPoE') }}
                </flux:button>
            </div>
        </form>

        @if ($run !== null)
            {{-- The poll sits on a plain element: Blade cannot compile an @if inside a Flux
                 component's opening tag. --}}
            <div @if (($run['status'] ?? null) === 'running') wire:poll.2s="poll" @endif>
            <flux:card class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Result') }}</flux:heading>

                @if (($run['status'] ?? null) === 'running')
                    <flux:text>{{ __('Working through the steps on the router…') }}</flux:text>
                    <flux:text size="sm" class="opacity-70">
                        {{ __('If this never finishes, the queue worker is not running. On a server that is isp-queue.service; on a laptop, php artisan queue:work.') }}
                    </flux:text>
                @else
                    @php($failed = collect($run['report'] ?? [])->contains('status', 'failed'))

                    <flux:callout :variant="$failed ? 'danger' : 'success'" :icon="$failed ? 'exclamation-triangle' : 'check-circle'">
                        <flux:callout.heading>{{ $failed ? __('PPPoE is not complete') : __('PPPoE is ready') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ $failed
                                ? __('Fix what is marked below and run it again. Running it again replaces what was made; it never doubles anything.')
                                : __('Give a subscriber a line under PPPoE → Subscribers (or on their customer page) and dial in from their router with that username and password.') }}
                        </flux:callout.text>
                    </flux:callout>

                    <flux:table>
                        <flux:table.rows>
                            @foreach ($run['report'] ?? [] as $line)
                                <flux:table.row>
                                    <flux:table.cell class="w-56">{{ $line['step'] }}</flux:table.cell>
                                    <flux:table.cell class="w-24">
                                        @php($badgeColor = ['ok' => 'green', 'failed' => 'red'][$line['status']] ?? 'zinc')
                                        @php($badgeText = ['ok' => __('done'), 'failed' => __('failed')][$line['status']] ?? __('skipped'))
                                        <flux:badge size="sm" :color="$badgeColor">{{ $badgeText }}</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="whitespace-normal">{{ $line['detail'] }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:card>
            </div>
        @endif
    </div>
</section>
