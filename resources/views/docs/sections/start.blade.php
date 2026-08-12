{{--
    Chapter 1. The mental model, and the order to do things in.

    Written for somebody who has just registered and is looking at an empty console. The single
    most useful thing at that moment is knowing that the router comes first — everything else
    hangs off it, and an ISP who starts with plans has nowhere to push them.
--}}

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>
        {{ __('This platform runs the business side of an ISP — customers, packages, payments and reporting — and drives your MikroTik routers to match. The two halves are deliberately separate:') }}
    </p>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('The business side') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Lives here. Who your customers are, what they bought, what they paid, and what you earned. This is the record, and it survives everything.') }}
            </flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('The network side') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Lives on the router. It authenticates people from its own user list, which the platform writes to and deletes from. A router that loses its configuration loses nobody a payment.') }}
            </flux:text>
        </div>
    </div>

    <flux:separator />

    <flux:heading size="sm">{{ __('The order to set up in') }}</flux:heading>

    <ol class="flex flex-col gap-4">
        <li class="flex gap-3">
            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold dark:bg-zinc-700">1</span>
            <div>
                <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ __('Add your router') }}</p>
                <p>{{ __('Everything else needs somewhere to go. Adding a router produces a setup script you paste into the device once, which builds the tunnel, the wifi and the HotSpot in one go.') }}</p>
                @can('viewAny', App\Models\Router::class)
                    <flux:link :href="route('routers.create')" wire:navigate class="text-sm">{{ __('Add a router') }}</flux:link>
                @endcan
            </div>
        </li>

        <li class="flex gap-3">
            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold dark:bg-zinc-700">2</span>
            <div>
                <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ __('Set up how you get paid') }}</p>
                <p>{{ __('Until this is done the portal tells customers to ask at the counter, which is honest but sells nothing. You need either an M-PESA till number or a Paystack account.') }}</p>
                @can(App\Support\Authorization\Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS)
                    <flux:link :href="route('settings.mpesa')" wire:navigate class="text-sm">{{ __('Payment settings') }}</flux:link>
                @endcan
            </div>
        </li>

        <li class="flex gap-3">
            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold dark:bg-zinc-700">3</span>
            <div>
                <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ __('Create your packages') }}</p>
                <p>{{ __('An hour, a day, a week — a price, a speed, and how long it lasts. Saving one pushes it to your routers by itself.') }}</p>
                @can('viewAny', App\Models\Plan::class)
                    <flux:link :href="route('plans.create')" wire:navigate class="text-sm">{{ __('Create a package') }}</flux:link>
                @endcan
            </div>
        </li>

        <li class="flex gap-3">
            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold dark:bg-zinc-700">4</span>
            <div>
                <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ __('Make the portal yours') }}</p>
                <p>{{ __('Your name, your logo, your colours and your wording. For most walk-in customers this page is the whole of what they ever see of your business.') }}</p>
                @can(App\Support\Authorization\Permissions::HOTSPOT_UPDATE_PORTAL)
                    <flux:link :href="route('settings.portal')" wire:navigate class="text-sm">{{ __('Portal settings') }}</flux:link>
                @endcan
            </div>
        </li>

        <li class="flex gap-3">
            <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold dark:bg-zinc-700">5</span>
            <div>
                <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ __('Buy something yourself') }}</p>
                <p>{{ __('Join your own wifi, buy the cheapest package with your own phone, and watch it go through. It costs you the price of one package and it is the only test that proves the whole chain.') }}</p>
            </div>
        </li>
    </ol>

    <flux:separator />

    <flux:heading size="sm">{{ __('How a sale actually happens') }}</flux:heading>

    <div class="overflow-x-auto">
        <div class="flex min-w-max items-center gap-2 text-xs">
            @foreach ([
                __('Customer joins the wifi'),
                __('Router redirects to your portal'),
                __('They tap a package'),
                __('They enter their phone number'),
                __('A prompt reaches their phone'),
                __('They pay'),
                __('The provider confirms it'),
                __('A login is written to the router'),
                __('They are online'),
            ] as $index => $step)
                @if ($index > 0)
                    <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-300 dark:text-zinc-600" />
                @endif

                <span class="rounded-md border border-zinc-200 px-2 py-1 whitespace-nowrap dark:border-zinc-700">{{ $step }}</span>
            @endforeach
        </div>
    </div>

    <flux:callout icon="information-circle">
        <flux:callout.heading>{{ __('The customer never types a username') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Their M-PESA code becomes both their username and their password, and the portal signs them in with it automatically. That code is the only thing they need to remember, and it is already in their confirmation SMS — which is what makes it possible to get them back online later without them paying twice.') }}
        </flux:callout.text>
    </flux:callout>
</div>
