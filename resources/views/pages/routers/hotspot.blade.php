<?php

use App\Jobs\SetUpHotspot;
use App\Models\Router;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\HotspotInstaller;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * HotSpot setup: Winbox's wizard, from the dashboard.
 *
 * The same questions in the same order — which interface, what network, which addresses to
 * hand out, which DNS — answered on one screen and applied over the API. For a router the
 * platform can already reach; one it cannot reach is set up by pasting its setup script.
 */
new #[Title('HotSpot setup')] class extends Component {
    public Router $router;

    public string $interface = '';

    public string $address = '10.10.10.1/24';

    public string $pool = '';

    /** Whether the pool was typed by hand, so changing the address stops overwriting it. */
    public bool $poolEdited = false;

    public string $dnsServers = '1.1.1.1,8.8.8.8';

    public string $dnsName = '';

    public bool $masquerade = true;

    public string $name = 'billing-hotspot';

    public function mount(Router $router): void
    {
        Gate::authorize('provision', $router);

        $this->router = $router;

        // What was built last time, so re-running is "fix my HotSpot" rather than starting
        // from defaults that no longer match the device.
        $options = $router->setupOptions();
        $this->interface = (string) ($options['lan_interface'] ?? '');
        $this->name = (string) ($options['hotspot_name'] ?? 'billing-hotspot');
        $gateway = (string) ($options['hotspot_gateway'] ?? '10.10.10.1');
        $prefix = str_contains((string) ($options['hotspot_network'] ?? ''), '/') ? explode('/', (string) $options['hotspot_network'])[1] : '24';
        $this->address = $gateway.'/'.$prefix;
        $this->pool = (string) ($options['hotspot_pool'] ?? HotspotInstaller::defaultPool($gateway, (int) $prefix));
        $this->dnsServers = (string) ($options['hotspot_dns_servers'] ?? '1.1.1.1,8.8.8.8');
        $this->dnsName = (string) ($options['hotspot_dns_name'] ?? '');
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
     * The interfaces worth offering: ports, bridges, radios and VLANs — never the uplink or the
     * management tunnel, and not a port that already sits in a bridge (its bridge is offered).
     *
     * @return array{interfaces: array<int, array<string, string>>, error: string|null}
     */
    #[Computed]
    public function device(): array
    {
        try {
            $connection = app(RouterOsConnector::class)->connect($this->router);
            $interfaces = $connection->get('/interface');
            $bridged = collect($connection->get('/interface/bridge/port'))->pluck('interface')->all();
            $connection->disconnect();
        } catch (Throwable $e) {
            return ['interfaces' => [], 'error' => $e->getMessage()];
        }

        $options = $this->router->setupOptions();
        $excluded = array_filter([$options['wan_interface'] ?? null, $options['interface_name'] ?? null]);

        $usable = array_values(array_filter($interfaces, fn (array $row): bool => ! in_array($row['name'] ?? '', $excluded, true)
            && ! in_array($row['name'] ?? '', $bridged, true)
            && in_array($row['type'] ?? '', ['ether', 'bridge', 'wlan', 'wifi', 'vlan'], true)
            && ($row['dynamic'] ?? 'false') !== 'true'));

        return ['interfaces' => $usable, 'error' => null];
    }

    /**
     * Where the last run stands: running, or done with a step-by-step report.
     *
     * @return array{status: string, report?: array<int, array{step: string, status: string, detail: string}>, finished_at?: string}|null
     */
    #[Computed]
    public function run(): ?array
    {
        return Cache::get(SetUpHotspot::cacheKey($this->router->id));
    }

    public function install(HotspotInstaller $installer): void
    {
        Gate::authorize('provision', $this->router);

        $this->validate([
            'interface' => ['required', 'regex:/^[A-Za-z0-9._\-]+$/'],
            'address' => ['required', 'regex:#^\d{1,3}(\.\d{1,3}){3}/\d{1,2}$#'],
            'pool' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,3}){3}-\d{1,3}(\.\d{1,3}){3}(,\d{1,3}(\.\d{1,3}){3}-\d{1,3}(\.\d{1,3}){3})*$/'],
            'dnsServers' => ['required', 'regex:/^[0-9.,\s]+$/'],
            'dnsName' => ['nullable', 'max:128'],
            'name' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-]*$/', 'max:40'],
        ]);

        $setup = [
            'interface' => $this->interface,
            'address' => $this->address,
            'pool' => $this->pool,
            'dns_servers' => $this->dnsServers,
            'dns_name' => $this->dnsName,
            'masquerade' => $this->masquerade,
            'name' => $this->name,
        ];

        // Checked here as well as in the job, so a mistake is shown beside the field rather
        // than half a minute later in a report.
        try {
            $installer->plan($this->router, $setup);
        } catch (InvalidArgumentException $e) {
            $this->addError('address', $e->getMessage());

            return;
        }

        Cache::put(SetUpHotspot::cacheKey($this->router->id), ['status' => 'running', 'started_at' => now()->toIso8601String()], now()->addHour());

        $job = new SetUpHotspot((int) Tenancy::id(), $this->router->id, $setup, Auth::id());

        config('queue.default') === 'sync' ? dispatch_sync($job) : dispatch($job);

        unset($this->run);
    }

    public function poll(): void
    {
        unset($this->run);
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('HotSpot setup') }}</flux:heading>
                <flux:subheading>
                    {{ __(':router — the interface customers connect to, its network, DNS, and the HotSpot that asks them to pay.', ['router' => $router->name]) }}
                </flux:subheading>
            </div>

            <div class="flex items-center gap-2">
                <flux:button variant="ghost" icon="squares-2x2" :href="route('routers.console', $router)" wire:navigate>
                    {{ __('Console') }}
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
                <flux:callout.text>
                    {{ __('A router the platform cannot reach yet is set up by pasting its setup script, which builds the HotSpot too.') }}
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" :href="route('routers.setup', $router)" wire:navigate>{{ __('Setup script') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        <form
            wire:submit="install"
            wire:confirm="{{ __('Set up the HotSpot? Devices on the chosen interface will be asked to log in from now on.') }}"
            class="flex flex-col gap-6"
        >
            <flux:card class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('1. HotSpot interface') }}</flux:heading>
                    <flux:subheading>{{ __('Where customers connect: the bridge their wifi and ports are in, or a single port. Never the uplink.') }}</flux:subheading>
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
            </flux:card>

            <flux:card class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('2. Network') }}</flux:heading>
                    <flux:subheading>{{ __('The router\'s address on the customers\' network, and the addresses it hands out.') }}</flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model.live.debounce.500ms="address" :label="__('Local address of network')" placeholder="10.10.10.1/24" :description="__('The gateway, with its prefix.')" />
                    <flux:input wire:model.live.debounce.500ms="pool" :label="__('Address pool')" placeholder="10.10.10.10-10.10.10.254" :description="__('Filled in from the address. Change it to keep room for fixed devices.')" />
                </div>

                <flux:switch wire:model="masquerade" :label="__('Masquerade network')" :description="__('Lets customers out to the internet. Leave on unless another rule already does this.')" />
            </flux:card>

            <flux:card class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('3. DNS') }}</flux:heading>
                    <flux:subheading>{{ __('The router answers its customers\' DNS itself, which is what makes the login page appear.') }}</flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="dnsServers" :label="__('DNS servers')" placeholder="1.1.1.1,8.8.8.8" :description="__('Where the router asks on their behalf.')" />
                    <flux:input wire:model="dnsName" :label="__('DNS name')" placeholder="wifi.myisp.co.ke" :description="__('Optional. The HotSpot\'s own name instead of a bare address.')" />
                </div>
            </flux:card>

            <flux:card class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('4. HotSpot') }}</flux:heading>
                    <flux:subheading>{{ __('Customers who join are sent to this ISP\'s portal to pay; the portal, M-PESA and Paystack are reachable before they have.') }}</flux:subheading>
                </div>

                <flux:input wire:model="name" :label="__('HotSpot name')" :description="__('How it is named on the router. Keep the default unless you run more than one.')" />
            </flux:card>

            @php($run = $this->run)

            <div class="flex items-center justify-end gap-3">
                @if (($run['status'] ?? null) === 'running')
                    <flux:text>{{ __('Setting up… this takes up to a minute.') }}</flux:text>
                @endif

                <flux:button
                    type="submit"
                    variant="primary"
                    icon="wifi"
                    :disabled="($run['status'] ?? null) === 'running' || $this->device['error'] !== null"
                >
                    {{ __('Set up HotSpot') }}
                </flux:button>
            </div>
        </form>

        @if ($run !== null)
            <flux:card class="flex flex-col gap-4" @if (($run['status'] ?? null) === 'running') wire:poll.2s="poll" @endif>
                <flux:heading size="lg">{{ __('Result') }}</flux:heading>

                @if (($run['status'] ?? null) === 'running')
                    <flux:text>{{ __('Working through the steps on the router…') }}</flux:text>
                    <flux:text size="sm" class="opacity-70">
                        {{ __('If this never finishes, the queue worker is not running. On a server that is isp-queue.service; on a laptop, php artisan queue:work.') }}
                    </flux:text>
                @else
                    @php($failed = collect($run['report'] ?? [])->contains('status', 'failed'))

                    <flux:callout :variant="$failed ? 'danger' : 'success'" :icon="$failed ? 'exclamation-triangle' : 'check-circle'">
                        <flux:callout.heading>
                            {{ $failed ? __('The HotSpot is not complete') : __('The HotSpot is ready') }}
                        </flux:callout.heading>
                        <flux:callout.text>
                            {{ $failed
                                ? __('Fix what is marked below and run it again. Running it again replaces what was made; it never doubles anything.')
                                : __('Join the wifi on :interface from a phone: it should open the payment page by itself.', ['interface' => $interface]) }}
                        </flux:callout.text>
                    </flux:callout>

                    <flux:table>
                        <flux:table.rows>
                            @foreach ($run['report'] ?? [] as $line)
                                <flux:table.row>
                                    <flux:table.cell class="w-56">{{ $line['step'] }}</flux:table.cell>
                                    <flux:table.cell class="w-24">
                                        <flux:badge size="sm" :color="match ($line['status']) { 'ok' => 'green', 'failed' => 'red', default => 'zinc' }">
                                            {{ match ($line['status']) { 'ok' => __('done'), 'failed' => __('failed'), default => __('skipped') } }}
                                        </flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell class="whitespace-normal">{{ $line['detail'] }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                @endif
            </flux:card>
        @endif
    </div>
</section>
