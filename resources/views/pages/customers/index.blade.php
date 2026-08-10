<?php

use App\Enums\CustomerStatus;
use App\Models\Customer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customers')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

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
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Customers') }}</flux:heading>
                <flux:subheading>{{ __('Subscribers of your ISP and the services they hold.') }}</flux:subheading>
            </div>

            @can('create', App\Models\Customer::class)
                <flux:button variant="primary" icon="plus" :href="route('customers.create')" wire:navigate>
                    {{ __('New customer') }}
                </flux:button>
            @endcan
        </div>

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
                            <flux:text>{{ __('No customers match this view yet.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>
</section>
