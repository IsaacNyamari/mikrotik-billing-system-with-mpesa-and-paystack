<?php

use App\Enums\SubscriptionStatus;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Services\Platform\PlatformBilling;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Facades\Tenancy;
use Flux\Flux;
use Illuminate\Support\Collection;
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

        <flux:text size="sm" class="opacity-70">
            {{ __('Rates are held between :min% and :max%. Invoices are raised nightly for the month just ended; an ISP loses the console :grace days after an invoice falls due.', [
                'min' => config('platform.pricing.hotspot_commission_min'),
                'max' => config('platform.pricing.hotspot_commission_max'),
                'grace' => config('platform.terms.grace_days'),
            ]) }}
        </flux:text>
    </div>
</section>
