{{--
    Chapter 8. PPPoE.

    Short, because most of this platform's users sell HotSpot packages. The one thing that trips
    people is that PPP secrets authenticate nothing until a PPPoE server exists on the device —
    the platform will happily write credentials to a router that will never be asked for them.
--}}

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>
        {{ __('PPPoE is for subscribers on a fixed line — a home or an office paying monthly rather than buying an hour. It works the opposite way round from the HotSpot: you create the credentials and hand them over, rather than selling a code to somebody who walks in.') }}
    </p>

    <flux:heading size="sm">{{ __('Customers and service accounts') }}</flux:heading>

    <p>
        {{ __('A customer is a billing and support identity — a person you invoice and telephone. A service account is a network login. They are not the same thing, and one customer may hold several: a home line on one router and a backup HotSpot on another, each with its own package and its own credentials.') }}
    </p>

    <flux:callout variant="warning" icon="exclamation-triangle">
        <flux:callout.heading>{{ __('A PPPoE server has to exist on the router') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Credentials written to a router with no PPPoE server are a list nothing will ever ask for. The setup script builds one if you ask it to, on its own interface — a HotSpot and a PPPoE server on the same segment both try to answer the same client, and your fixed-line subscriber would end up at a captive portal.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('Suspending and terminating') }}</flux:heading>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Action') }}</flux:table.column>
                <flux:table.column>{{ __('On the router') }}</flux:table.column>
                <flux:table.column>{{ __('Why') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell>{{ __('Suspend') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The credentials are disabled') }}</flux:table.cell>
                    <flux:table.cell>{{ __('A suspended line usually comes back, and the subscriber keeps the same username.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Terminate') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The credentials are deleted') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The line is finished with.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Block the customer') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Every one of their services goes offline') }}</flux:table.cell>
                    <flux:table.cell>{{ __('Regardless of each service\'s own status. Blocking a customer means blocking them.') }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:callout icon="information-circle">
        <flux:callout.heading>{{ __('Taking access away also drops the live session') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Disabling credentials does not end a session already running. Without dropping it too, a suspended subscriber stays online until their connection happens to break — which for a fixed line can be days. The platform drops it for you.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('Usernames') }}</flux:heading>

    <p>
        {{ __('Network logins are unique within your ISP, not across the whole platform. Another ISP on this platform may have a subscriber called john and it will never collide with yours.') }}
    </p>
</div>
