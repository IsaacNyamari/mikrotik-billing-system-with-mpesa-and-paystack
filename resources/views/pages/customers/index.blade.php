<?php

use App\Enums\CustomerStatus;
use App\Enums\HotspotUserStatus;
use App\Models\Customer;
use App\Models\HotspotUser;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The people who pay this ISP, of which there are two kinds.
 *
 * A `Customer` is a billing and support identity — somebody invoiced and telephoned, usually on
 * a fixed line. A walk-in who buys an hour of wifi never becomes one, deliberately: they gave a
 * phone number and nothing else, and one row per hourly purchase would bury the subscribers
 * this list exists to show.
 *
 * But an ISP selling only HotSpot packages then has no `Customer` rows at all, and opens this
 * page to a blank table while four people are sitting on their wifi having paid. That is a true
 * screen telling a useless lie. So the buyers are shown here too, as what they are — purchases
 * grouped by the one identifier they gave — beside the accounts rather than mixed into them.
 */
new #[Title('Customers')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    /** Empty means "decide from what this ISP actually has". */
    #[Url(except: '')]
    public string $tab = '';

    /** Whose purchase history is open. */
    #[Locked]
    public ?string $viewingPhone = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', Customer::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, ['accounts', 'buyers'], true) ? $tab : 'accounts';

        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    /**
     * Which list to open on.
     *
     * An ISP that has never created a customer record but has been selling packages all week is
     * shown the packages. Opening on an empty table, when the answer they came for is one tab
     * away, is the whole of the complaint this addresses.
     */
    #[Computed]
    public function activeTab(): string
    {
        if (in_array($this->tab, ['accounts', 'buyers'], true)) {
            return $this->tab;
        }

        return $this->accountCount === 0 && $this->buyerCount > 0 ? 'buyers' : 'accounts';
    }

    #[Computed]
    public function accountCount(): int
    {
        return Customer::query()->count();
    }

    /**
     * Matches the Earnings screen, so one figure is not quoted in two currencies.
     */
    #[Computed]
    public function currency(): string
    {
        return \App\Support\Tenancy\Facades\Tenancy::tenant()?->currency ?? 'KES';
    }

    /**
     * How many distinct people have bought a package.
     */
    #[Computed]
    public function buyerCount(): int
    {
        return HotspotUser::query()->distinct()->count('phone');
    }

    /**
     * @return LengthAwarePaginator<int, Customer>
     */
    #[Computed]
    public function customers(): LengthAwarePaginator
    {
        return Customer::query()
            ->withCount('serviceAccounts')
            ->search($this->search)
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderBy('name')
            ->paginate(15);
    }

    /**
     * @return array<int, CustomerStatus>
     */
    #[Computed]
    public function statuses(): array
    {
        return CustomerStatus::cases();
    }

    /**
     * Everyone who has been sold a HotSpot package, one row per person.
     *
     * Grouped by phone number because that is the only thing a walk-in gave us, and it is what
     * they will read out at a counter. Money comes from the package's own `amount`, which is
     * overwritten with the sum the provider reported as actually paid — so this agrees with
     * Earnings rather than with the price list.
     *
     * @return LengthAwarePaginator<int, HotspotUser>
     */
    #[Computed]
    public function buyers(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return HotspotUser::query()
            ->selectRaw('phone')
            ->selectRaw('count(*) as packages')
            ->selectRaw('sum(amount) as spent')
            ->selectRaw('max(created_at) as last_bought')
            // "Live" is not the same as "active": the sweeper takes spent packages off the
            // router once a minute, so a row can be marked active for up to a minute after it
            // has stopped working. The clock is the authority, not the status column.
            ->selectRaw('sum(case when status = ? and (expires_at is null or expires_at > ?) then 1 else 0 end) as live', [
                HotspotUserStatus::Active->value,
                now(),
            ])
            ->when($search !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('phone', 'like', '%'.$search.'%')->orWhere('username', 'like', '%'.$search.'%'),
            ))
            ->groupBy('phone')
            ->orderByDesc('last_bought')
            ->paginate(15);
    }

    /**
     * One buyer's purchases, for the counter conversation that starts "I paid earlier".
     *
     * @return Collection<int, HotspotUser>
     */
    #[Computed]
    public function purchases(): Collection
    {
        if ($this->viewingPhone === null) {
            return collect();
        }

        return HotspotUser::query()
            ->with(['plan', 'router'])
            ->when(
                $this->viewingPhone === '',
                fn ($query) => $query->whereNull('phone'),
                fn ($query) => $query->where('phone', $this->viewingPhone),
            )
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    public function viewBuyer(?string $phone): void
    {
        $this->viewingPhone = $phone ?? '';

        unset($this->purchases);
    }

    public function closeBuyer(): void
    {
        $this->viewingPhone = null;

        unset($this->purchases);
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Customers') }}</flux:heading>
                <flux:subheading>
                    {{ $this->activeTab === 'buyers'
                        ? __('Everyone who has bought a HotSpot package, and what they bought.')
                        : __('Subscribers of your ISP and the services they hold.') }}
                </flux:subheading>
            </div>

            @can('create', App\Models\Customer::class)
                <flux:button variant="primary" icon="plus" :href="route('customers.create')" wire:navigate>
                    {{ __('New customer') }}
                </flux:button>
            @endcan
        </div>

        {{-- Two lists, not one. A HotSpot buyer and a fixed-line subscriber are different kinds
             of relationship, and flattening them into a single table would bury the handful of
             accounts an ISP actually manages under a row per hourly purchase. --}}
        <div class="flex flex-wrap items-center gap-2">
            <flux:button
                size="sm"
                :variant="$this->activeTab === 'accounts' ? 'filled' : 'ghost'"
                wire:click="showTab('accounts')"
                icon="identification"
            >
                {{ __('Accounts') }}
                <flux:badge size="sm" color="zinc" inset="top bottom">{{ number_format($this->accountCount) }}</flux:badge>
            </flux:button>

            <flux:button
                size="sm"
                :variant="$this->activeTab === 'buyers' ? 'filled' : 'ghost'"
                wire:click="showTab('buyers')"
                icon="device-phone-mobile"
            >
                {{ __('HotSpot buyers') }}
                <flux:badge size="sm" color="zinc" inset="top bottom">{{ number_format($this->buyerCount) }}</flux:badge>
            </flux:button>
        </div>

        @if ($this->activeTab === 'buyers')
            <div class="flex flex-wrap items-center gap-3">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    icon="magnifying-glass"
                    :placeholder="__('Phone number or M-PESA code')"
                    class="max-w-md"
                />

                @if ($search !== '')
                    <flux:button variant="ghost" wire:click="clearFilters">{{ __('Clear') }}</flux:button>
                @endif
            </div>

            <flux:table :paginate="$this->buyers">
                <flux:table.columns>
                    <flux:table.column>{{ __('Phone') }}</flux:table.column>
                    <flux:table.column>{{ __('Packages') }}</flux:table.column>
                    <flux:table.column>{{ __('Spent') }}</flux:table.column>
                    <flux:table.column>{{ __('Last bought') }}</flux:table.column>
                    <flux:table.column>{{ __('Online now') }}</flux:table.column>
                    <flux:table.column />
                </flux:table.columns>

                <flux:table.rows>
                    @forelse ($this->buyers as $buyer)
                        <flux:table.row :key="'buyer-'.($buyer->phone ?? 'none')">
                            <flux:table.cell variant="strong" class="whitespace-nowrap">
                                {{ $buyer->phone ?? __('No number given') }}
                            </flux:table.cell>

                            <flux:table.cell>{{ number_format((int) $buyer->packages) }}</flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap">
                                {{ $this->currency }} {{ number_format((float) $buyer->spent, 2) }}
                            </flux:table.cell>

                            <flux:table.cell class="whitespace-nowrap">
                                <flux:tooltip :content="\Illuminate\Support\Carbon::parse($buyer->last_bought)->toDayDateTimeString()">
                                    {{ \Illuminate\Support\Carbon::parse($buyer->last_bought)->diffForHumans() }}
                                </flux:tooltip>
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ((int) $buyer->live > 0)
                                    <flux:badge size="sm" color="green" inset="top bottom">{{ __('Active') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc" inset="top bottom">{{ __('No') }}</flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="text-end">
                                <flux:button
                                    size="sm"
                                    variant="ghost"
                                    icon="eye"
                                    wire:click="viewBuyer(@js($buyer->phone))"
                                >{{ __('Purchases') }}</flux:button>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6" class="text-center">
                                <flux:text>
                                    {{ $search !== ''
                                        ? __('Nobody matches that phone number or code.')
                                        : __('Nobody has bought a package yet. They will appear here the moment somebody pays.') }}
                                </flux:text>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>

            <flux:modal name="buyer-purchases" :open="$this->viewingPhone !== null" wire:close="closeBuyer" class="md:w-160">
                <div class="flex flex-col gap-4">
                    <div>
                        <flux:heading size="lg">
                            {{ $this->viewingPhone === '' ? __('No number given') : $this->viewingPhone }}
                        </flux:heading>
                        <flux:subheading>{{ __('Every package this number has been sold.') }}</flux:subheading>
                    </div>

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('Code') }}</flux:table.column>
                            <flux:table.column>{{ __('Package') }}</flux:table.column>
                            <flux:table.column>{{ __('Paid') }}</flux:table.column>
                            <flux:table.column>{{ __('When') }}</flux:table.column>
                            <flux:table.column>{{ __('Status') }}</flux:table.column>
                        </flux:table.columns>

                        <flux:table.rows>
                            @foreach ($this->purchases as $purchase)
                                <flux:table.row :key="'purchase-'.$purchase->id">
                                    {{-- The code is the whole of what a customer has when they
                                         say they paid, so it is the first thing on the row and
                                         it is selectable. --}}
                                    <flux:table.cell variant="strong" class="font-mono whitespace-nowrap select-all">
                                        {{ $purchase->username }}
                                    </flux:table.cell>

                                    <flux:table.cell class="whitespace-nowrap">{{ $purchase->plan?->name ?? '—' }}</flux:table.cell>

                                    <flux:table.cell class="whitespace-nowrap">
                                        {{ $this->currency }} {{ number_format((float) $purchase->amount, 2) }}
                                    </flux:table.cell>

                                    <flux:table.cell class="whitespace-nowrap">
                                        {{ $purchase->created_at->diffForHumans() }}
                                    </flux:table.cell>

                                    <flux:table.cell>
                                        <flux:badge size="sm" :color="$purchase->status->color()" inset="top bottom">
                                            {{ $purchase->hasExpired() && $purchase->status === App\Enums\HotspotUserStatus::Active
                                                ? __('Finishing')
                                                : $purchase->status->label() }}
                                        </flux:badge>

                                        @if ($purchase->last_error)
                                            <flux:tooltip :content="$purchase->last_error">
                                                <flux:icon.exclamation-triangle variant="micro" class="ms-1 inline text-amber-500" />
                                            </flux:tooltip>
                                        @endif
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </flux:modal>
        @else
        <div class="flex flex-wrap items-center gap-3">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Name, number, phone, email or network login')"
                class="max-w-md"
            />

            <flux:select wire:model.live="status" class="max-w-48" :placeholder="__('Any status')">
                <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
                @foreach ($this->statuses as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($search !== '' || $status !== '')
                <flux:button variant="ghost" wire:click="clearFilters">{{ __('Clear') }}</flux:button>
            @endif
        </div>

        <flux:table :paginate="$this->customers">
            <flux:table.columns>
                <flux:table.column>{{ __('Customer') }}</flux:table.column>
                <flux:table.column>{{ __('Number') }}</flux:table.column>
                <flux:table.column>{{ __('Phone') }}</flux:table.column>
                <flux:table.column>{{ __('Services') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->customers as $customer)
                    <flux:table.row :key="$customer->id">
                        <flux:table.cell class="whitespace-nowrap">
                            <flux:link :href="route('customers.show', $customer)" wire:navigate variant="ghost">
                                {{ $customer->displayName() }}
                            </flux:link>
                        </flux:table.cell>
                        <flux:table.cell variant="strong">{{ $customer->customer_number }}</flux:table.cell>
                        <flux:table.cell>{{ $customer->phone }}</flux:table.cell>
                        <flux:table.cell>{{ $customer->service_accounts_count }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$customer->status->color()" inset="top bottom">
                                {{ $customer->status->label() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="text-end">
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="pencil-square"
                                :href="route('customers.edit', $customer)"
                                wire:navigate
                            />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center">
                            <flux:text>
                                {{ $this->buyerCount > 0 && $search === '' && $status === ''
                                    ? __('No customer accounts yet — but :count people have bought HotSpot packages.', ['count' => number_format($this->buyerCount)])
                                    : __('No customers match this view yet.') }}
                            </flux:text>

                            @if ($this->buyerCount > 0 && $search === '' && $status === '')
                                <div class="mt-2">
                                    <flux:button size="sm" variant="ghost" icon="device-phone-mobile" wire:click="showTab('buyers')">
                                        {{ __('See who has paid') }}
                                    </flux:button>
                                </div>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
        @endif
    </div>
</section>
