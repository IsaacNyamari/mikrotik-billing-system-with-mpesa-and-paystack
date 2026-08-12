{{--
    Chapter 7. Paid but not online.

    The chapter that saves the most refunds, and the one counter staff should be shown first. Its
    whole message is: the customer's M-PESA code is enough, always, and nobody should ever be
    asked to pay twice.
--}}

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>
        {{ __('Money moves and then something goes wrong between there and the customer being online. The router was down for a minute. Their phone hopped to mobile data for the M-PESA prompt and never came back to your wifi. They closed the tab. Their battery died. They bought at the counter and walked to a different room.') }}
    </p>

    <p>
        {{ __('In every one of those cases the customer is holding a confirmation SMS with a code in it, and has no idea which of those things happened — nor should they need one. That code is enough on its own.') }}
    </p>

    <flux:callout icon="lifebuoy">
        <flux:callout.heading>{{ __('Nobody should ever pay twice') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('If a customer says they have paid, they almost certainly have. Take the code from their message rather than the money from their pocket.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:separator />

    <flux:heading size="sm">{{ __('What the customer does') }}</flux:heading>

    <ol class="flex flex-col gap-3">
        @foreach ([
            [__('Rejoin the wifi'), __('The portal opens by itself, as it did the first time.')],
            [__('Tap "Already paid? Enter your M-PESA code"'), __('It sits under the packages, and again on the screen shown when a payment appears to have failed.')],
            [__('Type the code from their SMS'), __('Upper or lower case, it does not matter. It is the code the message calls a transaction ID — the one that looks like QGR4TZ8LMN.')],
            [__('They are put back online'), __('Their package is written to the router again if it is not already there, and they are signed in — with whatever time they have left, not a fresh hour.')],
        ] as $index => [$title, $detail])
            <li class="flex gap-3">
                <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold dark:bg-zinc-700">{{ $index + 1 }}</span>
                <div>
                    <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ $title }}</p>
                    <p>{{ $detail }}</p>
                </div>
            </li>
        @endforeach
    </ol>

    <flux:separator />

    <flux:heading size="sm">{{ __('What the code is checked against') }}</flux:heading>

    <p>{{ __('Three things in turn, so the customer only ever types one thing:') }}</p>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('If the code is…') }}</flux:table.column>
                <flux:table.column>{{ __('What happens') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell>{{ __('A login that already exists') }}</flux:table.cell>
                    <flux:table.cell>{{ __('It is pushed to the router again if it is not live there, and they are signed in.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('A payment that was confirmed but never became a login') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The login is created now, and they are signed in. This is the router-was-down case.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('A payment still waiting on the provider') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The provider is asked outright, on the spot. If they confirm it, the customer goes straight online.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('A package already used up') }}</flux:table.cell>
                    <flux:table.cell>{{ __('They are told so plainly, and invited to buy another. Recovery never resurrects an expired package.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Not recognised') }}</flux:table.cell>
                    <flux:table.cell>{{ __('They are told to check the code or ask at the counter. At that point it is worth you looking at the payment record.') }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:callout icon="information-circle">
        <flux:callout.heading>{{ __('Codes work at the router they were bought at') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('A package bought at one of your locations exists on that location\'s router. Entering the code somewhere else reports it as not found, which is the honest answer — recovering it there would sign them in to a device that has never heard of them.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:separator />

    <flux:heading size="sm">{{ __('What happens without anybody doing anything') }}</flux:heading>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('A customer still on the portal has their access retried every few seconds until the router takes it.') }}</li>
        <li>{{ __('A background job re-pushes every paid-but-unissued package once a minute, whether or not anybody is watching.') }}</li>
        <li>{{ __('Payments left hanging are chased with the provider once a minute, for the customers who closed the page.') }}</li>
    </ul>

    <p>
        {{ __('So in most cases a customer who waits a minute and rejoins the wifi is simply online. The code is for the ones who do not want to wait, and for the ones whose phone left your network entirely.') }}
    </p>

    <flux:heading size="sm">{{ __('For counter staff') }}</flux:heading>

    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
        <p class="font-medium text-zinc-800 dark:text-zinc-100">{{ __('"I paid and I am not connected."') }}</p>
        <ol class="mt-2 flex flex-col gap-1 ps-5" style="list-style: decimal">
            <li>{{ __('Ask them to rejoin the wifi and open the page.') }}</li>
            <li>{{ __('Tap "Already paid? Enter your M-PESA code".') }}</li>
            <li>{{ __('Read the code from their M-PESA message and type it in.') }}</li>
            <li>{{ __('If it says the code is not recognised, search their phone number under Earnings and see what the payment says.') }}</li>
        </ol>
        <p class="mt-2">{{ __('Do not take another payment.') }}</p>
    </div>
</div>
