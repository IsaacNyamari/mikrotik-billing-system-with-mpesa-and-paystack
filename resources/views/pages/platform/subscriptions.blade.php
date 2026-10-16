<?php

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Models\TenantSubscription;
use App\Services\Platform\PlatformBilling;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Facades\Tenancy;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Every ISP's standing with the platform, for the people who run it.
 *
 * Super admins only. It deliberately spans every tenant, which is the one thing the rest of
 * the system is built to prevent, so it says so explicitly rather than relying on a super
 * admin happening to be unscoped.
 */
new #[Title('ISP subscriptions')] class extends Component {
    public ?int $editingId = null;

    public string $rate = '';

    public string $grantDays = '14';

    // Marking a platform invoice paid by hand: cash, a bank transfer, an M-PESA payment that
    // went to the paybill without a prompt.
    public ?int $payingInvoiceId = null;

    public string $payMethod = 'mpesa';

    public string $payReference = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() === true, 403);
    }

    /**
     * @return Collection<int, Tenant>
     */
    #[Computed]
    public function tenants(): Collection
    {
        return Tenancy::withoutScope(fn (): Collection => Tenant::query()
            ->with('subscription')
            ->orderBy('name')
            ->get());
    }

    public function edit(int $tenantId): void
    {
        $this->editingId = $tenantId;

        $tenant = Tenancy::withoutScope(fn (): ?Tenant => Tenant::query()->find($tenantId));

        $this->rate = $tenant === null ? '' : (string) $tenant->billing()->hotspot_commission_rate;
    }

    public function saveRate(AuditLogger $audit): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() === true, 403);

        [$min, $max] = TenantSubscription::commissionBounds();

        $validated = $this->validate([
            // Held to the published range. A rate outside it is a typo, and a typo here bills
            // somebody the wrong amount every month until a human notices.
            'rate' => ['required', 'numeric', "min:{$min}", "max:{$max}"],
        ], [
            'rate.min' => __('The published range is :min% to :max%.', ['min' => $min, 'max' => $max]),
            'rate.max' => __('The published range is :min% to :max%.', ['min' => $min, 'max' => $max]),
        ]);

        $tenant = Tenancy::withoutScope(fn (): ?Tenant => Tenant::query()->findOrFail($this->editingId));

        $subscription = $tenant->billing();
        $subscription->hotspot_commission_rate = (float) $validated['rate'];
        $subscription->save();

        $audit->record(
            event: 'platform.rate_changed',
            auditable: $subscription,
            description: sprintf('Set %s to %s%% HotSpot commission', $tenant->name, $validated['rate']),
        );

        $this->editingId = null;
        unset($this->tenants);

        Flux::toast(variant: 'success', text: __('Rate updated.'));
    }

    /**
     * Let an ISP in without paying yet — a trial, or terms agreed offline.
     */
    public function grantAccess(int $tenantId, AuditLogger $audit): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() === true, 403);

        $days = max(1, min(365, (int) $this->grantDays));

        $tenant = Tenancy::withoutScope(fn (): ?Tenant => Tenant::query()->findOrFail($tenantId));

        $subscription = $tenant->billing();
        $subscription->access_granted_until = now()->addDays($days);
        $subscription->save();

        $audit->record(
            event: 'platform.access_granted',
            auditable: $subscription,
            description: sprintf('Granted %s %d days of access without payment', $tenant->name, $days),
        );

        unset($this->tenants);

        Flux::toast(text: __('Access granted for :days days.', ['days' => $days]));
    }

    /**
     * Bill one ISP for last month now, rather than waiting for the nightly run.
     */
    public function invoiceNow(int $tenantId, PlatformBilling $billing): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() === true, 403);

        $tenant = Tenancy::withoutScope(fn (): ?Tenant => Tenant::query()->findOrFail($tenantId));

        $invoice = $billing->invoiceForPeriod($tenant);

        unset($this->tenants);

        Flux::toast(text: $invoice === null
            ? __('Nothing to bill — no HotSpot sales and no PPPoE lines last month.')
            : __('Raised :number for :amount.', ['number' => $invoice->number, 'amount' => $invoice->total]));
    }

    /**
     * Every platform invoice still owed, setup fees first, oldest first.
     *
     * @return Collection<int, TenantInvoice>
     */
    #[Computed]
    public function unpaid(): Collection
    {
        return TenantInvoice::query()
            ->with('tenant')
            ->outstanding()
            ->orderByRaw('kind = ? desc', [TenantInvoice::KIND_ONBOARDING])
            ->orderBy('due_at')
            ->get();
    }

    public function startMarkPaid(int $invoiceId): void
    {
        $this->payingInvoiceId = $invoiceId;
        $this->reset(['payMethod', 'payReference']);
        $this->resetErrorBag();

        Flux::modal('mark-paid')->show();
    }

    /**
     * Record money that reached the platform without a prompt, and settle the invoice exactly
     * as a verified payment would: a setup fee lets the ISP in, a monthly bill restores access.
     */
    public function markPaid(PlatformBilling $billing, AuditLogger $audit): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() === true, 403);

        $validated = $this->validate([
            'payMethod' => ['required', 'in:mpesa,bank,cash,other'],
            'payReference' => ['required', 'string', 'max:64'],
        ], [
            'payReference.required' => __('Enter the M-PESA code, bank reference or receipt number, so the payment can be traced.'),
        ]);

        $invoice = TenantInvoice::query()->with('tenant')->outstanding()->find($this->payingInvoiceId);

        if ($invoice === null) {
            Flux::modal('mark-paid')->close();
            Flux::toast(variant: 'warning', text: __('That invoice is already settled.'));

            return;
        }

        DB::transaction(function () use ($invoice, $validated, $billing): void {
            $payment = new TenantPayment([
                'tenant_id' => $invoice->tenant_id,
                'tenant_invoice_id' => $invoice->id,
                'provider' => 'manual',
                'phone' => '',
                'amount' => $invoice->total,
                'receipt' => mb_strtoupper(trim($validated['payReference'])),
                'result_description' => sprintf('Marked paid by %s (%s)', auth()->user()?->email, $validated['payMethod']),
            ]);
            $payment->status = PaymentStatus::Paid;
            $payment->paid_at = now();
            $payment->save();

            $billing->markPaid($invoice);
        });

        $audit->record(
            event: 'platform.invoice_marked_paid',
            auditable: $invoice,
            description: sprintf('Marked %s (%s, %s %s) paid by %s, reference %s', $invoice->number, $invoice->tenant?->name, $this->currency, $invoice->total, $validated['payMethod'], $validated['payReference']),
        );

        $this->payingInvoiceId = null;
        unset($this->unpaid, $this->tenants);

        Flux::modal('mark-paid')->close();
        Flux::toast(variant: 'success', text: __(':number marked paid. :isp has access.', ['number' => $invoice->number, 'isp' => $invoice->tenant?->name]));
    }

    #[Computed]
    public function currency(): string
    {
        return (string) config('platform.pricing.currency');
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">{{ __('ISP subscriptions') }}</flux:heading>
            <flux:subheading>{{ __('What each ISP pays, and whether they are paid up.') }}</flux:subheading>
        </div>

        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('ISP') }}</flux:table.column>
                    <flux:table.column>{{ __('Subscription') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('HotSpot rate') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Outstanding') }}</flux:table.column>
                    <flux:table.column>{{ __('Access') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->tenants as $tenant)
                        @php($subscription = $tenant->billing())

                        <flux:table.row wire:key="tenant-{{ $tenant->id }}">
                            <flux:table.cell>
                                <div class="font-medium">{{ $tenant->name }}</div>
                                <flux:text size="sm" class="opacity-70">{{ $tenant->slug }}</flux:text>
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:badge size="sm" :color="$subscription->status->color()">
                                    {{ $subscription->status->label() }}
                                </flux:badge>
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                @if ($editingId === $tenant->id)
                                    <div class="flex items-center justify-end gap-2">
                                        <flux:input wire:model="rate" class="max-w-24" size="sm" />
                                        <flux:button size="sm" variant="primary" wire:click="saveRate">{{ __('Save') }}</flux:button>
                                        <flux:button size="sm" variant="ghost" wire:click="$set('editingId', null)">{{ __('Cancel') }}</flux:button>
                                    </div>
                                    @error('rate')
                                        <flux:text size="sm" class="mt-1 text-red-600">{{ $message }}</flux:text>
                                    @enderror
                                @else
                                    {{ rtrim(rtrim($subscription->hotspot_commission_rate, '0'), '.') }}%
                                @endif
                            </flux:table.cell>

                            <flux:table.cell align="end">
                                @php($owed = $subscription->outstandingTotal())
                                <span @class(['font-medium', 'text-red-600' => $owed > 0])>
                                    {{ $this->currency }} {{ number_format($owed, 2) }}
                                </span>
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($subscription->permitsAccess())
                                    <flux:badge size="sm" color="green">{{ __('Allowed') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="red">{{ __('On hold') }}</flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />

                                    <flux:menu>
                                        <flux:menu.item wire:click="edit({{ $tenant->id }})" icon="pencil-square">
                                            {{ __('Change rate') }}
                                        </flux:menu.item>
                                        <flux:menu.item wire:click="invoiceNow({{ $tenant->id }})" icon="document-text">
                                            {{ __('Invoice last month now') }}
                                        </flux:menu.item>
                                        <flux:menu.item wire:click="grantAccess({{ $tenant->id }})" icon="key">
                                            {{ __('Grant :days days access', ['days' => (int) $grantDays]) }}
                                        </flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6">
                                <flux:text class="opacity-70">{{ __('No ISPs yet.') }}</flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <div>
            <flux:heading size="lg">{{ __('Unpaid invoices') }}</flux:heading>
            <flux:subheading>{{ __('Setup fees and monthly bills not yet settled. Mark one paid when the money reached you some other way: cash, a bank transfer, or M-PESA paid straight to the paybill.') }}</flux:subheading>
        </div>

        <flux:card>
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Invoice') }}</flux:table.column>
                    <flux:table.column>{{ __('ISP') }}</flux:table.column>
                    <flux:table.column>{{ __('For') }}</flux:table.column>
                    <flux:table.column>{{ __('Due') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Amount') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->unpaid as $invoice)
                        <flux:table.row wire:key="invoice-{{ $invoice->id }}">
                            <flux:table.cell class="font-mono text-sm">{{ $invoice->number }}</flux:table.cell>
                            <flux:table.cell>{{ $invoice->tenant?->name }}</flux:table.cell>
                            <flux:table.cell>
                                {{ $invoice->describePeriod() }}
                                @if ($invoice->kind === App\Models\TenantInvoice::KIND_ONBOARDING)
                                    <flux:badge size="sm" color="blue" inset="top bottom">{{ __('Setup fee') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="whitespace-nowrap">
                                {{ $invoice->due_at?->format('j M Y') ?? '—' }}
                                <flux:badge size="sm" inset="top bottom" :color="$invoice->status->color()">{{ $invoice->status->label() }}</flux:badge>
                            </flux:table.cell>
                            <flux:table.cell align="end" variant="strong">{{ $this->currency }} {{ number_format((float) $invoice->total, 2) }}</flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:button size="sm" icon="check" wire:click="startMarkPaid({{ $invoice->id }})">{{ __('Mark paid') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6"><flux:text class="opacity-70">{{ __('Every invoice is paid.') }}</flux:text></flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:modal name="mark-paid" class="w-full max-w-md">
            @php($paying = $this->unpaid->firstWhere('id', $payingInvoiceId))
            <form wire:submit="markPaid" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Mark invoice paid') }}</flux:heading>
                    @if ($paying)
                        <flux:subheading>{{ $paying->number }} · {{ $paying->tenant?->name }} · {{ $this->currency }} {{ number_format((float) $paying->total, 2) }}</flux:subheading>
                    @endif
                </div>

                <flux:select wire:model="payMethod" :label="__('How it was paid')">
                    <flux:select.option value="mpesa">{{ __('M-PESA (paybill or till, no prompt)') }}</flux:select.option>
                    <flux:select.option value="bank">{{ __('Bank transfer') }}</flux:select.option>
                    <flux:select.option value="cash">{{ __('Cash') }}</flux:select.option>
                    <flux:select.option value="other">{{ __('Other') }}</flux:select.option>
                </flux:select>

                <flux:input wire:model="payReference" :label="__('Reference')" :placeholder="__('e.g. UHDI92J1YN')" :description="__('M-PESA code, bank reference or receipt number.')" />

                <flux:text size="sm">{{ __('This records the payment, settles the invoice and restores the ISP\'s access at once. It is recorded on the audit trail.') }}</flux:text>

                <div class="flex gap-2">
                    <flux:button variant="primary" type="submit">{{ __('Mark paid') }}</flux:button>
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                </div>
            </form>
        </flux:modal>

        <flux:text size="sm" class="opacity-70">
            {{ __('Rates are held between :min% and :max%. Invoices are raised nightly for the month just ended; an ISP loses the console :grace days after an invoice falls due.', [
                'min' => config('platform.pricing.hotspot_commission_min'),
                'max' => config('platform.pricing.hotspot_commission_max'),
                'grace' => config('platform.terms.grace_days'),
            ]) }}
        </flux:text>
    </div>
</section>
