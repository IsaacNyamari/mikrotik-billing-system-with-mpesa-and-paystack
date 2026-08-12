{{--
    Chapter 5. The captive portal.

    The most-seen screen in the product and the one an ISP has most opinions about, so this
    chapter is mostly about what they can change — and about the two design decisions they are
    most likely to want to undo and should not.
--}}

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>
        {{ __('A customer joins your wifi, their phone opens the login page by itself, and the router hands them to your portal. For most walk-ins this page is the whole of what they ever see of your business, so it is worth twenty minutes.') }}
    </p>

    @if ($this->exampleRouter)
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Your portal address') }}</flux:heading>
            <flux:text class="mt-1">{{ __('One per router, so a customer always sees the packages sold at the place they are standing.') }}</flux:text>
            <p class="mt-2 font-mono text-xs break-all">{{ $this->exampleRouter->portalUrl() }}</p>
        </div>
    @endif

    <flux:heading size="sm">{{ __('The buying flow is two taps') }}</flux:heading>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('Tapping a package chooses it. The phone number is asked for on the next screen — somebody holding a phone in one hand should be asked one thing at a time, and their number is worth asking for only once they have decided what they are buying.') }}</li>
        <li>{{ __('Once the money lands, they are signed in automatically with nothing to press. Asking somebody who has just paid to confirm that they want what they bought is how people end up stranded on a page.') }}</li>
        <li>{{ __('A visible "Connect now" link is there anyway, because a phone that hopped to mobile data for the M-PESA prompt cannot reach the router until it rejoins the wifi.') }}</li>
    </ul>

    <flux:separator />

    <flux:heading size="sm">{{ __('What you can change') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-2">
        @foreach ([
            [__('Your name and logo'), __('Shown at the top. Falls back to your ISP name if you set nothing.')],
            [__('Colours'), __('One brand colour drives the whole page. Text colours are chosen from it automatically, so a pale colour does not end up with white text nobody can read.')],
            [__('The wording'), __('The headline, the intro, the banner, what the buy button says, and what the money is called.')],
            [__('Layout'), __('Tiles two to a row, or one package per row. Whether to show speeds and data allowances.')],
            [__('Light or dark'), __('Fixed either way, or following the customer\'s own phone.')],
            [__('Custom CSS'), __('For anything else. Angle brackets are stripped, so it can only ever style the page.')],
        ] as [$title, $detail])
            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                <flux:heading size="sm">{{ $title }}</flux:heading>
                <flux:text class="mt-1">{{ $detail }}</flux:text>
            </div>
        @endforeach
    </div>

    @can(App\Support\Authorization\Permissions::HOTSPOT_UPDATE_PORTAL)
        <div>
            <flux:button :href="route('settings.portal')" wire:navigate size="sm" icon="paint-brush">
                {{ __('Edit your portal') }}
            </flux:button>
        </div>
    @endcan

    <flux:separator />

    <flux:heading size="sm">{{ __('The walled garden') }}</flux:heading>

    <p>
        {{ __('A customer who has paid nothing can reach almost nothing — that is the point of a HotSpot. The walled garden is the short list of exceptions, and the router\'s setup script opens exactly the ones a purchase needs: this platform, the payment providers, and DNS. Without them a customer is redirected to a page they cannot load, which they experience as your wifi being broken.') }}
    </p>

    <flux:callout variant="warning" icon="exclamation-triangle">
        <flux:callout.heading>{{ __('Regenerate the script if the platform ever moves') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('One of those exceptions is written as an address rather than a name, because the router\'s own DNS is answered by the very HotSpot being configured. If this platform changes address, that rule has to be rewritten on each router.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('Why the portal looks plainer than the rest of this') }}</flux:heading>

    <p>
        {{ __('On purpose. It is the one page that has to render for somebody who has paid nothing and can reach almost nothing, so its styling is built into the page itself rather than fetched. There is no second request left to fail. A customer deciding whether to trust your network with their money should never be looking at unstyled text.') }}
    </p>
</div>
