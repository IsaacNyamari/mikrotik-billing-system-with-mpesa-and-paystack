<?php

use App\Models\Router;
use App\Services\Provisioning\RouterSetupScript;
use App\Services\Provisioning\TunnelAllocator;
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

    public bool $withHotspot = true;

    public function mount(Router $router): void
    {
        Gate::authorize(Permissions::ROUTERS_PROVISION);

        $this->routerId = $router->id;
        $this->lanInterface = (string) config('wireguard.defaults.lan_interface');
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
            'with_hotspot' => $this->withHotspot,
            // The password already on the router stays valid until the operator deliberately
            // rotates it, so opening this page does not silently lock out a working device.
            'api_password' => $this->router->api_password,
        ]);
    }

    #[Computed]
    public function tunnelReady(): bool
    {
        return config('wireguard.enabled') === true && ! blank(config('wireguard.public_key'));
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

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input
                    wire:model.live.debounce.500ms="lanInterface"
                    :label="__('Interface customers connect to')"
                    :description="__('The port they plug into, or the wireless interface. Never the uplink.')"
                    placeholder="ether3"
                />

                <flux:switch
                    wire:model.live="withHotspot"
                    :label="__('Set up the HotSpot too')"
                    :description="__('Turn off for a router whose network you have already built. The tunnel and API are still configured.')"
                />
            </div>
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

            <pre x-ref="script" class="max-h-[32rem] overflow-auto rounded-lg bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-100 dark:bg-black"><code>{{ $this->script }}</code></pre>
        </flux:card>

        <flux:card class="flex flex-col gap-4">
            <div>
                <flux:heading size="lg">{{ __('After it runs') }}</flux:heading>
            </div>

            <ol class="ml-5 list-decimal space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
                <li>{{ __('On the router, check the tunnel: /interface/wireguard/peers print detail — a recent last-handshake means it reached us.') }}</li>
                <li>{{ __('Add the matching peer on the management server: php artisan wireguard:peers') }}</li>
                <li>{{ __('Then test from here: php artisan router:test :slug', ['slug' => $this->router->slug]) }}</li>
            </ol>

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
