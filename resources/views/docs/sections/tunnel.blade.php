{{--
    Chapter 3. The management tunnel.

    This is the chapter operators reach when the platform "feels slow", and the honest answer is
    almost never bandwidth — it is a tunnel that had gone to sleep and has to be rebuilt while
    somebody waits. So the settings that keep it awake are documented as the load-bearing things
    they are, rather than left as numbers in a config file.
--}}

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>
        {{ __('Your routers sit on other people\'s connections: behind NAT, behind CGNAT, on dynamic addresses, sometimes on a 4G dongle. None of them can be dialled from here, and forwarding a port at every site would be both a security problem and a support burden. So the router dials out to us instead, over WireGuard, and the platform speaks to it down that tunnel.') }}
    </p>

    <div class="overflow-x-auto">
        <div class="flex min-w-max items-center gap-3 text-xs">
            <span class="rounded-md border border-zinc-200 px-3 py-2 dark:border-zinc-700">{{ __('This platform') }}<br><span class="text-zinc-500">10.8.0.1</span></span>
            <flux:icon.arrows-right-left variant="micro" class="shrink-0 text-zinc-400" />
            <span class="rounded-md border border-zinc-200 px-3 py-2 dark:border-zinc-700">{{ __('WireGuard, UDP 51820') }}</span>
            <flux:icon.arrows-right-left variant="micro" class="shrink-0 text-zinc-400" />
            <span class="rounded-md border border-zinc-200 px-3 py-2 dark:border-zinc-700">{{ __('Your router') }}<br><span class="text-zinc-500">{{ $this->exampleRouter?->wg_address ?? '10.8.0.x' }}</span></span>
        </div>
    </div>

    <flux:callout icon="shield-check">
        <flux:callout.heading>{{ __('Management only') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('The tunnel carries API commands and nothing else. No customer traffic goes through it, there is no forwarding and no NAT on it, and it never becomes an accidental transit provider for your subscribers. Your customers\' internet does not touch it and is not affected by its speed.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('Why it is fast, and what keeps it that way') }}</flux:heading>

    <p>
        {{ __('What an operator or a paying customer experiences as slowness is almost never throughput — the commands involved are tens of bytes. It is the delay before the first one gets through. Four things are set up to remove that delay, and all four are already in place on a router set up by the current script:') }}
    </p>

    <div class="flex flex-col gap-3">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('The tunnel is never allowed to fall asleep') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('A NAT mapping with no traffic through it commonly disappears in about thirty seconds, and WireGuard itself changes keys after two minutes of silence. The router pokes the tunnel every fifteen seconds, which is inside both, so it is already up at the moment somebody pays rather than being rebuilt while they wait. It costs about six megabytes a month.') }}
            </flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('One connection serves the whole job') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Every conversation with a router costs a handshake and a login. Issuing a customer\'s login used to pay for two of them — one to check the package profile, one to write the user. They now share a single connection, so the second costs nothing.') }}
            </flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Work that has already been done is not repeated') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('A package\'s settings are pushed to the router when you create or change it, not on every sale. Somebody buying the same hourly bundle as the last twenty customers waits for their own login to be written and nothing else.') }}
            </flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Packets are sized to survive the journey') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('The tunnel uses a smaller packet size than the default. On a PPPoE line or a 4G dongle the default is slightly too large, and a network that quietly swallows the "too big" reply turns that into a command that hangs rather than one that fails — by far the worst way for this to go wrong. Management traffic is also kept out of any queue you set up to shape subscribers, so a provisioning command is never stuck behind somebody\'s download.') }}
            </flux:text>
        </div>
    </div>

    <flux:separator />

    <flux:heading size="sm">{{ __('Checking a tunnel') }}</flux:heading>

    <p>{{ __('On the router\'s own terminal:') }}</p>

    <pre class="overflow-x-auto rounded-lg bg-zinc-100 p-3 font-mono text-xs dark:bg-zinc-900">/interface/wireguard/peers print detail</pre>

    <p>
        {{ __('A recent last-handshake means the router has reached us. If it stays empty, the router cannot get out to UDP port 51820 — that is the one thing this arrangement still needs from the network the router sits on. A restrictive corporate firewall or a mobile network blocking outbound UDP are the usual causes.') }}
    </p>

    <flux:callout variant="warning" icon="exclamation-triangle">
        <flux:callout.heading>{{ __('A router that never gets a handshake is not broken') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('It is behind something that will not let it out. Nothing you change on this platform fixes that; the change has to happen on the connection the router is sitting on.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('If the platform cannot reach a router') }}</flux:heading>

    <p>
        {{ __('Nothing is lost. Payments still settle, packages are still recorded, and a background job re-pushes anything that could not be written, once a minute, until the router answers. Customers waiting on the portal have their access retried automatically. What you should not do is ask anyone to pay again.') }}
    </p>
</div>
