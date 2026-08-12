{{--
    Chapter 4. Plans.

    The two things worth writing down are the split between what the profile carries and what the
    user row carries, and the fact that a plan code becomes a RouterOS profile name — because both
    surprise people, and getting the second wrong means a plan that will not save.
--}}

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>
        {{ __('A plan is what you sell: a price, a speed, and how long it lasts. There are two kinds, and they work in opposite directions.') }}
    </p>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('HotSpot') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('A walk-in buys it. They pay, and a login appears on the router named after their payment code. When their time or data runs out the login is deleted from the device — but the payment record stays, which is what your earnings are built from.') }}
            </flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('PPPoE') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('You create it and assign it to a subscriber on a fixed line. You choose the username and password, not them, and suspending the line disables it rather than deleting it — a suspended subscriber usually comes back.') }}
            </flux:text>
        </div>
    </div>

    <flux:separator />

    <flux:heading size="sm">{{ __('What goes where') }}</flux:heading>

    <p>
        {{ __('A plan becomes two different things on the router, and the split matters:') }}
    </p>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Setting') }}</flux:table.column>
                <flux:table.column>{{ __('Lives on') }}</flux:table.column>
                <flux:table.column>{{ __('Because') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell>{{ __('Speed') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The shared profile') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Everyone on the package gets the same speed.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Devices per login') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The shared profile') }}</flux:table.cell>
                    <flux:table.cell>{{ __('A property of the package, not of the buyer.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Time') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The customer\'s own login') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Two people on the same package each bought their own hour.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Data allowance') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The customer\'s own login') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Same reason. It is theirs, not the package\'s.') }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:separator />

    <flux:heading size="sm">{{ __('The plan code') }}</flux:heading>

    <p>
        {{ __('A plan\'s code becomes the profile name on the router, so it is limited to letters, numbers, dots, dashes and underscores, and it has to be unique among your plans. If a profile of that name already exists on a device — because you built it by hand before adopting this platform — it is adopted and updated rather than duplicated. You keep one of each.') }}
    </p>

    <flux:callout icon="information-circle">
        <flux:callout.heading>{{ __('Speeds are stored the way you sell them') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Enter download and upload as your customer would understand them. RouterOS reads rate limits from the router\'s point of view, which is the reverse, and the platform turns them round on the way out. You do not need to think about it — but it is why the numbers on the device look swapped if you go and read them.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('Getting a plan onto a router') }}</flux:heading>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('Saving a plan pushes it to your routers.') }}</li>
        <li>{{ __('A router that was unreachable at that moment has the failure recorded against it on the plan\'s page, with a button to try again. Nothing is silently lost.') }}</li>
        <li>{{ __('The first sale of a package also makes sure its profile is there, so a plan that failed to push still works once the router comes back.') }}</li>
    </ul>

    <flux:callout variant="warning" icon="exclamation-triangle">
        <flux:callout.heading>{{ __('Withdrawing a package does not cut off the people on it') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('That is deliberate: somebody who bought an hour is entitled to their hour. Take a plan off sale and it stops appearing on the portal, while the customers already on it run out normally.') }}
        </flux:callout.text>
    </flux:callout>
</div>
