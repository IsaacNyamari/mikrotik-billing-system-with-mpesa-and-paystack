{{--
    Chapter 2. Adding a router.

    The single riskiest thing an operator does on this platform, because the wipe option is
    genuinely destructive and getting WAN and LAN the wrong way round strands a device that then
    has to be reached with a cable. Said plainly here, and said again in the script itself.
--}}

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>
        {{ __('A router behind somebody\'s broadband cannot be dialled from the internet, so it cannot be configured over the API — there is no API to reach yet. The way in is a script generated here and pasted into the device\'s own terminal. It is the only channel that always works, which is why it does everything at once: the tunnel, the API user, the firewall, the wifi, the HotSpot and the login pages.') }}
    </p>

    <flux:heading size="sm">{{ __('What to have ready') }}</flux:heading>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('Physical or Winbox access to the router. You will paste into its terminal.') }}</li>
        <li>{{ __('Which port its internet arrives on. Usually ether1.') }}</li>
        <li>{{ __('Which port or radio your customers connect to. Usually ether3 and wlan1.') }}</li>
        <li>{{ __('The router already online, so it can download its login pages while the script runs.') }}</li>
    </ul>

    <flux:callout variant="warning" icon="exclamation-triangle">
        <flux:callout.heading>{{ __('Check the two interface names before you paste') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Putting the internet port into the customer bridge takes the router off the internet mid-script, and it takes your own session with it. Run /interface print on the device first and be certain. If it happens anyway, the fix is a cable and a walk.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('Two kinds of script') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:badge color="zinc" size="sm">{{ __('Default') }}</flux:badge>
            <flux:heading size="sm" class="mt-2">{{ __('Add to what is there') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Builds the platform\'s pieces alongside whatever the router already has. Right for a device already carrying your own configuration. Safe to run more than once — pasting it twice leaves one of each thing, not two.') }}
            </flux:text>
        </div>

        <div class="rounded-lg border border-amber-300 p-4 dark:border-amber-700">
            <flux:badge color="amber" size="sm">{{ __('Destructive') }}</flux:badge>
            <flux:heading size="sm" class="mt-2">{{ __('Wipe and rebuild') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Erases the router completely and builds it again from nothing. Right for a device straight out of its box. There is no undo and no backup kept. The router downloads its configuration first, then resets and reboots — give it two minutes before you expect it back.') }}
            </flux:text>
        </div>
    </div>

    <flux:heading size="sm">{{ __('What the script does, in order') }}</flux:heading>

    <p>{{ __('The order is a security property, not a preference. Each step is only safe once the one before it has happened.') }}</p>

    <ol class="flex flex-col gap-2 ps-5" style="list-style: decimal">
        <li>{{ __('Brings up the management tunnel, so the platform can see the device at all.') }}</li>
        <li>{{ __('Creates an API user restricted to the tunnel, and firewalls the API off every other interface — before enabling it. Opening the API first, even briefly, advertises an admin service to the internet.') }}</li>
        <li>{{ __('Builds the bridge, the address pool, DHCP and the HotSpot itself.') }}</li>
        <li>{{ __('Opens the walled garden, so a customer who has paid nothing can still reach the page where they pay.') }}</li>
        <li>{{ __('Downloads the login pages, which hand the customer to your portal instead of asking for a username they have never been given.') }}</li>
        <li>{{ __('Switches on the wifi, open, because the HotSpot is what checks who somebody is and a wifi password would turn a customer away before they could buy anything.') }}</li>
    </ol>

    <flux:callout icon="key">
        <flux:callout.heading>{{ __('The script is a password') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('It contains that router\'s private key and API password. Do not forward it, and if it goes astray generate another — doing so changes the password, and the old script stops working.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('Afterwards') }}</flux:heading>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('The router\'s page here should show it online within a minute or so. If it does not, the tunnel is the first thing to look at.') }}</li>
        <li>{{ __('Join the wifi with a phone. You should land on your portal without typing anything.') }}</li>
        <li>{{ __('Your packages reach the device the first time somebody buys one, or immediately if you push them by hand from the plan\'s page.') }}</li>
    </ul>

    @if ($this->routers->isNotEmpty())
        <flux:separator />

        <flux:heading size="sm">{{ __('Your routers') }}</flux:heading>

        <div class="flex flex-wrap gap-2">
            @foreach ($this->routers as $router)
                <flux:button
                    :href="route('routers.show', $router)"
                    wire:navigate
                    size="sm"
                    variant="ghost"
                    icon="server"
                >{{ $router->name }}</flux:button>
            @endforeach
        </div>
    @endif
</div>
