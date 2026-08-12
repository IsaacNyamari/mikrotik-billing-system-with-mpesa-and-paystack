{{--
    Chapter 10. Symptoms.

    Organised by what the operator sees rather than by what the system is doing, because the
    operator does not yet know what the system is doing — that is why they are here.
--}}

<div class="flex flex-col gap-6 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>{{ __('Ordered roughly by how often each one comes up.') }}</p>

    @foreach ([
        [
            'symptom' => __('A customer paid and is not online'),
            'cause' => __('Almost always their phone, not your router: the M-PESA prompt arrives over the mobile network, and some phones leave the wifi to answer it and do not come back.'),
            'fix' => __('Rejoin the wifi and tap "Connect now" on the portal, or enter the M-PESA code under "Already paid?". Never take a second payment.'),
            'link' => 'recovery',
        ],
        [
            'symptom' => __('The portal says payments are not set up'),
            'cause' => __('No till number and no Paystack account has been entered, so there is nothing to send a prompt with.'),
            'fix' => __('Enter your till number under payment settings. The portal starts selling the moment you save.'),
            'link' => 'payments',
        ],
        [
            'symptom' => __('A router shows as offline'),
            'cause' => __('The management tunnel has no handshake. Either the router is off, or its internet is down, or the connection it sits on blocks outbound UDP.'),
            'fix' => __('Check the router is on and can reach the internet. Then check the tunnel on the device itself.'),
            'link' => 'tunnel',
        ],
        [
            'symptom' => __('Customers see MikroTik\'s own login page, asking for a username'),
            'cause' => __('The login pages did not download when the setup script ran — usually because the router had no internet at that moment.'),
            'fix' => __('Regenerate the setup script and paste it again. It is safe to run more than once.'),
            'link' => 'routers',
        ],
        [
            'symptom' => __('The portal loads but customers cannot reach it after joining'),
            'cause' => __('The walled garden does not have a route to this platform. It is written as an address, so it goes stale if the platform moves.'),
            'fix' => __('Regenerate and re-paste the setup script on that router.'),
            'link' => 'portal',
        ],
        [
            'symptom' => __('A package shows as failed on a router'),
            'cause' => __('It could not be written when it was created, because the device was unreachable.'),
            'fix' => __('Nothing, usually — it retries by itself. The plan\'s page also has a button to push it now.'),
            'link' => 'plans',
        ],
        [
            'symptom' => __('Speeds look swapped on the router'),
            'cause' => __('They are not. RouterOS states rate limits from the router\'s point of view, which is the reverse of how you sell them.'),
            'fix' => __('Nothing. Your customers get what you advertised.'),
            'link' => 'plans',
        ],
        [
            'symptom' => __('Customers get online without paying'),
            'cause' => __('Something on the router is letting established connections past the HotSpot — most often a fast-track rule added by hand after setup.'),
            'fix' => __('Re-paste the setup script, which removes it. Report usage figures that look too low at the same time; they are the same problem.'),
            'link' => 'routers',
        ],
        [
            'symptom' => __('You are locked out of the console'),
            'cause' => __('An invoice has passed its due date plus the grace period, or the setup fee has not been paid.'),
            'fix' => __('The billing page is always reachable, however much is owed. Settle there and the console returns immediately.'),
            'link' => 'billing',
        ],
        [
            'symptom' => __('PPPoE credentials do not authenticate anything'),
            'cause' => __('There is no PPPoE server on the router, so nothing ever asks for them.'),
            'fix' => __('Regenerate the setup script with PPPoE enabled, and paste it.'),
            'link' => 'pppoe',
        ],
    ] as $item)
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ $item['symptom'] }}</flux:heading>

            <dl class="mt-3 grid gap-x-4 gap-y-1 sm:grid-cols-[6rem_1fr]">
                <dt class="text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Usually') }}</dt>
                <dd>{{ $item['cause'] }}</dd>

                <dt class="text-xs font-semibold tracking-wide text-zinc-500 uppercase">{{ __('Do this') }}</dt>
                <dd>{{ $item['fix'] }}</dd>
            </dl>

            <flux:button
                wire:click="show('{{ $item['link'] }}')"
                size="xs"
                variant="ghost"
                icon-trailing="arrow-right"
                class="mt-3"
            >{{ __('Read more') }}</flux:button>
        </div>
    @endforeach

    <flux:callout icon="chat-bubble-left-right">
        <flux:callout.heading>{{ __('Two things to have ready if you contact us') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('Which router, and the M-PESA code or phone number of one customer it happened to. Those two turn a report into something answerable in minutes.') }}
        </flux:callout.text>
    </flux:callout>
</div>
