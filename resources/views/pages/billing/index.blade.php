<?php

use App\Enums\PaymentStatus;
use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Models\TenantSubscription;
use App\Services\Platform\BillingCalculator;
use App\Services\Platform\PlatformBilling;
use App\Services\Platform\PlatformTill;
use App\Services\Platform\SubscriptionCollector;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * What this ISP owes the platform, and how they settle it.
 *
 * Deliberately outside the subscription gate: an ISP locked out of the page where they pay can
 * only get back in by telephoning somebody, which turns every late payment into support work.
 */
new #[Title('Subscription')] class extends Component {
    #[Locked]
    public ?int $payingInvoiceId = null;

    #[Locked]
    public ?int $paymentId = null;

    public string $phone = '';

    public function mount(): void
    {
        // Nothing here is another ISP's: every read below is filtered by the bound tenant,
        // and a super admin who has not switched into an ISP has no bill of their own.
        if (Tenancy::id() !== null) {
            TenantSubscription::forTenant(Tenancy::tenant());
        }
    }

    #[Computed]
    public function subscription(): ?TenantSubscription
    {
        return Tenancy::id() === null ? null : Tenancy::tenant()->billing();
    }

    /**
     * The bill to settle next: the setup fee first, then the oldest unpaid month.
     */
    #[Computed]
    public function dueInvoice(): ?TenantInvoice
    {
        if (Tenancy::id() === null) {
            return null;
        }

        $subscription = $this->subscription;

        if ($subscription !== null && ! $subscription->onboardingIsPaid()) {
            return app(PlatformBilling::class)->onboardingInvoice(Tenancy::tenant());
        }

        return TenantInvoice::query()
            ->where('tenant_id', Tenancy::id())
            ->outstanding()
            ->orderBy('due_at')
            ->first();
    }

    /**
     * @return Collection<int, TenantInvoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        return Tenancy::id() === null
            ? collect()
            : TenantInvoice::query()
                ->where('tenant_id', Tenancy::id())
                ->latest('id')
                ->limit(24)
                ->get();
    }

    /**
     * What this month is costing so far, before it is billed.
     *
     * An ISP should never be surprised by an invoice: the charge accrues in the open, with
     * the arithmetic on show.
     */
    #[Computed]
    public function runningCharge(): ?\App\Services\Platform\UsageCharge
    {
        if (Tenancy::id() === null) {
            return null;
        }

        return app(BillingCalculator::class)->forTenant(
            Tenancy::tenant(),
            \Carbon\CarbonImmutable::now()->startOfMonth(),
            \Carbon\CarbonImmutable::now(),
        );
    }

    #[Computed]
    public function payment(): ?TenantPayment
    {
        return $this->paymentId === null ? null : TenantPayment::query()->find($this->paymentId);
    }

    #[Computed]
    public function tillIsReady(): bool
    {
        return app(PlatformTill::class)->isConfigured();
    }

    #[Computed]
    public function tillProblem(): ?string
    {
        return app(PlatformTill::class)->unconfiguredReason();
    }

    public function pay(SubscriptionCollector $collector): void
    {
        $invoice = $this->dueInvoice;

        if ($invoice === null) {
            return;
        }

        $validated = $this->validate([
            'phone' => ['required', 'string', 'regex:/^(?:\+?254|0)?7\d{8}$|^(?:\+?254|0)?1\d{8}$/'],
        ], [
            'phone.regex' => __('Enter a Safaricom number like 07XX XXX XXX.'),
        ]);

        $payment = $collector->collect($invoice, $validated['phone']);

        $this->paymentId = $payment->id;
        $this->payingInvoiceId = $invoice->id;

        unset($this->payment);
    }

    /**
     * Polled while the prompt is on the operator's phone.
     */
    public function checkPayment(): void
    {
        unset($this->payment, $this->subscription, $this->dueInvoice, $this->invoices);

        $payment = $this->payment;

        if ($payment === null || $payment->status !== PaymentStatus::Pending) {
            return;
        }

        if ($payment->created_at->addMinutes((int) config('mpesa.abandon_after_minutes'))->isPast()) {
            $payment->status = PaymentStatus::Abandoned;
            $payment->save();

            unset($this->payment);
        }
    }

    public function startOver(): void
    {
        $this->reset(['paymentId', 'payingInvoiceId']);

        unset($this->payment, $this->subscription, $this->dueInvoice, $this->invoices);
    }

    #[Computed]
    public function currency(): string
    {
        return (string) config('platform.pricing.currency');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">{{ __('Subscription') }}</flux:heading>
            <flux:subheading>{{ __('What you pay to run your ISP on this platform.') }}</flux:subheading>
        </div>

        @if (! $this->subscription)
            <flux:card>
                <flux:text>{{ __('Choose an ISP first — a platform administrator has no subscription of their own.') }}</flux:text>
            </flux:card>
        @else
            @php($subscription = $this->subscription)

            {{-- Why they are locked out, said once and said plainly. --}}
            @if (! $subscription->permitsAccess())
                <flux:callout variant="danger" icon="lock-closed">
                    <flux:callout.heading>{{ __('Your account is on hold') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ $subscription->accessBlockedReason() }}
                        {{ __('Settle the amount below and everything comes straight back — nothing has been deleted.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:card class="flex flex-col gap-1">
                    <flux:text size="sm" class="opacity-70">{{ __('Status') }}</flux:text>
                    <flux:badge :color="$subscription->status->color()" size="sm" inset="top bottom">
                        {{ $subscription->status->label() }}
                    </flux:badge>
                </flux:card>

                <flux:card class="flex flex-col gap-1">
                    <flux:text size="sm" class="opacity-70">{{ __('Your HotSpot rate') }}</flux:text>
                    <flux:heading size="lg">{{ rtrim(rtrim($subscription->hotspot_commission_rate, '0'), '.') }}%</flux:heading>
                    <flux:text size="sm" class="opacity-70">{{ __('of what you collect') }}</flux:text>
                </flux:card>

                <flux:card class="flex flex-col gap-1">
                    <flux:text size="sm" class="opacity-70">{{ __('Per PPPoE line') }}</flux:text>
                    <flux:heading size="lg">
                        {{ $this->currency }} {{ number_format((float) $subscription->pppoe_monthly_fee, 0) }}
                    </flux:heading>
                    <flux:text size="sm" class="opacity-70">{{ __('each month') }}</flux:text>
                </flux:card>
            </div>

            {{-- Paying ------------------------------------------------------------- --}}
            @if ($this->payment?->status === PaymentStatus::Paid)
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.heading>{{ __('Payment received') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Receipt :code. Your account is active again.', ['code' => $this->payment->receipt ?? '—']) }}
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" href="{{ route('dashboard') }}" wire:navigate>{{ __('Go to dashboard') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @elseif ($this->payment?->status === PaymentStatus::Pending)
                <flux:card class="flex flex-col gap-3" wire:poll.3s="checkPayment">
                    <flux:heading size="lg">{{ __('Check your phone') }}</flux:heading>
                    <flux:text>
                        {{ __('Enter your M-PESA PIN to pay :currency :amount. This page updates by itself.', [
                            'currency' => $this->currency,
                            'amount' => number_format((float) $this->payment->amount, 2),
                        ]) }}
                    </flux:text>
                </flux:card>
            @elseif ($this->payment && $this->payment->status->isSettled())
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.heading>
                        {{ $this->payment->status === PaymentStatus::Cancelled ? __('You cancelled the prompt') : __('That payment did not go through') }}
                    </flux:callout.heading>
                    <flux:callout.text>{{ $this->payment->result_description ?? __('Nothing was charged.') }}</flux:callout.text>
                    <x-slot name="actions">
                        <flux:button size="sm" wire:click="startOver">{{ __('Try again') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @elseif ($this->dueInvoice)
                @php($invoice = $this->dueInvoice)

                <flux:card class="flex flex-col gap-4">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <flux:heading size="lg">
                                {{ $invoice->kind === TenantInvoice::KIND_ONBOARDING ? __('One-off setup fee') : __('Invoice :number', ['number' => $invoice->number]) }}
                            </flux:heading>
                            <flux:subheading>{{ $invoice->describePeriod() }}</flux:subheading>
                        </div>

                        <div class="text-right">
                            <flux:heading size="xl">{{ $this->currency }} {{ number_format((float) $invoice->total, 2) }}</flux:heading>
                            @if ($invoice->daysOfGraceLeft() !== null && $invoice->status->isOutstanding())
                                <flux:text size="sm" class="opacity-70">
                                    {{ trans_choice('{0}Access is on hold now|{1}:count day before access is held|[2,*]:count days before access is held', $invoice->daysOfGraceLeft(), ['count' => $invoice->daysOfGraceLeft()]) }}
                                </flux:text>
                            @endif
                        </div>
                    </div>

                    {{-- The arithmetic, not just the answer. An ISP querying a bill is owed
                         the numbers it was built from. --}}
                    @if ($invoice->breakdown && $invoice->kind !== TenantInvoice::KIND_ONBOARDING)
                        <div class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                            <div class="flex justify-between">
                                <span>
                                    {{ __('HotSpot commission — :collected collected at :rate%', [
                                        'collected' => $this->currency.' '.number_format((float) data_get($invoice->breakdown, 'hotspot.collected', 0), 2),
                                        'rate' => data_get($invoice->breakdown, 'hotspot.rate_percent'),
                                    ]) }}
                                </span>
                                <span class="font-medium">{{ number_format((float) $invoice->hotspot_amount, 2) }}</span>
                            </div>
                            <div class="mt-1 flex justify-between">
                                <span>
                                    {{ trans_choice('{0}No PPPoE lines|{1}:count PPPoE line|[2,*]:count PPPoE lines', (int) data_get($invoice->breakdown, 'pppoe.lines', 0), ['count' => (int) data_get($invoice->breakdown, 'pppoe.lines', 0)]) }}
                                    &times; {{ $this->currency }} {{ number_format((float) data_get($invoice->breakdown, 'pppoe.fee_per_line', 0), 0) }}
                                </span>
                                <span class="font-medium">{{ number_format((float) $invoice->pppoe_amount, 2) }}</span>
                            </div>
                        </div>
                    @endif

                    @if (! $this->tillIsReady)
                        <flux:callout variant="warning" icon="exclamation-triangle">
                            <flux:callout.text>{{ $this->tillProblem }}</flux:callout.text>
                        </flux:callout>
                    @else
                        <form wire:submit="pay" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <flux:input
                                wire:model="phone"
                                type="tel"
                                :label="__('M-PESA number to charge')"
                                placeholder="07XX XXX XXX"
                                class="sm:max-w-xs"
                            />
                            <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="pay">
                                <span wire:loading.remove wire:target="pay">
                                    {{ __('Pay :currency :amount', ['currency' => $this->currency, 'amount' => number_format((float) $invoice->total, 0)]) }}
                                </span>
                                <span wire:loading wire:target="pay">{{ __('Sending request…') }}</span>
                            </flux:button>
                        </form>
                    @endif
                </flux:card>
            @else
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.heading>{{ __('Nothing outstanding') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('You are paid up. This month is billed at the start of next month.') }}</flux:callout.text>
                </flux:callout>
            @endif

            {{-- This month so far ---------------------------------------------------- --}}
            @if ($this->runningCharge)
                @php($charge = $this->runningCharge)

                <flux:card class="flex flex-col gap-3">
                    <div>
                        <flux:heading size="lg">{{ __('This month so far') }}</flux:heading>
                        <flux:subheading>{{ __('Not yet billed. Charged at the start of next month.') }}</flux:subheading>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3 text-sm">
                        <div>
                            <flux:text class="opacity-70">{{ __('HotSpot collected') }}</flux:text>
                            <flux:heading>{{ $this->currency }} {{ number_format($charge->hotspotCollected, 2) }}</flux:heading>
                            <flux:text size="sm" class="opacity-70">
                                {{ __('commission :amount', ['amount' => $this->currency.' '.number_format($charge->hotspotAmount, 2)]) }}
                            </flux:text>
                        </div>
                        <div>
                            <flux:text class="opacity-70">{{ __('Active PPPoE lines') }}</flux:text>
                            <flux:heading>{{ number_format($charge->pppoeLines) }}</flux:heading>
                            <flux:text size="sm" class="opacity-70">
                                {{ $this->currency }} {{ number_format($charge->pppoeAmount, 2) }}
                            </flux:text>
                        </div>
                        <div>
                            <flux:text class="opacity-70">{{ __('Running total') }}</flux:text>
                            <flux:heading size="lg">{{ $this->currency }} {{ number_format($charge->total(), 2) }}</flux:heading>
                        </div>
                    </div>
                </flux:card>
            @endif

            {{-- History -------------------------------------------------------------- --}}
            @if ($this->invoices->isNotEmpty())
                <flux:card>
                    <flux:heading size="lg" class="mb-3">{{ __('Invoices') }}</flux:heading>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Number') }}</flux:table.column>
                            <flux:table.column>{{ __('For') }}</flux:table.column>
                            <flux:table.column>{{ __('Due') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->invoices as $row)
                                <flux:table.row wire:key="invoice-{{ $row->id }}">
                                    <flux:table.cell class="font-mono text-xs">{{ $row->number }}</flux:table.cell>
                                    <flux:table.cell>{{ $row->describePeriod() }}</flux:table.cell>
                                    <flux:table.cell>{{ $row->due_at?->format('j M Y') ?? '—' }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ number_format((float) $row->total, 2) }}</flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="$row->status->color()">{{ $row->status->label() }}</flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            @endif
        @endif
    </div>
</section>
