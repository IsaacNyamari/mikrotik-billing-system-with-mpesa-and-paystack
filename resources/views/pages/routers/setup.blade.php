<?php

use App\Models\Router;
use App\Services\Provisioning\RouterSetupScript;
use App\Services\Provisioning\TunnelAllocator;
use App\Services\Provisioning\WireGuardPeerSync;
use App\Services\RouterOs\RouterHealthProbe;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\Permissions;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The script that makes a router ready, for the operator standing in front of it.
 *
 * This is the one thing in the platform that cannot be done over the API, because a router
 * behind NAT has no API to do it over. It is carried by a person and pasted into the device's
 * own terminal.
 */
new #[Title('Router setup')] class extends Component {
    #[Locked]
    public int $routerId = 0;

    public string $lanInterface = '';

    public string $wanInterface = '';

    /** How the uplink gets its internet after a wipe: dhcp, static or pppoe. */
    public string $wanMode = 'dhcp';

    public string $wanAddress = '';

    public string $wanGateway = '';

    public string $wanDns = '';

    public string $wanPppoeUser = '';

    /**
     * Never prefilled, and cleared the moment it is stored: it is the ISP's own provider's
     * credential, and the component's state travels to the browser on every request.
     */
    public string $wanPppoePassword = '';

    /** The ISP administrator's password stays hidden until somebody asks, and asking is audited. */
    public bool $showAdminPassword = false;

    /** Why the script could not be built, when it could not. */
    protected ?string $scriptError = null;

    public bool $withHotspot = true;

    /** The wifi customers look for on their phone. */
    public bool $withWireless = true;

    public string $wirelessInterface = '';

    public string $ssid = '';

    /** A PPPoE server, so the secrets the platform writes can authenticate somebody. */
    public bool $withPppoe = false;

    public string $pppoeInterface = '';

    public string $pppoeService = '';

    /** Whether the script erases the router first. Never the default. */
    public bool $wipe = true;

    public function mount(Router $router): void
    {
        Gate::authorize(Permissions::ROUTERS_PROVISION);

        $this->routerId = $router->id;

        // What was chosen when this router was added, not the platform default — otherwise
        // an operator returning to this page is handed a script that bridges the wrong port.
        $options = $router->setupOptions();

        $this->lanInterface = (string) $options['lan_interface'];
        $this->wanInterface = (string) ($options['wan_interface'] ?? 'ether1');
        $this->wanMode = (string) ($options['wan_mode'] ?? 'dhcp');
        $this->wanAddress = (string) ($options['wan_address'] ?? '');
        $this->wanGateway = (string) ($options['wan_gateway'] ?? '');
        $this->wanDns = (string) ($options['wan_dns'] ?? '');
        $this->wanPppoeUser = (string) ($options['wan_pppoe_user'] ?? '');
        $this->withHotspot = (bool) ($options['with_hotspot'] ?? true);
        $this->wipe = (bool) ($options['wipe'] ?? true);

        $this->withWireless = (bool) ($options['with_wireless'] ?? true);
        $this->wirelessInterface = (string) ($options['wireless_interface'] ?? 'wlan1');
        // Blank means "use what the ISP calls itself", which is what a customer scanning a
        // list of wifi names is looking for.
        $this->ssid = (string) ($options['ssid'] ?? '');

        $this->withPppoe = (bool) ($options['with_pppoe'] ?? false);
        $this->pppoeInterface = (string) ($options['pppoe_interface'] ?? 'ether4');
        $this->pppoeService = (string) ($options['pppoe_service_name'] ?? 'isp');
    }

    #[Computed]
    public function router(): Router
    {
        return Router::query()->findOrFail($this->routerId);
    }

    /**
     * Generated on every render rather than stored.
     *
     * It carries a private key and a password, so keeping a copy anywhere it does not have to
     * be — a cache, a file, a job payload — is one more place it can leak from.
     */
    #[Computed]
    public function script(): string
    {
        $this->scriptError = null;

        try {
            return $this->build();
        } catch (InvalidArgumentException $e) {
            // An uplink that is not filled in yet. Shown instead of a script, because a wipe
            // built from it would bring the router back with no way out.
            $this->scriptError = $e->getMessage();

            return '';
        }
    }

    public function scriptError(): ?string
    {
        return $this->scriptError;
    }

    protected function build(): string
    {
        return app(RouterSetupScript::class)->for($this->router, [
            'lan_interface' => $this->lanInterface,
            'wan_interface' => $this->wanInterface,
            'wan_mode' => in_array($this->wanMode, ['dhcp', 'static', 'pppoe'], true) ? $this->wanMode : 'dhcp',
            'wan_address' => $this->wanAddress,
            'wan_gateway' => $this->wanGateway,
            'wan_dns' => $this->wanDns,
            'wan_pppoe_user' => $this->wanPppoeUser,
            'with_hotspot' => $this->withHotspot,
            'wipe' => $this->wipe,
            'with_wireless' => $this->withWireless,
            'wireless_interface' => $this->wirelessInterface,
            'ssid' => $this->ssid,
            'with_pppoe' => $this->withPppoe,
            'pppoe_interface' => $this->pppoeInterface,
            'pppoe_service_name' => $this->pppoeService,
            // The password already on the router stays valid until the operator deliberately
            // rotates it, so opening this page does not silently lock out a working device.
            'api_password' => $this->router->api_password,
        ]);
    }

    /**
     * What the wifi is called when the ISP has not said otherwise.
     */
    #[Computed]
    public function defaultSsid(): string
    {
        return \App\Models\PortalSetting::current()->displayName($this->router->tenant);
    }

    /**
     * Store the upstream PPPoE password and forget it here.
     */
    public function updatedWanPppoePassword(): void
    {
        Gate::authorize(Permissions::ROUTERS_PROVISION);

        if ($this->wanPppoePassword === '') {
            return;
        }

        $router = $this->router;
        $router->wan_pppoe_password = $this->wanPppoePassword;
        $router->save();

        $this->wanPppoePassword = '';

        unset($this->router, $this->script);
    }

    /**
     * Show the ISP administrator's password.
     *
     * It is the one credential that opens the whole device, so who looked at it, and when,
     * goes on the audit trail.
     */
    public function revealAdminPassword(AuditLogger $audit): void
    {
        Gate::authorize(Permissions::ROUTERS_PROVISION);

        $audit->record(
            event: 'router.admin_password_revealed',
            auditable: $this->router,
            description: sprintf('Viewed the administrator password for %s', $this->router->name),
        );

        $this->showAdminPassword = true;
    }

    #[Computed]
    public function peerSyncIsAutomatic(): bool
    {
        return app(WireGuardPeerSync::class)->isAvailable();
    }

    /**
     * Where the one-time download link stands, for the wipe script that depends on it.
     *
     * Worth showing rather than leaving to be discovered: a spent or expired link fails on the
     * router with a bare 404, at the point where the operator is standing over a device they
     * are about to erase.
     */
    #[Computed]
    public function linkState(): array
    {
        $router = $this->router;

        if ($router->provision_token === null) {
            return ['ok' => true, 'text' => __('A download link is created when you copy the script.')];
        }

        if ($router->provision_fetched_at !== null) {
            return ['ok' => false, 'text' => __('This link has already been used, :when. Generate a new script before pasting it again.', [
                'when' => $router->provision_fetched_at->diffForHumans(),
            ])];
        }

        if ($router->provision_token_expires_at?->isPast()) {
            return ['ok' => false, 'text' => __('This link expired :when. Generate a new script.', [
                'when' => $router->provision_token_expires_at->diffForHumans(),
            ])];
        }

        return ['ok' => true, 'text' => __('The download link works once and expires :when.', [
            'when' => $router->provision_token_expires_at?->diffForHumans() ?? '—',
        ])];
    }

    /**
     * Throw the current download link away and mint another.
     */
    public function newLink(): void
    {
        Gate::authorize(Permissions::ROUTERS_PROVISION);

        $router = $this->router;
        $router->provision_token = null;
        $router->provision_token_expires_at = null;
        $router->provision_fetched_at = null;
        $router->save();

        unset($this->router, $this->script, $this->linkState);

        Flux::toast(text: __('New link. Copy the script again — the old one no longer works.'));
    }

    #[Computed]
    public function tunnelReady(): bool
    {
        return config('wireguard.enabled') === true && ! blank(config('wireguard.public_key'));
    }

    /**
     * Tell this server about the router, without anyone opening a terminal.
     */
    public function syncPeer(WireGuardPeerSync $sync): void
    {
        Gate::authorize(Permissions::ROUTERS_PROVISION);

        $result = $sync->sync($this->router);

        Flux::toast(
            variant: $result['ok'] ? 'success' : 'warning',
            text: $result['message'],
        );
    }

    /**
     * Try the router down the tunnel and say what happened.
     *
     * The same probe the router page runs. Put here because this is where an operator is
     * standing when they want to know whether what they just pasted worked.
     */
    public function testConnection(RouterHealthProbe $probe): void
    {
        Gate::authorize('command', $this->router);

        $router = $this->router;

        $probe->probe($router);

        unset($this->router, $this->script);

        $router->status->isReachable()
            ? Flux::toast(variant: 'success', text: __('Connected to :name. The tunnel is up.', ['name' => $router->identity ?? $router->name]))
            : Flux::toast(variant: 'danger', text: $router->last_error ?? __('Still cannot reach it.'));
    }

    /**
     * Where this router is in being set up, as the operator should see it after pasting.
     *
     * Every step is something the platform recorded, not something the operator has to go
     * and find out: the router downloading its configuration, the router answering down the
     * tunnel, the HotSpot confirmed or built. A step that takes far longer than it should
     * says what to look at.
     *
     * @return array{active: bool, steps: array<int, array{label: string, state: string, text: string}>}
     */
    #[Computed]
    public function progress(): array
    {
        $router = $this->router;
        $downloaded = $router->config_downloaded_at;
        $withHotspot = ($router->setupOptions()['with_hotspot'] ?? true) === true;
        $online = $downloaded !== null
            && $router->status === App\Enums\RouterStatus::Online
            && $router->reachedOverTunnel()
            && $router->last_seen_at?->greaterThanOrEqualTo($downloaded);
        $since = $downloaded?->diffInSeconds(now()) ?? 0;
        $run = Illuminate\Support\Facades\Cache::get(App\Jobs\SetUpHotspot::cacheKey($router->id));
        $hotspotFailed = ($run['status'] ?? null) === 'done' && collect($run['report'] ?? [])->contains('status', 'failed');

        $steps = [[
            'label' => __('Router downloaded its configuration'),
            'state' => $downloaded !== null ? 'done' : 'waiting',
            'text' => $downloaded !== null
                ? __(':when. It wiped itself and is rebuilding.', ['when' => $downloaded->diffForHumans()])
                : __('Paste the script into the router\'s terminal. This ticks the moment it downloads its setup.'),
        ], [
            'label' => __('Router is online through the tunnel'),
            'state' => $online ? 'done' : ($downloaded === null ? 'pending' : ($since > 240 ? 'stuck' : 'waiting')),
            'text' => match (true) {
                $online => __('The platform reaches it at :address.', ['address' => $router->wg_address]),
                $downloaded === null => __('After the download.'),
                $since > 240 => __('Not back after 4 minutes. Check that ether1 still has internet, then connect in Winbox by MAC address and look at Log for lines starting "platform-setup".'),
                default => __('Rebooting and connecting — usually about two minutes.'),
            },
        ]];

        if ($withHotspot) {
            $steps[] = [
                'label' => __('HotSpot ready'),
                'state' => $router->hotspot_ready_at !== null ? 'done' : (! $online ? 'pending' : ($hotspotFailed ? 'stuck' : 'waiting')),
                'text' => match (true) {
                    $router->hotspot_ready_at !== null => __('Customers who join the network are sent to the payment page.'),
                    ! $online => __('Once the router is online.'),
                    $hotspotFailed => __('It could not be finished automatically. Open HotSpot setup to see which step failed.'),
                    default => __('Checking, and finishing it over the tunnel if the first boot could not.'),
                },
            ];
        }

        $complete = collect($steps)->every(fn (array $step): bool => $step['state'] === 'done');

        return [
            'active' => $downloaded !== null && ! $complete && $since < 1800,
            'steps' => $steps,
        ];
    }

    /**
     * Look at the router now, while somebody is watching the progress — at most every
     * fifteen seconds, whatever the page's poll does.
     */
    public function checkProgress(App\Services\RouterOs\Outages\RouterWatcher $watcher): void
    {
        Gate::authorize('view', $this->router);

        if (Illuminate\Support\Facades\Cache::add('setup-watch:'.$this->routerId, true, now()->addSeconds(15))) {
            try {
                $watcher->watch($this->router);
            } catch (Throwable) {
                // The progress list reports what is known; one failed look is not news.
            }
        }

        unset($this->router, $this->progress);
    }

    /**
     * Throw the keys and the password away and issue new ones.
     */
    public function rotate(TunnelAllocator $allocator, AuditLogger $audit): void
    {
        Gate::authorize(Permissions::ROUTERS_PROVISION);

        $router = $this->router;

        $allocator->rotate($router);

        // A fresh API password too: the old script carried both, so revoking half of it would
        // leave the other half working.
        app(RouterSetupScript::class)->for($router, [
            'lan_interface' => $this->lanInterface,
            'with_hotspot' => $this->withHotspot,
            'rotate_api_password' => true,
        ]);

        $audit->record(
            event: 'router.credentials_rotated',
            auditable: $router,
            description: sprintf('Regenerated the tunnel key and API password for %s', $router->name),
        );

        unset($this->router, $this->script);

        Flux::toast(
            variant: 'warning',
            text: __('New keys issued. The router is unreachable until you paste the new script into it.'),
        );
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Set up :router', ['router' => $this->router->name]) }}</flux:heading>
                <flux:subheading>
                    {{ __('Paste this into the router once. It needs no public address and works behind NAT.') }}
                </flux:subheading>
            </div>

            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('routers.show', $this->router)" wire:navigate>
                {{ __('Back to router') }}
            </flux:button>
        </div>

        @unless ($this->tunnelReady)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('The management tunnel is not configured yet') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Set WIREGUARD_PUBLIC_KEY and WIREGUARD_ENDPOINT on the server. Until then the script below will be generated with an empty server key and the router will never complete a handshake.') }}
                </flux:callout.text>
            </flux:callout>
        @endunless

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:card class="flex flex-col gap-1">
                <flux:text size="sm" class="opacity-70">{{ __('Tunnel address') }}</flux:text>
                <flux:heading size="lg" class="font-mono">{{ $this->router->wg_address ?? '—' }}</flux:heading>
                <flux:text size="sm" class="opacity-70">{{ __('How the platform reaches it') }}</flux:text>
            </flux:card>

            <flux:card class="flex flex-col gap-1">
                <flux:text size="sm" class="opacity-70">{{ __('Dials out to') }}</flux:text>
                <flux:heading size="lg" class="font-mono text-sm">
                    {{ config('wireguard.endpoint') }}:{{ config('wireguard.listen_port') }}
                </flux:heading>
                <flux:text size="sm" class="opacity-70">{{ __('UDP, outbound only') }}</flux:text>
            </flux:card>

            <flux:card class="flex flex-col gap-1">
                <flux:text size="sm" class="opacity-70">{{ __('Tunnel last confirmed') }}</flux:text>
                <flux:heading size="lg">{{ $this->router->wg_configured_at?->diffForHumans() ?? __('Never') }}</flux:heading>
                <flux:text size="sm" class="opacity-70">{{ __('Set when the platform first reaches it') }}</flux:text>
            </flux:card>
        </div>

        <flux:card class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Before you paste') }}</flux:heading>
                <flux:subheading>
                    {{ __('Only one of these matters, and getting it wrong takes the router off the internet.') }}
                </flux:subheading>
            </div>

            <flux:radio.group wire:model.live="wipe" :label="__('What should the script do to what is already on the router?')" variant="cards" class="flex-col">
                <flux:radio
                    :value="false"
                    :label="__('Add to it')"
                    :description="__('Leaves the existing configuration alone and adds the tunnel, the API user and the HotSpot alongside it. Safe on a router already carrying your own setup.')"
                />
                <flux:radio
                    :value="true"
                    :label="__('Wipe it and build from nothing')"
                    :description="__('Erases everything, including the MikroTik defaults, and rebuilds the router from scratch. Right for a device straight out of the box.')"
                />
            </flux:radio.group>

            @if ($wipe)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('This erases the router') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('There is no undo and no backup is kept. The router reboots, comes back with nothing, and rebuilds itself from a file it downloads first.') }}
                    </flux:callout.text>
                    <flux:callout.text>
                        {{ __('Get the two interfaces below right. If the uplink is named wrong the router comes back with no way to reach the internet, and the only fix is to walk to it with a cable. Check them in /interface print on the device first.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input
                    wire:model.live.debounce.500ms="lanInterface"
                    :label="__('Interface customers connect to')"
                    :description="__('The port they plug into, or the wireless interface. Never the uplink.')"
                    placeholder="ether3"
                />

                @if ($wipe)
                    <flux:input
                        wire:model.live.debounce.500ms="wanInterface"
                        :label="__('Interface the internet comes in on')"
                        :description="__('The uplink. After a wipe the router has no DHCP client until this is configured.')"
                        placeholder="ether1"
                    />
                @else
                    <flux:switch
                        wire:model.live="withHotspot"
                        :label="__('Set up the HotSpot too')"
                        :description="__('Turn off for a router whose network you have already built. The tunnel and API are still configured.')"
                    />
                @endif
            </div>

            @if ($wipe)
                <flux:radio.group wire:model.live="wanMode" :label="__('How does :port get its internet?', ['port' => $wanInterface ?: 'ether1'])" variant="segmented">
                    <flux:radio value="dhcp" :label="__('DHCP')" />
                    <flux:radio value="static" :label="__('Static address')" />
                    <flux:radio value="pppoe" :label="__('PPPoE')" />
                </flux:radio.group>

                @if ($wanMode === 'static')
                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:input wire:model.live.debounce.500ms="wanAddress" :label="__('Address')" placeholder="196.201.1.10/29" :description="__('With its prefix.')" />
                        <flux:input wire:model.live.debounce.500ms="wanGateway" :label="__('Gateway')" placeholder="196.201.1.9" />
                        <flux:input wire:model.live.debounce.500ms="wanDns" :label="__('DNS servers')" placeholder="1.1.1.1,8.8.8.8" :description="__('Optional.')" />
                    </div>
                @elseif ($wanMode === 'pppoe')
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input wire:model.live.debounce.500ms="wanPppoeUser" :label="__('PPPoE username')" :description="__('From your internet provider.')" />
                        <flux:input
                            wire:model.blur="wanPppoePassword"
                            type="password"
                            :label="__('PPPoE password')"
                            :description="$this->router->wan_pppoe_password ? __('Stored. Type a new one only to change it.') : __('Stored encrypted, never shown again.')"
                        />
                    </div>
                @endif

                <flux:switch
                    wire:model.live="withHotspot"
                    :label="__('Set up the HotSpot too')"
                    :description="__('Turn off if you would rather build the customer network yourself afterwards.')"
                />
            @endif
        </flux:card>

        {{-- The wifi ---------------------------------------------------------- --}}
        @if ($withHotspot)
            <flux:card class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('The wifi customers connect to') }}</flux:heading>
                    <flux:subheading>
                        {{ __('A HotSpot with no wireless network is one nobody can find — there is nothing on their phone to tap.') }}
                    </flux:subheading>
                </div>

                <flux:switch
                    wire:model.live="withWireless"
                    :label="__('Create the wifi network')"
                    :description="__('Turn off for a router whose wireless is handled by separate access points.')"
                />

                @if ($withWireless)
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:input
                            wire:model.live.debounce.500ms="ssid"
                            :label="__('Network name (SSID)')"
                            :placeholder="$this->defaultSsid"
                            :description="__('What appears in the wifi list. Leave blank to use your own name.')"
                        />

                        <flux:input
                            wire:model.live.debounce.500ms="wirelessInterface"
                            :label="__('Wireless interface')"
                            placeholder="wlan1"
                            :description="__('On boards with the newer wifi package this is detected automatically.')"
                        />
                    </div>

                    <flux:callout icon="information-circle">
                        <flux:callout.text>
                            {{ __('The network is created open, with no wifi password. The HotSpot is what checks who someone is — a password would turn a customer away before they could buy anything.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif
            </flux:card>
        @endif

        {{-- PPPoE ------------------------------------------------------------- --}}
        <flux:card class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('PPPoE for fixed lines') }}</flux:heading>
                <flux:subheading>
                    {{ __('Without a server here, the credentials this platform writes are a list nothing ever asks for.') }}
                </flux:subheading>
            </div>

            <flux:switch
                wire:model.live="withPppoe"
                :label="__('Create a PPPoE server')"
                :description="__('For subscribers on a cable rather than the wifi.')"
            />

            @if ($withPppoe)
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input
                        wire:model.live.debounce.500ms="pppoeInterface"
                        :label="__('Interface for subscriber lines')"
                        placeholder="ether4"
                        :description="__('Its own port. A HotSpot and a PPPoE server on one segment both answer the same customer.')"
                    />

                    <flux:input
                        wire:model.live.debounce.500ms="pppoeService"
                        :label="__('Service name')"
                        placeholder="isp"
                        :description="__('What subscriber routers look for. Most accept anything.')"
                    />
                </div>

                @if ($withPppoe && $withHotspot && $pppoeInterface === $lanInterface)
                    <flux:callout variant="warning" icon="exclamation-triangle">
                        <flux:callout.text>
                            {{ __('That is the same port the HotSpot uses. Both will try to answer the same customer, and a subscriber on a fixed line will find themselves at a captive portal.') }}
                        </flux:callout.text>
                    </flux:callout>
                @endif
            @endif
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <flux:heading size="lg">{{ __('The script') }}</flux:heading>
                    <flux:subheading>{{ __('Winbox → New Terminal, or SSH. Safe to run more than once.') }}</flux:subheading>
                </div>

                {{-- Copying is the whole point of this page, so it does not depend on a
                     round trip to the server to work. --}}
                <div x-data="{ copied: false }">
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="clipboard-document"
                        x-on:click="
                            $refs.script && navigator.clipboard.writeText($refs.script.textContent).then(() => {
                                copied = true;
                                setTimeout(() => copied = false, 2000);
                            })
                        "
                    >
                        <span x-show="! copied">{{ __('Copy script') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </flux:button>
                </div>
            </div>

            <flux:callout variant="warning" icon="key">
                <flux:callout.text>
                    {{ __('This carries the router\'s private key and its API password. Treat it like a password — do not forward it, and rotate below if it goes astray.') }}
                </flux:callout.text>
            </flux:callout>

            {{-- The wipe script depends on a one-time link, and a spent one fails on the
                 router with a bare 404 — at the point where somebody is standing over a
                 device they are about to erase. --}}
            @if ($wipe)
                <flux:callout :variant="$this->linkState['ok'] ? 'secondary' : 'danger'" icon="link">
                    <flux:callout.text>{{ $this->linkState['text'] }}</flux:callout.text>
                    @unless ($this->linkState['ok'])
                        <x-slot name="actions">
                            <flux:button size="sm" wire:click="newLink">{{ __('New link') }}</flux:button>
                        </x-slot>
                    @endunless
                </flux:callout>
            @endif

            @php($script = $this->script)

            @if ($this->scriptError() !== null)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.heading>{{ __('No script until the uplink is complete') }}</flux:callout.heading>
                    <flux:callout.text>{{ $this->scriptError() }}</flux:callout.text>
                </flux:callout>
            @else
                <pre x-ref="script" class="max-h-[32rem] overflow-auto rounded-lg bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-100 dark:bg-black"><code>{{ $script }}</code></pre>
            @endif
        </flux:card>

        @if ($this->router->admin_password)
            <flux:card class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Administrator login') }}</flux:heading>
                    <flux:subheading>
                        {{ __('A wiped router disables the stock admin account and is rebuilt with this one. Use it in Winbox or SSH from the router\'s LAN when the platform cannot reach it. It is not reachable from the internet.') }}
                    </flux:subheading>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <flux:text size="sm" class="opacity-70">{{ __('Username') }}</flux:text>
                        <flux:text class="font-mono">{{ $this->router->admin_username }}</flux:text>
                    </div>
                    <div>
                        <flux:text size="sm" class="opacity-70">{{ __('Password') }}</flux:text>
                        @if ($showAdminPassword)
                            <flux:text class="font-mono select-all">{{ $this->router->admin_password }}</flux:text>
                        @else
                            <flux:button size="sm" icon="eye" wire:click="revealAdminPassword">{{ __('Show password') }}</flux:button>
                        @endif
                    </div>
                </div>
            </flux:card>
        @endif

        @php($progress = $this->progress)

        {{-- The poll sits on a plain element, as on the HotSpot page: Blade cannot compile an
             @if inside a Flux component's opening tag. --}}
        <div @if ($progress['active']) wire:poll.5s="checkProgress" @endif>
            <flux:card class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Progress') }}</flux:heading>
                    <flux:subheading>{{ __('Follows the router by itself after you paste. Nothing to press.') }}</flux:subheading>
                </div>

                <ol class="flex flex-col gap-3">
                    @foreach ($progress['steps'] as $step)
                        <li class="flex items-start gap-3">
                            @if ($step['state'] === 'done')
                                <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-green-600" />
                            @elseif ($step['state'] === 'stuck')
                                <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                            @elseif ($step['state'] === 'waiting')
                                <flux:icon.arrow-path class="mt-0.5 size-5 shrink-0 animate-spin text-blue-600" />
                            @else
                                <div class="mt-0.5 size-5 shrink-0 rounded-full border-2 border-zinc-300 dark:border-zinc-600"></div>
                            @endif
                            <div>
                                <flux:text class="font-medium">{{ $step['label'] }}</flux:text>
                                <flux:text size="sm" class="opacity-70">{{ $step['text'] }}</flux:text>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </flux:card>
        </div>

        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('After it runs') }}</flux:heading>
                <flux:subheading>{{ __('Nothing here needs the server: the platform does its half by itself.') }}</flux:subheading>
            </div>

            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="min-w-0 flex-1">
                        <flux:heading size="sm">{{ __('1. The platform opens its side of the tunnel') }}</flux:heading>
                        <flux:text size="sm" class="opacity-70">
                            {{ $this->peerSyncIsAutomatic
                                ? __('Done automatically when this router was given its keys, and again when it downloads its setup. Press to do it again now.')
                                : __('Automatic tunnel setup is not switched on for this platform yet. The platform administrator needs to finish it; there is nothing for you to run.') }}
                        </flux:text>
                    </div>

                    @if ($this->peerSyncIsAutomatic)
                        <flux:button size="sm" icon="arrow-path" wire:click="syncPeer" wire:loading.attr="disabled" wire:target="syncPeer">
                            <span wire:loading.remove wire:target="syncPeer">{{ __('Sync tunnel') }}</span>
                            <span wire:loading wire:target="syncPeer">{{ __('Syncing…') }}</span>
                        </flux:button>
                    @else
                        <flux:badge color="amber" size="sm">{{ __('Not switched on') }}</flux:badge>
                    @endif
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="min-w-0 flex-1">
                        <flux:heading size="sm">{{ __('2. Check the platform can reach it') }}</flux:heading>
                        <flux:text size="sm" class="opacity-70">
                            {{ __('A wiped router takes a minute or two to reboot before this will answer.') }}
                        </flux:text>
                    </div>

                    <flux:button size="sm" variant="primary" wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">
                        <span wire:loading.remove wire:target="testConnection">{{ __('Test connection') }}</span>
                        <span wire:loading wire:target="testConnection">{{ __('Connecting…') }}</span>
                    </flux:button>
                </div>
            </div>

            {{-- Server instructions are for whoever runs the platform, never for an ISP: they have no
                 access to the server and should not be shown how it is administered. --}}
            @if (! $this->peerSyncIsAutomatic && auth()->user()->isSuperAdmin())
                <flux:callout variant="warning" icon="command-line">
                    <flux:callout.text>
                        {{ __('Platform admin: adding a peer needs root. Run deploy/install-wg-sync.sh once on the server and set WIREGUARD_SYNC_COMMAND; tunnels are then opened automatically for every ISP.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            <flux:text size="sm" class="opacity-70">
                {{ __('On the router itself, /interface/wireguard/peers print detail shows a recent last-handshake once it has reached us.') }}
            </flux:text>

            <flux:separator />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <flux:heading size="sm">{{ __('Regenerate the keys') }}</flux:heading>
                    <flux:text size="sm" class="opacity-70">
                        {{ __('For a script that leaked. The router goes unreachable until you paste the new one in.') }}
                    </flux:text>
                </div>

                <flux:button size="sm" variant="danger" wire:click="rotate" wire:confirm="{{ __('This will lock the platform out of this router until you paste the new script into it. Continue?') }}">
                    {{ __('Rotate keys') }}
                </flux:button>
            </div>
        </flux:card>
    </div>
</section>
