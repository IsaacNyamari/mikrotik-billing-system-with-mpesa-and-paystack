<?php

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\UpdateCustomer;
use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Customer')] class extends Component {
    public ?Customer $customer = null;

    public string $type = 'individual';
    public string $name = '';
    public string $company_name = '';
    public string $email = '';
    public string $phone = '';
    public string $alternate_phone = '';
    public string $national_id = '';
    public string $address = '';
    public string $city = '';
    public string $status = 'active';
    public string $notes = '';

    public function mount(?Customer $customer = null): void
    {
        $this->customer = $customer?->exists ? $customer : null;

        if ($this->customer === null) {
            Gate::authorize('create', Customer::class);

            return;
        }

        Gate::authorize('update', $this->customer);

        $this->fill([
            'type' => $this->customer->type->value,
            'name' => $this->customer->name,
            'company_name' => (string) $this->customer->company_name,
            'email' => (string) $this->customer->email,
            'phone' => $this->customer->phone,
            'alternate_phone' => (string) $this->customer->alternate_phone,
            'national_id' => (string) $this->customer->national_id,
            'address' => (string) $this->customer->address,
            'city' => (string) $this->customer->city,
            'status' => $this->customer->status->value,
            'notes' => (string) $this->customer->notes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CustomerType::class)],
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255', 'required_if:type,company'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'alternate_phone' => ['nullable', 'string', 'max:32'],
            'national_id' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:128'],
            'status' => ['required', Rule::enum(CustomerStatus::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function save(CreateCustomer $create, UpdateCustomer $update): void
    {
        $validated = $this->validate();

        // Empty text inputs come back as "" from the browser; the database wants null.
        $validated = array_map(fn ($value) => $value === '' ? null : $value, $validated);

        if ($this->customer === null) {
            Gate::authorize('create', Customer::class);

            $customer = $create->handle($validated);

            Flux::toast(variant: 'success', text: __('Customer created.'));
        } else {
            Gate::authorize('update', $this->customer);

            $customer = $update->handle($this->customer, $validated);

            Flux::toast(variant: 'success', text: __('Customer updated.'));
        }

        $this->redirectRoute('customers.show', $customer, navigate: true);
    }

    #[Computed]
    public function isCompany(): bool
    {
        return $this->type === CustomerType::Company->value;
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">
                {{ $customer === null ? __('New customer') : __('Edit :name', ['name' => $customer->displayName()]) }}
            </flux:heading>
            <flux:subheading>
                {{ $customer === null
                    ? __('The customer number is allocated automatically.')
                    : __('Customer :number', ['number' => $customer->customer_number]) }}
            </flux:subheading>
        </div>

        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:radio.group wire:model.live="type" :label="__('Customer type')" variant="segmented">
                @foreach (CustomerType::cases() as $case)
                    <flux:radio value="{{ $case->value }}" label="{{ $case->label() }}" />
                @endforeach
            </flux:radio.group>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Contact name')" required autofocus />

                @if ($this->isCompany)
                    <flux:input wire:model="company_name" :label="__('Company name')" required />
                @endif

                <flux:input wire:model="phone" :label="__('Phone')" required :placeholder="__('2547XXXXXXXX')" />
                <flux:input wire:model="alternate_phone" :label="__('Alternate phone')" />

                <flux:input wire:model="email" type="email" :label="__('Email')" />
                <flux:input wire:model="national_id" :label="__('National ID / Registration no.')" />

                <flux:input wire:model="address" :label="__('Address')" />
                <flux:input wire:model="city" :label="__('City / town')" />

                <flux:select wire:model="status" :label="__('Status')">
                    @foreach (CustomerStatus::cases() as $case)
                        <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit" data-test="save-customer-button">
                    {{ __('Save') }}
                </flux:button>

                <flux:button
                    variant="ghost"
                    :href="$customer === null ? route('customers.index') : route('customers.show', $customer)"
                    wire:navigate
                >
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </form>
    </div>
</section>
