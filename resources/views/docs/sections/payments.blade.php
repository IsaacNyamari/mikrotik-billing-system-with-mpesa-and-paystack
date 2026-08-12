{{--
    Chapter 6. Taking payment.

    The rule an ISP most often wants to bend is "activate on a verified result, never on the push
    being accepted", so it is stated first and stated as a rule. The till-versus-Daraja-app
    arrangement is the other thing worth spelling out, because it is unusual and because an ISP
    who does not understand it will go and spend a fortnight on Safaricom paperwork they do not
    need.
--}}

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <flux:callout icon="check-badge">
        <flux:callout.heading>{{ __('Nobody gets online until the money has actually moved') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('A payment prompt being accepted only means the provider agreed to ring the customer\'s phone. Access is granted on a confirmed result and never before it — which is what stops a dismissed prompt from being free wifi.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('Two ways to collect') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('M-PESA') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('The customer gets the familiar prompt on their phone and enters their PIN. The money lands in your till, and the M-PESA code becomes their login.') }}
            </flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Paystack') }}</flux:heading>
            <flux:text class="mt-1">
                {{ __('Bring your own Paystack account. It prompts the phone in the same way — there is no separate checkout page for the customer to reach, which matters on a captive portal. The portal behaves identically either way.') }}
            </flux:text>
        </div>
    </div>

    <flux:separator />

    <flux:heading size="sm">{{ __('You need a till number, not a Daraja app') }}</flux:heading>

    <p>
        {{ __('Getting your own M-PESA API credentials from Safaricom is a fortnight of paperwork, and most ISPs arrive with something pasted into the wrong field. So the platform signs the request and you supply only where the money should land. That is a distinction Safaricom itself makes: one number says who the request authenticates as, another says who gets paid.') }}
    </p>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('If you already hold your own Daraja app, keep using it. Enter your own credentials and nothing changes.') }}</li>
        <li>{{ __('Otherwise enter your till or paybill number and leave the credentials empty.') }}</li>
        <li>{{ __('Safaricom will only route to a till the signing shortcode is authorised for. If your payments are refused with a routing error, that authorisation is what is missing — talk to us rather than to your customers.') }}</li>
    </ul>

    @can(App\Support\Authorization\Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS)
        <div>
            <flux:button :href="route('settings.mpesa')" wire:navigate size="sm" icon="credit-card">
                {{ __('Payment settings') }}
            </flux:button>
        </div>
    @endcan

    <flux:separator />

    <flux:heading size="sm">{{ __('How a payment is confirmed') }}</flux:heading>

    <p>
        {{ __('Two ways, and both are believed equally because both come from the provider:') }}
    </p>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('The provider tells us — a callback arriving at this platform the moment the customer pays.') }}</li>
        <li>{{ __('We ask the provider — every few seconds while the customer is on the page, and once a minute afterwards for the ones who closed the tab.') }}</li>
    </ul>

    <p>
        {{ __('The second exists because the first only arrives if the provider can reach this installation, which is not something to bet a customer\'s purchase on. Whichever answers first, the result is the same and nobody is issued two logins for one payment.') }}
    </p>

    <flux:heading size="sm">{{ __('What the customer is told') }}</flux:heading>

    <div class="overflow-x-auto">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('What happened') }}</flux:table.column>
                <flux:table.column>{{ __('What they see') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                <flux:table.row>
                    <flux:table.cell>{{ __('They dismissed the prompt') }}</flux:table.cell>
                    <flux:table.cell>{{ __('"You cancelled the request. Nothing was charged." Not called a failure — they pressed cancel a second ago, and a page disagreeing with them reads as broken.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Insufficient funds, wrong PIN') }}</flux:table.cell>
                    <flux:table.cell>{{ __('The provider\'s own wording. It happened on their phone and tells them exactly what to do next.') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('Nothing came back') }}</flux:table.cell>
                    <flux:table.cell>{{ __('"No response came back from your phone. Nothing was charged."') }}</flux:table.cell>
                </flux:table.row>
                <flux:table.row>
                    <flux:table.cell>{{ __('A provider outage, or your setup is incomplete') }}</flux:table.cell>
                    <flux:table.cell>{{ __('A plain "please try again in a moment". The real error is kept on the payment record for you — a walk-in cannot act on it, and it would tell a stranger about your configuration.') }}</flux:table.cell>
                </flux:table.row>
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:callout icon="magnifying-glass">
        <flux:callout.heading>{{ __('You always see the real reason') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Every payment keeps the provider\'s exact words, whatever the customer was shown. If somebody at the counter says their payment failed, the payment\'s own record says why.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('Two details worth knowing') }}</flux:heading>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('Prices are rounded up to whole shillings when the prompt is sent, because M-PESA will not accept decimals. Rounding down would undercharge you.') }}</li>
        <li>{{ __('If a customer pays a different amount from the one asked for, your books show the money that actually moved.') }}</li>
    </ul>
</div>
