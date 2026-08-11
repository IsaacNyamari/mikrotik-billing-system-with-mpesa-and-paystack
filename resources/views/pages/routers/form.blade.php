<?php

use App\Models\Router;
use App\Services\Provisioning\RouterSetupScript;
use App\Services\RouterOs\RouterHealthProbe;
use App\Support\Audit\AuditLogger;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Router')] class extends Component {
    /** The platform generates a script the operator pastes; the router dials out to us. */
    public const CONNECTION_TUNNEL = 'tunnel';

    /** The old way: the platform dials the router, which needs a reachable address. */
    public const CONNECTION_DIRECT = 'direct';

    public ?Router $router = null;

    public string $connection = self::CONNECTION_TUNNEL;

    public string $name = '';
    public string $host = '';
    public int $api_port = 8728;
    public string $api_username = '';
    public string $api_password = '';
    public bool $uses_tls = false;
    public string $location = '';
    public string $notes = '';

    /** Only asked for on the tunnel path, where the script has to name a real interface. */
    public string $lanInterface = '';

    public bool $withHotspot = true;

    public function mount(?Router $router = null): void
    {
        $this->router = $router?->exists ? $router : null;
        $this->lanInterface = (string) config('wireguard.defaults.lan_interface');

        if ($this->router === null) {
            Gate::authorize('create', Router::class);

            // Default to the tunnel: it is the only method that works on a connection the
            // operator does not control, which is most of them.
            $this->connection = $this->tunnelAvailable
                ? self::CONNECTION_TUNNEL
                : self::CONNECTION_DIRECT;

            return;
        }

        Gate::authorize('update', $this->router);

        $this->fill([
            'connection' => $this->router->hasTunnel() ? self::CONNECTION_TUNNEL : self::CONNECTION_DIRECT,
            'name' => $this->router->name,
            'host' => $this->router->host,
            'api_port' => $this->router->api_port,
            'api_username' => $this->router->api_username,
            'uses_tls' => $this->router->uses_tls,
            'location' => (string) $this->router->location,
            'notes' => (string) $this->router->notes,
        ]);

        // The stored password is never sent to the browser. Leaving the field blank on an
        // edit keeps whatever is already saved.
    }

    /**
     * Whether the platform has a management tunnel to offer at all.
     */
    #[Computed]
    public function tunnelAvailable(): bool
    {
        return config('wireguard.enabled') === true && ! blank(config('wireguard.public_key'));
    }

    public function usesTunnel(): bool
    {
        return $this->connection === self::CONNECTION_TUNNEL;
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'connection' => ['required', Rule::in([self::CONNECTION_TUNNEL, self::CONNECTION_DIRECT])],
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];

        if ($this->usesTunnel()) {
            // Nothing to ask for. The address, the credentials and the keys are all issued by
            // the platform and written into the script — asking an operator to invent them
            // would only be asking them to get them wrong.
            return $rules + [
                'lanInterface' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+$/'],
                'withHotspot' => ['boolean'],
            ];
        }

        return $rules + [
            'host' => ['required', 'string', 'max:255'],
            'api_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'api_username' => ['required', 'string', 'max:255'],
            'api_password' => [$this->router === null ? 'required' : 'nullable', 'string', 'max:255'],
            'uses_tls' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'lanInterface.regex' => __('An interface name, like ether3 or wlan1.'),
        ];
    }

    public function save(RouterHealthProbe $probe, AuditLogger $audit, RouterSetupScript $scripts): void
    {
        $validated = $this->validate();

        $router = $this->router ?? new Router;
        $isNew = $this->router === null;

        Gate::authorize($isNew ? 'create' : 'update', $isNew ? Router::class : $router);

        $router->fill([
            'name' => $validated['name'],
            'location' => $validated['location'] !== '' ? $validated['location'] : null,
            'notes' => $validated['notes'] !== '' ? $validated['notes'] : null,
        ]);

        if (! $this->usesTunnel()) {
            $router->fill([
                'host' => $validated['host'],
                'api_port' => $validated['api_port'],
                'api_username' => $validated['api_username'],
                'uses_tls' => $validated['uses_tls'],
            ]);

            if (($validated['api_password'] ?? '') !== '') {
                $router->api_password = $validated['api_password'];
            }
        } elseif ($isNew) {
            // Placeholders only. The script generator replaces every one of these with the
            // tunnel address and the credentials it writes into the script, but the columns
            // are not nullable and the row has to exist before a tunnel can be allocated to it.
            $router->fill([
                'host' => '0.0.0.0',
                'api_port' => 8728,
                'api_username' => (string) config('wireguard.defaults.api_username'),
                'uses_tls' => false,
            ]);
            $router->api_password = Str::password(24, symbols: false);
        }

        if ($isNew) {
            $router->slug = $this->uniqueSlug($validated['name']);
        }

        $router->save();

        $audit->record(
            event: $isNew ? 'router.created' : 'router.updated',
            auditable: $router,
            description: sprintf('%s router %s', $isNew ? 'Added' : 'Updated', $router->name),
        );

        if ($this->usesTunnel()) {
            // Generate now so the script exists the moment they land on it, and so the router
            // carries its tunnel address rather than the placeholder above.
            $scripts->for($router, [
                'lan_interface' => $this->lanInterface,
                'with_hotspot' => $this->withHotspot,
            ]);

            // Deliberately no probe: the device has not been configured yet, and telling an
            // operator their brand-new router is unreachable — seconds before handing them
            // the script that makes it reachable — reads as a failure when it is a step.
            Flux::toast(
                variant: 'success',
                text: __('Saved. Paste the script below into :name to bring it online.', ['name' => $router->name]),
            );

            $this->redirectRoute('routers.setup', $router, navigate: true);

            return;
        }

        // Contact the device straight away: an operator who has just typed credentials wants
        // to know there and then whether they work.
        $probe->probe($router);

        $router->status->isReachable()
            ? Flux::toast(variant: 'success', text: __('Saved and connected to :name.', ['name' => $router->identity ?? $router->name]))
            : Flux::toast(variant: 'warning', text: __('Saved, but the router could not be reached: :error', ['error' => $router->last_error]));

        $this->redirectRoute('routers.show', $router, navigate: true);
    }

    /**
     * Slugs are unique per ISP, so a second "Nairobi 01" gets a suffix rather than an error.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 2;

        while (Router::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">
                {{ $router === null ? __('Add a router') : __('Edit :name', ['name' => $router->name]) }}
            </flux:heading>
            <flux:subheading>
                {{ __('The platform connects over the RouterOS API to read the device and provision users onto it.') }}
            </flux:subheading>
        </div>

        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="name" :label="__('Name')" required autofocus :placeholder="__('Nairobi 01')" />

            {{-- The choice that decides everything below it. Asked first, because an operator
                 who does not control the router's connection cannot answer the direct
                 questions at all. --}}
            <flux:radio.group wire:model.live="connection" :label="__('How does the platform reach this router?')" variant="cards" class="flex-col">
                <flux:radio
                    value="tunnel"
                    :label="__('Generate a setup script')"
                    :description="__('The router dials out to us. No public address, no port forwarding, works behind NAT and CGNAT. You paste one script into the router once.')"
                    :disabled="! $this->tunnelAvailable"
                />
                <flux:radio
                    value="direct"
                    :label="__('I have an address for it')"
                    :description="__('The platform dials the router. Only for a device on a static public address, or one on the same network as this server.')"
                />
            </flux:radio.group>

            @unless ($this->tunnelAvailable)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>
                        {{ __('The management tunnel is not configured on this server, so scripts cannot be generated yet. Set WIREGUARD_PUBLIC_KEY and WIREGUARD_ENDPOINT.') }}
                    </flux:callout.text>
                </flux:callout>
            @endunless

            @if ($this->usesTunnel())
                <flux:callout icon="information-circle">
                    <flux:callout.heading>{{ __('Nothing to set up on the MikroTik first') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Save this and you will get a script to paste into the router\'s terminal. It builds the tunnel, the API user, the firewall rules and the HotSpot in one go.') }}
                    </flux:callout.text>
                </flux:callout>

                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input
                        wire:model="lanInterface"
                        :label="__('Interface customers connect to')"
                        required
                        placeholder="ether3"
                        :description="__('The port they plug into, or the wireless interface. Never the uplink — putting that in the bridge takes the router offline.')"
                    />

                    <flux:switch
                        wire:model.live="withHotspot"
                        :label="__('Set up the HotSpot too')"
                        :description="__('Turn off for a router whose network you have already built. The tunnel and API are still configured.')"
                    />
                </div>
            @else
                @if ($router === null)
                    <flux:callout icon="information-circle">
                        <flux:callout.heading>{{ __('On the MikroTik first') }}</flux:callout.heading>
                        <flux:callout.text>
                            {{ __('Enable the API service under /ip/service, and create a dedicated user for this platform rather than reusing admin. The user needs the api, read, write and policy permissions.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif

                <div class="grid gap-6 sm:grid-cols-3">
                    <flux:input
                        wire:model="host"
                        class="sm:col-span-2"
                        :label="__('Address')"
                        required
                        :description="__('IP or hostname the platform can reach.')"
                    />
                    <flux:input wire:model="api_port" type="number" :label="__('API port')" required />
                </div>

                <div class="grid gap-6 sm:grid-cols-2">
                    <flux:input wire:model="api_username" :label="__('API username')" required autocomplete="off" />
                    <flux:input
                        wire:model="api_password"
                        type="password"
                        :label="__('API password')"
                        :required="$router === null"
                        autocomplete="new-password"
                        :description="$router === null ? null : __('Leave blank to keep the stored password.')"
                    />
                </div>

                <flux:switch
                    wire:model="uses_tls"
                    :label="__('Use TLS')"
                    :description="__('Connect on the encrypted API port. The router needs api-ssl enabled with a certificate.')"
                />
            @endif

            <flux:input wire:model="location" :label="__('Location')" :description="__('Optional, e.g. “Kilimani, Block C rooftop”.')" />
            <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit" data-test="save-router-button">
                    @if ($this->usesTunnel())
                        <span wire:loading.remove wire:target="save">{{ __('Save and get the script') }}</span>
                        <span wire:loading wire:target="save">{{ __('Generating…') }}</span>
                    @else
                        <span wire:loading.remove wire:target="save">{{ __('Save and test connection') }}</span>
                        <span wire:loading wire:target="save">{{ __('Connecting…') }}</span>
                    @endif
                </flux:button>

                <flux:button
                    variant="ghost"
                    :href="$router === null ? route('routers.index') : route('routers.show', $router)"
                    wire:navigate
                >
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </form>
    </div>
</section>
