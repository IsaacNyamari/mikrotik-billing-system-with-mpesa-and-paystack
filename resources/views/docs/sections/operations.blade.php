{{--
    Chapter 9. The scheduled work.

    Included in an ISP-facing manual on purpose. An operator cannot fix a stopped scheduler, but
    an operator who knows what it does can recognise its absence — expired packages that never
    expire, payments stuck pending — and say so, instead of reporting four unrelated bugs.
--}}

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>
        {{ __('A lot of what makes this platform feel reliable happens when nobody is looking. Three jobs run in the background, and everything they do is something a customer would otherwise notice.') }}
    </p>

    <div class="flex flex-col gap-3">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center gap-2">
                <flux:heading size="sm">{{ __('Sweeping spent packages') }}</flux:heading>
                <flux:badge size="sm" color="zinc">{{ __('every minute') }}</flux:badge>
            </div>
            <flux:text class="mt-1">
                {{ __('Takes finished packages off the router, reading how much was used first — once the row is gone the device has no memory of it, and that reading is the only evidence of what the customer consumed. In the same pass it re-pushes anything that was paid for while a router was down.') }}
            </flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center gap-2">
                <flux:heading size="sm">{{ __('Chasing payments') }}</flux:heading>
                <flux:badge size="sm" color="zinc">{{ __('every minute') }}</flux:badge>
            </div>
            <flux:text class="mt-1">
                {{ __('Asks each provider what became of payments still waiting — for the customers who paid and then closed the page. Their money moved too, so their purchase still has to be recorded and their login still has to exist.') }}
            </flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <div class="flex items-center gap-2">
                <flux:heading size="sm">{{ __('Checking routers') }}</flux:heading>
                <flux:badge size="sm" color="zinc">{{ __('every five minutes') }}</flux:badge>
            </div>
            <flux:text class="mt-1">
                {{ __('Asks each device how it is, records outages, and keeps the health shown on your routers honest rather than a memory of the last time somebody clicked something.') }}
            </flux:text>
        </div>
    </div>

    <flux:callout variant="warning" icon="exclamation-triangle">
        <flux:callout.heading>{{ __('How to tell the background work has stopped') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Packages that should have run out are still shown as active, payments sit at "awaiting payment" long after the customer paid, and every router reports the same health it had hours ago. Those three together are one problem, not three. Tell us rather than changing settings.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:separator />

    <flux:heading size="sm">{{ __('Time') }}</flux:heading>

    <p>
        {{ __('Everything is stamped in East Africa Time, and your routers are set to your own timezone by their setup script. This matters more than it sounds: a payment stamped three hours out is one you cannot reconcile against an M-PESA statement.') }}
    </p>

    <flux:heading size="sm">{{ __('What survives what') }}</flux:heading>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('If this happens') }}</flux:table.column>
                <flux:table.column>{{ __('You lose') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell>{{ __('A router is switched off or loses its internet') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Nothing. Payments still settle and packages are re-pushed when it returns. Customers already online at that router are offline until it comes back, because it is their internet.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('A router is reset to factory defaults') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The device\'s configuration. No payments, no records and no earnings. Generate a fresh setup script and paste it.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('A customer\'s phone dies mid-purchase') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Nothing. Their payment is chased in the background, and their code gets them back in.') }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>
</div>
