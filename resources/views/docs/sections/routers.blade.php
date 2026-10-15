{{--
    Chapter 2. Adding a router.

    Written from what actually goes wrong in front of a real MikroTik: the cable in the wrong
    port, the factory password, a laptop on the wrong side of the router. Each of those cost a
    real operator an afternoon before this chapter existed. The happy path is short on purpose;
    the rest is the part people come back to read when the router is not where they expect.
--}}

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>
        {{ __('A router behind somebody\'s broadband cannot be dialled from the internet, so the platform cannot configure it until the router calls in. The way in is one script, generated here and pasted into the router\'s own terminal. It wipes the router, rebuilds it from nothing and connects it to the platform. From then on everything is done from this dashboard.') }}
    </p>

    <flux:heading size="sm">{{ __('Wire it up first') }}</flux:heading>

    <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left">
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                <tr><td class="px-4 py-3 font-semibold">ether1</td><td class="px-4 py-3">{{ __('Your internet: the cable from your modem or upstream router. With a PoE injector, its data/LAN port goes to the modem and its PoE port to ether1.') }}</td></tr>
                <tr><td class="px-4 py-3 font-semibold">ether3 + wifi</td><td class="px-4 py-3">{{ __('Your customers. The HotSpot covers both.') }}</td></tr>
                <tr><td class="px-4 py-3 font-semibold">ether2, 4, 5</td><td class="px-4 py-3">{{ __('Your laptop, when you need to reach the router by hand. Plug in and the router gives you an address; Winbox answers at 192.168.88.1.') }}</td></tr>
            </tbody>
        </table>
    </div>

    <flux:heading size="sm">{{ __('Add it in three steps') }}</flux:heading>

    <ol class="flex list-decimal flex-col gap-2 ps-5">
        <li>{{ __('Routers → Add router and give it a name. You land on its setup page: check the internet port (ether1), the customer port (ether3) and the wifi name.') }}</li>
        <li>{{ __('Copy the script and paste it into the router\'s terminal: Winbox → New Terminal, or WebFig → Terminal, logged in as admin.') }}</li>
        <li>{{ __('Watch Progress on the setup page. It ticks through download, online and HotSpot ready by itself, in about two minutes. Nothing else to press.') }}</li>
    </ol>

    <flux:callout icon="shield-check">
        <flux:callout.heading>{{ __('The script checks before it changes anything') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('It first makes sure ether1 has a cable, the router has internet and it can reach this platform. If any of that is missing it says exactly what, and stops — "Nothing has been changed." Fix that one thing and paste again.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('After it runs') }}</flux:heading>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('The stock admin account is switched off. You log in as isp-admin; its password is on the setup page under Administrator login → Show password.') }}</li>
        <li>{{ __('Winbox finds the router by MAC address on every port, including from your own network through ether1. By IP, from ether2, 4 or 5 at 192.168.88.1.') }}</li>
        <li>{{ __('Join the wifi with a phone: the payment page opens by itself.') }}</li>
        <li>{{ __('If the HotSpot could not be built on the router\'s first boot, the platform finishes it over the tunnel as soon as the router is online. You will see it tick on the setup page.') }}</li>
    </ul>

    <flux:callout icon="key">
        <flux:callout.heading>{{ __('The script is a password') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('It carries that router\'s keys and a one-time download link. Do not forward it. The link works once and expires after 30 minutes; reload the setup page for a fresh one.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:separator />

    <flux:heading size="sm">{{ __('When you cannot get into the router') }}</flux:heading>

    <p>{{ __('Before the script can run you have to be logged in to the router. These are the ways that fails, in the order they usually happen.') }}</p>

    <div class="flex flex-col gap-3">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('It shows in Winbox for a moment, then disappears') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Your laptop is reaching it through ether1. MikroTik\'s own default setup hides Winbox on ether1, because ether1 is the internet side. Plug the laptop into ether2 instead, or join the router\'s wifi.') }}</flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('admin with no password is refused') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Newer MikroTik routers ship with their own password, printed on the sticker underneath. Type it by hand — 0 and O, 1 and l are easy to mix up — and use a private browser window so a saved password is not filled in for you.') }}</flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Nothing works, or it was set up somewhere else before') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Reset it: unplug the power, hold the reset button, plug the power back in and let go the moment the ACT light starts flashing (about 5 seconds). When the router\'s wifi appears as MikroTik-xxxxxx it has its factory setup again. Holding much longer puts it in other modes; just do it again.') }}</flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Even the reset does not get you in') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Netinstall reinstalls RouterOS and clears every password. Download Netinstall and the RouterOS package for your router\'s architecture from mikrotik.com. Connect your computer to ether1, start Netinstall as administrator with Net booting enabled and a client address in your own network, then power the router on holding reset for 15–20 seconds until it appears in the list. Select it and the packages, tick Apply default config, and Install. Afterwards log in as admin on ether2.') }}</flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('The script says ether1 has no cable link') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The cable from your modem is not in ether1 — or, with a PoE injector, its two ports are the wrong way round. The data/LAN port goes to the modem, the PoE port to the router.') }}</flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Progress stops at "online"') }}</flux:heading>
            <flux:text class="mt-1">{{ __('The router rebuilt itself but cannot reach the platform. Connect in Winbox by MAC address as isp-admin, open Log and look for lines starting "platform-setup": they say how far it got and what stopped it.') }}</flux:text>
        </div>
    </div>

    @if ($this->routers->isNotEmpty())
        <flux:separator />

        <flux:heading size="sm">{{ __('Your routers') }}</flux:heading>

        <div class="flex flex-wrap gap-2">
            @foreach ($this->routers as $router)
                <flux:button
                    :href="route('routers.setup', $router)"
                    wire:navigate
                    size="sm"
                    variant="ghost"
                    icon="server"
                >{{ $router->name }}</flux:button>
            @endforeach
        </div>
    @endif
</div>
