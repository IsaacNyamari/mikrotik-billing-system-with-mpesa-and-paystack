<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('components.layouts.frontend')] #[Title('Services and pricing')] class extends Component {
    /**
     * Read from config rather than typed into the page, so the price a prospect is quoted and
     * the price the system bills can never drift apart.
     *
     * @return array<string, mixed>
     */
    public function with(): array
    {
        return [
            'onboarding' => (float) config('platform.pricing.onboarding_fee'),
            'rateMin' => (float) config('platform.pricing.hotspot_commission_min'),
            'rateMax' => (float) config('platform.pricing.hotspot_commission_max'),
            'pppoeFee' => (float) config('platform.pricing.pppoe_monthly_fee'),
            'dueDays' => (int) config('platform.terms.due_after_days'),
            'graceDays' => (int) config('platform.terms.grace_days'),
        ];
    }
}; ?>

<div>
    <x-frontend.hero
        compact
        :eyebrow="__('Services')"
        :heading="__('Everything an ISP needs to bill')"
        :lead="__('Two products, one platform: prepaid wifi sold at the login page, and monthly subscriber lines over PPPoE.')"
    />

    <x-frontend.section
        :eyebrow="__('HotSpot')"
        :heading="__('Prepaid wifi, sold from a phone')"
        align="left"
        :lead="__('The way most internet is bought here: a few shillings for an hour, paid on M-PESA, on the spot.')"
    >
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-frontend.feature icon="phone" :title="__('Your own branded login page')">
                {{ __('Your name, your logo, your colours and your wording. Packages appear as tiles a customer can price at a glance.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="cash" :title="__('M-PESA or Paystack')">
                {{ __('Use your own Daraja short code if you have one. If you do not, collect through Paystack instead — the customer sees the same thing either way.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="clock" :title="__('Time and data limits')">
                {{ __('An hour, a day, a week, or a data bundle. When it runs out the login is deleted from the router and the customer buys again.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="check" :title="__('No usernames to remember')">
                {{ __('The M-PESA code becomes the login, and the portal signs the customer in the moment payment clears. Nothing to write on a slip of paper.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="router" :title="__('Works with what you own')">
                {{ __('Any MikroTik running RouterOS with a HotSpot server. No new hardware, no proprietary boxes.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="chart" :title="__('Know what sells')">
                {{ __('Which package, which router, which hour of the day. Priced wrong and you will see it in a week rather than a quarter.') }}
            </x-frontend.feature>
        </div>
    </x-frontend.section>

    <x-frontend.section
        tint
        :eyebrow="__('PPPoE')"
        :heading="__('Monthly subscribers, properly managed')"
        align="left"
        :lead="__('For the homes and shops on a fixed line, where the relationship lasts longer than an hour.')"
    >
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-frontend.feature icon="users" :title="__('Customers and their lines')">
                {{ __('One customer can hold several services — a home line and a backup HotSpot — each with its own router, package and credentials.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="shield" :title="__('Suspension that means it')">
                {{ __('Suspending a line disables the secret and drops the live session. The customer is offline immediately, not whenever their session happens to end.') }}
            </x-frontend.feature>

            <x-frontend.feature icon="router" :title="__('Speed profiles pushed for you')">
                {{ __('A package becomes a RouterOS profile on every router it applies to. Change the speed once and it reaches every device.') }}
            </x-frontend.feature>
        </div>
    </x-frontend.section>

    {{-- Pricing ---------------------------------------------------------------- --}}
    <div id="pricing" class="scroll-mt-24"></div>

    <x-frontend.section
        :eyebrow="__('Pricing')"
        :heading="__('You pay us out of what you collect')"
        :lead="__('No monthly licence and no charge per router. If you sell nothing in a month, you owe almost nothing.')"
    >
        <div class="mx-auto grid max-w-4xl gap-4 sm:grid-cols-3">
            <x-frontend.price-card :title="__('Setup')" :amount="number_format($onboarding, 0)" :note="__('One-off, before you go live')">
                {{ __('Opening your account, connecting your first router, and getting your first package selling.') }}
            </x-frontend.price-card>

            <x-frontend.price-card
                featured
                :title="__('HotSpot commission')"
                :amount="rtrim(rtrim(number_format($rateMin, 1), '0'), '.').'–'.rtrim(rtrim(number_format($rateMax, 1), '0'), '.')"
                unit="%"
                currency=""
                :badge="__('Pay as you earn')"
                :note="__('Of what your portal collects')"
            >
                {{ __('Billed monthly on payments that actually settled. Your rate is agreed with you and shown on your subscription page — it never changes without you being told.') }}
            </x-frontend.price-card>

            <x-frontend.price-card :title="__('PPPoE')" :amount="number_format($pppoeFee, 0)" :unit="__('/ user')" :note="__('Per active line, per month')">
                {{ __('Counted at billing time on lines that are active. Suspended and terminated lines cost you nothing.') }}
            </x-frontend.price-card>
        </div>

        {{-- A worked example. An ISP deciding whether to sign up is doing this arithmetic on
             paper anyway; better they do it with our numbers than their guess at them. --}}
        <div class="mx-auto mt-12 max-w-3xl rounded-2xl border border-zinc-200 bg-white p-6 sm:p-8 dark:border-zinc-800 dark:bg-zinc-900">
            <h3 class="font-semibold text-zinc-900 dark:text-white">{{ __('What a real month looks like') }}</h3>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('An ISP collecting KSh 80,000 through the wifi portal and running 40 PPPoE lines, at 5%.') }}
            </p>

            <dl class="mt-6 space-y-3 text-sm">
                <div class="flex justify-between gap-4 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('HotSpot collections') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">KSh 80,000</dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Commission at 5%') }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">KSh 4,000</dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('40 PPPoE lines × KSh :fee', ['fee' => number_format($pppoeFee, 0)]) }}</dt>
                    <dd class="font-medium text-zinc-900 dark:text-white">KSh {{ number_format(40 * $pppoeFee, 0) }}</dd>
                </div>
                <div class="flex justify-between gap-4 pt-1">
                    <dt class="font-semibold text-zinc-900 dark:text-white">{{ __('Your bill for the month') }}</dt>
                    <dd class="text-lg font-semibold text-zinc-900 dark:text-white">KSh {{ number_format(4000 + 40 * $pppoeFee, 0) }}</dd>
                </div>
            </dl>

            <p class="mt-6 text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('The ISP keeps KSh :kept of the wifi money. The bill arrives at the start of the following month, falls due after :due days, and there are :grace more days after that before anything is restricted.', [
                    'kept' => number_format(80000 - 4000, 0),
                    'due' => $dueDays,
                    'grace' => $graceDays,
                ]) }}
            </p>
        </div>

        <div class="mx-auto mt-8 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
            <p class="font-medium text-zinc-900 dark:text-white">{{ __('Worth being clear about') }}</p>
            <ul class="mt-3 space-y-2">
                <li>{{ __('Customer money goes to your own M-PESA or Paystack account, never through ours. We invoice you afterwards.') }}</li>
                <li>{{ __('The setup fee is payable before the system opens. Everything after that is billed monthly in arrears.') }}</li>
                <li>{{ __('Every invoice shows the arithmetic: what you collected, at what rate, over how many lines.') }}</li>
                <li>{{ __('If a bill goes unpaid past the grace period the console is locked, not deleted. Paying restores it within seconds.') }}</li>
            </ul>
        </div>
    </x-frontend.section>

    <x-frontend.cta
        :heading="__('Ready to see it working?')"
        :lead="__('Tell us how many routers you run and what you sell, and we will get you live.')"
    />
</div>
