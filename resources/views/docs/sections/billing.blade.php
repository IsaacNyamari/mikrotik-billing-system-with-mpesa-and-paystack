{{--
    Chapter 11. What the ISP pays us.

    Figures are read from the platform's own price list rather than typed here, so what an ISP is
    quoted in the manual and what the system actually bills cannot drift apart.
--}}

@php
    $currency = config('platform.pricing.currency');
    $rate = App\Support\Tenancy\Facades\Tenancy::has()
        ? App\Support\Tenancy\Facades\Tenancy::tenant()->billing()?->hotspot_commission_rate
        : null;
@endphp

<div class="flex flex-col gap-5 text-sm leading-relaxed text-zinc-700 dark:text-zinc-300">
    <p>
        {{ __('What you charge your customers and what you pay us are kept entirely apart. Your earnings figures are built from your customers\' payments alone — our fees never appear in your revenue.') }}
    </p>

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('To start') }}</flux:heading>
            <p class="mt-1 text-lg font-semibold text-zinc-800 dark:text-zinc-100">
                {{ $currency }} {{ number_format((float) config('platform.pricing.onboarding_fee')) }}
            </p>
            <flux:text class="mt-1">{{ __('Once, when you sign up.') }}</flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('On HotSpot sales') }}</flux:heading>
            <p class="mt-1 text-lg font-semibold text-zinc-800 dark:text-zinc-100">
                {{ $rate !== null
                    ? rtrim(rtrim(number_format((float) $rate, 2), '0'), '.').'%'
                    : number_format((float) config('platform.pricing.hotspot_commission_min'), 0).'–'.number_format((float) config('platform.pricing.hotspot_commission_max'), 0).'%' }}
            </p>
            <flux:text class="mt-1">{{ __('Of money that actually arrived. A cancelled prompt is not a sale.') }}</flux:text>
        </div>

        <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <flux:heading size="sm">{{ __('Per PPPoE line') }}</flux:heading>
            <p class="mt-1 text-lg font-semibold text-zinc-800 dark:text-zinc-100">
                {{ $currency }} {{ number_format((float) config('platform.pricing.pppoe_monthly_fee')) }}
            </p>
            <flux:text class="mt-1">{{ __('A month, counted on active lines only.') }}</flux:text>
        </div>
    </div>

    <flux:callout icon="lock-closed">
        <flux:callout.heading>{{ __('Your rate is fixed when you sign up') }}</flux:callout.heading>
        <flux:callout.text>
            {{ __('It is copied onto your subscription at that moment. Changing the published price list never re-prices an ISP who already agreed to something else.') }}
        </flux:callout.text>
    </flux:callout>

    <flux:heading size="sm">{{ __('How a bill is worked out') }}</flux:heading>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('Commission is charged on settled payments only. Cancelled and failed prompts are not money and are not counted.') }}</li>
        <li>{{ __('PPPoE lines are counted at billing time, on lines that are active. Suspended and terminated ones are not carrying anybody.') }}</li>
        <li>{{ __('A month with no sales and no lines raises no invoice at all. A bill for zero can fall overdue and cost you the console for owing nothing.') }}</li>
        <li>{{ __('Every invoice stores its arithmetic. If you query a bill, the breakdown is there to look at.') }}</li>
    </ul>

    <flux:separator />

    <flux:heading size="sm">{{ __('If a bill lapses') }}</flux:heading>

    <p>
        {{ __('An invoice falls due :due days after it is raised, and there are a further :grace days of grace after that. Past both, the console closes until it is settled.', [
            'due' => (int) config('platform.terms.due_after_days'),
            'grace' => (int) config('platform.terms.grace_days'),
        ]) }}
    </p>

    <p>{{ __('Three things deliberately stay open:') }}</p>

    <ul class="flex list-disc flex-col gap-2 ps-5">
        <li>{{ __('The billing page. Being locked out of the page where you pay would turn every late payment into a phone call.') }}</li>
        <li>{{ __('This manual, so you can read what to do about it.') }}</li>
        <li>{{ __('Your captive portal and your payments. Your customers are not party to the argument, and an ISP that keeps collecting is an ISP that can pay.') }}</li>
    </ul>

    <div>
        <flux:button :href="route('billing.index')" wire:navigate size="sm" icon="receipt-percent">
            {{ __('Your subscription') }}
        </flux:button>
    </div>
</div>
