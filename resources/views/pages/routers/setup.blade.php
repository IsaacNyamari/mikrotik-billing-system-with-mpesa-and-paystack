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

    public bool $withHotspot = true;

    /** Whether the script erases the router first. Never the default. */
    public bool $wipe = false;

    public function mount(Router $router): void
    {
        Gate::authorize(Permissions::ROUTERS_PROVISION);

        $this->routerId = $router->id;

        // What was chosen when this router was added, not the platform default — otherwise
        // an operator returning to this page is handed a script that bridges the wrong port.
        $options = $router->setupOptions();

        $this->lanInterface = (string) $options['lan_interface'];
        $this->wanInterface = (string) ($options['wan_interface'] ?? 'ether1');
        $this->withHotspot = (bool) ($options['with_hotspot'] ?? true);
        $this->wipe = (bool) ($options['wipe'] ?? false);
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
        return app(RouterSetupScript::class)->for($this->router, [
            'lan_interface' => $this->lanInterface,
            'wan_interface' => $this->wanInterface,
            'with_hotspot' => $this->withHotspot,
            'wipe' => $this->wipe,
            // The password already on the router stays valid until the operator deliberately
            // rotates it, so opening this page does not silently lock out a working device.
            'api_password' => $this->router->api_password,
        ]);
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
                <flux:switch
                    wire:model.live="withHotspot"
                    :label="__('Set up the HotSpot too')"
                    :description="__('Turn off if you would rather build the customer network yourself afterwards.')"
                />
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
                            navigator.clipboard.writeText($refs.script.textContent).then(() => {
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

            <pre x-ref="script" class="max-h-[32rem] overflow-auto rounded-lg bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-100 dark:bg-black"><code>{{ $this->script }}</code></pre>
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('After it runs') }}</flux:heading>
                <flux:subheading>{{ __('Both of these happen here — you should not need a terminal on the server.') }}</flux:subheading>
            </div>

            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="min-w-0 flex-1">
                        <flux:heading size="sm">{{ __('1. Tell this server about the router') }}</flux:heading>
                        <flux:text size="sm" class="opacity-70">
                            {{ __('WireGuard is mutual — the router knowing about us is only half of it.') }}
                        </flux:text>
                    </div>

                    @if ($this->peerSyncIsAutomatic)
                        <flux:button size="sm" wire:click="syncPeer" wire:loading.attr="disabled" wire:target="syncPeer">
                            <span wire:loading.remove wire:target="syncPeer">{{ __('Add peer') }}</span>
                            <span wire:loading wire:target="syncPeer">{{ __('Adding…') }}</span>
                        </flux:button>
                    @else
                        <flux:text size="sm" class="font-mono opacity-70">php artisan wireguard:peers --apply</flux:text>
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

            @unless ($this->peerSyncIsAutomatic)
                <flux:callout variant="warning" icon="command-line">
                    <flux:callout.text>
                        {{ __('Adding a peer changes a live tunnel, which needs root — the web user deliberately does not have it. Run deploy/install-wg-sync.sh once on the server to allow this one command, and the button appears.') }}
                    </flux:callout.text>
                </flux:callout>
            @endunless

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
