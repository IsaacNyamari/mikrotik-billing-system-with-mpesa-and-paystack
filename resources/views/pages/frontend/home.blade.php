<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.frontend')] #[Title('Sell internet without the paperwork')] class extends Component {
    //
}; ?>

<div>
    <x-frontend.hero
        :eyebrow="__('For internet service providers in Kenya')"
        :heading="__('Sell internet without the paperwork')"
        :lead="__('Take M-PESA payments at the wifi login page, hand out access automatically, and manage every MikroTik you own from one screen. You pay us a share of what you actually collect.')"
    >
        <x-slot name="actions">
            <a href="{{ route('register') }}" wire:navigate class="fe-btn-mint">
                {{ __('Open your account') }}
            </a>
            <a href="{{ route('services') }}#pricing" class="fe-btn-glass">
                {{ __('See what it costs') }}
            </a>
        </x-slot>

        <x-slot name="below">
            <x-frontend.dashboard-preview class="mx-auto max-w-5xl" />
        </x-slot>
    </x-frontend.hero>

    <div class="px-4 pt-12 sm:px-6 sm:pt-16">
        <div class="mx-auto grid max-w-5xl gap-4 sm:grid-cols-3">
            <x-frontend.stat :value="__('KSh 2,000')" :label="__('One-off setup fee')" />
            <x-frontend.stat value="3–5%" :label="__('Of what your HotSpot collects')" />
            <x-frontend.stat :value="__('KSh 15')" :label="__('Per PPPoE subscriber a month')" />
        </div>
    </div>

    <x-frontend.section
        tint
        :eyebrow="__('What it does')"
        :heading="__('The whole job, end to end')"
        :lead="__('From the moment somebody joins your wifi to the moment their hour runs out — without you touching a router.')"
    >
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-frontend.feature icon="phone" :title="__('A captive portal that sells')">
                {{ __('Customers join your wifi, tap a package, enter their number and pay. Their login is created and posted to the router automatically — they never type a username.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="cash" :title="__('M-PESA that actually settles')">
                {{ __('Access is granted on a confirmed payment, never on a prompt being sent. If a callback never arrives we ask Safaricom directly, so nobody who paid is left staring at a spinner.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="router" :title="__('Your MikroTiks, controlled')">
                {{ __('Packages become RouterOS profiles, logins are written to the device, and expired users are removed the minute their time is up. HotSpot and PPPoE both.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="users" :title="__('PPPoE subscribers')">
                {{ __('Create a line, assign it to a customer, suspend it when they fall behind. A suspension actually cuts the connection instead of just changing a row in a database.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="chart" :title="__('Earnings you can trust')">
                {{ __('Every shilling that moved, by router and by package. Payment records outlive the access they bought, so your reporting stays intact after logins are swept away.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="shield" :title="__('Your data is yours')">
                {{ __('Every ISP on the platform is walled off from every other one, enforced in the database layer rather than by remembering to add a filter. Router passwords are encrypted at rest.') }}
            </x-frontend.feature>
        </div>
    </x-frontend.section>

    <x-frontend.section
        :eyebrow="__('How it works')"
        :heading="__('Four steps to your first sale')"
        align="center"
    >
        <div class="mx-auto grid max-w-4xl gap-10 sm:grid-cols-2">
            <x-frontend.step number="1" :title="__('Sign up and pay the setup fee')">
                {{ __('Nobody has to approve you — your account opens the moment you register. KSh 2,000 once, paid by M-PESA from inside the app, and the system unlocks.') }}
            </x-frontend.step>

            <x-frontend.step number="2" :title="__('Add your router')">
                {{ __('Point the platform at your MikroTik and it takes care of the rest — profiles, the login page, the lot.') }}
            </x-frontend.step>

            <x-frontend.step number="3" :title="__('Set your packages')">
                {{ __('An hour for twenty shillings, a week for a hundred and thirty, whatever sells where you are. Your prices, your wording, your colours.') }}
            </x-frontend.step>

            <x-frontend.step number="4" :title="__('Start collecting')">
                {{ __('Money goes to your own M-PESA till, not ours. We bill you a share of it at the end of the month.') }}
            </x-frontend.step>
        </div>
    </x-frontend.section>

    <x-frontend.section tint :heading="__('You only pay when you earn')" align="center"
        :lead="__('No monthly licence, no per-router charge. A quiet month costs you almost nothing.')">
        <div class="mx-auto grid max-w-4xl gap-4 sm:grid-cols-3">
            <x-frontend.price-card :title="__('Setup')" amount="2,000" :note="__('One-off')">
                {{ __('Your account, your first router, and help getting the first package live.') }}
            </x-frontend.price-card>

            <x-frontend.price-card featured :title="__('HotSpot')" amount="3–5" unit="%" currency="" :badge="__('Most ISPs')" :note="__('Of what you collect')">
                {{ __('Charged monthly on money that actually moved. Cancelled and failed payments cost you nothing.') }}
            </x-frontend.price-card>

            <x-frontend.price-card :title="__('PPPoE')" amount="15" :unit="__('/ user')" :note="__('Per active line, per month')">
                {{ __('Counted on lines that are actually active. Suspended and terminated lines are not billed.') }}
            </x-frontend.price-card>
        </div>

        <p class="mt-8 text-center text-sm text-slate-600">
            <a href="{{ route('services') }}#pricing" class="fe-link">
                {{ __('See exactly how the bill is worked out') }}
            </a>
        </p>
    </x-frontend.section>

    <x-frontend.cta />
</div>
