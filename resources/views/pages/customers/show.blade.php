<?php

use App\Actions\ServiceAccounts\ChangeServiceAccountStatus;
use App\Actions\ServiceAccounts\CreateServiceAccount;
use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Models\Customer;
use App\Models\ServiceAccount;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Customer')] class extends Component {
    public Customer $customer;

    // New service account form.
    public string $serviceType = 'pppoe';
    public string $username = '';
    public string $secret = '';
    public string $label = '';
    public string $staticIp = '';
    public string $installationAddress = '';

    public function mount(Customer $customer): void
    {
        Gate::authorize('view', $customer);

        $this->customer = $customer;
    }

    /**
     * @return Collection<int, ServiceAccount>
     */
    #[Computed]
    public function serviceAccounts(): Collection
    {
        return $this->customer->serviceAccounts()->orderBy('account_number')->get();
    }

    public function addServiceAccount(CreateServiceAccount $create): void
    {
        Gate::authorize('create', ServiceAccount::class);

        $validated = $this->validate([
            'serviceType' => ['required', Rule::enum(ServiceType::class)],
            'username' => [
                'required',
                'string',
                'max:128',
                // The network login has to be unique inside this ISP, but two ISPs may each
                // have a "john" — hence the tenant condition rather than a plain unique rule.
                Rule::unique('service_accounts', 'username')
                    ->where('tenant_id', $this->customer->tenant_id),
            ],
            'secret' => ['nullable', 'string', 'min:8', 'max:64'],
            'label' => ['nullable', 'string', 'max:255'],
            'staticIp' => ['nullable', 'ip', 'required_if:serviceType,static'],
            'installationAddress' => ['nullable', 'string', 'max:255'],
        ]);

        $create->handle($this->customer, [
            'type' => ServiceType::from($validated['serviceType']),
            'username' => $validated['username'],
            'secret' => $validated['secret'] !== '' ? $validated['secret'] : null,
            'label' => $validated['label'] !== '' ? $validated['label'] : null,
            'static_ip' => $validated['staticIp'] !== '' ? $validated['staticIp'] : null,
            'installation_address' => $validated['installationAddress'] !== '' ? $validated['installationAddress'] : null,
        ]);

        $this->reset(['serviceType', 'username', 'secret', 'label', 'staticIp', 'installationAddress']);
        unset($this->serviceAccounts);

        Flux::modal('add-service')->close();
        Flux::toast(variant: 'success', text: __('Service account created.'));
    }

    public function changeStatus(int $accountId, string $status, ChangeServiceAccountStatus $change): void
    {
        $account = $this->customer->serviceAccounts()->findOrFail($accountId);

        Gate::authorize('changeStatus', $account);

        $change->handle($account, ServiceAccountStatus::from($status));

        unset($this->serviceAccounts);

        Flux::toast(variant: 'success', text: __('Service account updated.'));
    }
}; ?>

<section class="w-full">
    <div class="flex flex-col gap-6 p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <flux:heading size="xl">{{ $customer->displayName() }}</flux:heading>
                    <flux:badge size="sm" :color="$customer->status->color()">
                        {{ $customer->status->label() }}
                    </flux:badge>
                </div>
                <flux:subheading>
                    {{ $customer->customer_number }} &middot; {{ $customer->phone }}
                    @if ($customer->email)
                        &middot; {{ $customer->email }}
                    @endif
                </flux:subheading>
            </div>

            <div class="flex items-center gap-2">
                <flux:button variant="ghost" :href="route('customers.index')" wire:navigate icon="arrow-left">
                    {{ __('All customers') }}
                </flux:button>

                @can('update', $customer)
                    <flux:button icon="pencil-square" :href="route('customers.edit', $customer)" wire:navigate>
                        {{ __('Edit') }}
                    </flux:button>
                @endcan
            </div>
        </div>

        @if (! $customer->status->allowsService())
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Customer is :status', ['status' => $customer->status->label()]) }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('None of this customer\'s services will be authorised on the network, whatever their individual status says.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <flux:card class="grid gap-4 sm:grid-cols-3">
            <div>
                <flux:text size="sm" class="opacity-70">{{ __('Contact') }}</flux:text>
                <flux:text>{{ $customer->name }}</flux:text>
            </div>
            <div>
                <flux:text size="sm" class="opacity-70">{{ __('Address') }}</flux:text>
                <flux:text>{{ $customer->address ?? '—' }}{{ $customer->city ? ', '.$customer->city : '' }}</flux:text>
            </div>
            <div>
                <flux:text size="sm" class="opacity-70">{{ __('National ID / Reg. no.') }}</flux:text>
                <flux:text>{{ $customer->national_id ?? '—' }}</flux:text>
            </div>
        </flux:card>

        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="lg">{{ __('Service accounts') }}</flux:heading>
                <flux:subheading>
                    {{ __('Each service is its own network login. A customer may hold as many as they need.') }}
                </flux:subheading>
            </div>

            @can('create', App\Models\ServiceAccount::class)
                <flux:modal.trigger name="add-service">
                    <flux:button variant="primary" icon="plus">{{ __('Add service') }}</flux:button>
                </flux:modal.trigger>
            @endcan
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Account') }}</flux:table.column>
                <flux:table.column>{{ __('Type') }}</flux:table.column>
                <flux:table.column>{{ __('Network login') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column />
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->serviceAccounts as $account)
                    <flux:table.row :key="$account->id">
                        <flux:table.cell variant="strong">{{ $account->account_number }}</flux:table.cell>
                        <flux:table.cell>{{ $account->type->label() }}</flux:table.cell>
                        <flux:table.cell>
                            <span class="font-mono text-sm">{{ $account->username }}</span>
                            @if ($account->static_ip)
                                <flux:text size="sm" class="opacity-70">{{ $account->static_ip }}</flux:text>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" :color="$account->status->color()" inset="top bottom">
                                {{ $account->status->label() }}
                            </flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="text-end">
                            @can('changeStatus', $account)
                                @if (! $account->status->isFinal())
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />

                                        <flux:menu>
                                            @if ($account->status !== App\Enums\ServiceAccountStatus::Active)
                                                <flux:menu.item
                                                    icon="play"
                                                    wire:click="changeStatus({{ $account->id }}, 'active')"
                                                >
                                                    {{ __('Activate') }}
                                                </flux:menu.item>
                                            @endif

                                            @if ($account->status !== App\Enums\ServiceAccountStatus::Suspended)
                                                <flux:menu.item
                                                    icon="pause"
                                                    wire:click="changeStatus({{ $account->id }}, 'suspended')"
                                                >
                                                    {{ __('Suspend') }}
                                                </flux:menu.item>
                                            @endif

                                            <flux:menu.separator />

                                            <flux:menu.item
                                                icon="x-circle"
                                                variant="danger"
                                                wire:click="changeStatus({{ $account->id }}, 'terminated')"
                                                wire:confirm="{{ __('Terminating a service is permanent. Continue?') }}"
                                            >
                                                {{ __('Terminate') }}
                                            </flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                            @endcan
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center">
                            <flux:text>{{ __('This customer has no services yet.') }}</flux:text>
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:modal name="add-service" class="w-full max-w-lg">
        <form wire:submit="addServiceAccount" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Add a service') }}</flux:heading>
                <flux:subheading>{{ __('The account number is allocated automatically.') }}</flux:subheading>
            </div>

            <flux:select wire:model.live="serviceType" :label="__('Service type')">
                @foreach (ServiceType::cases() as $case)
                    <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="username" :label="__('Network login')" required />

            @if (ServiceType::from($serviceType)->usesCredentials())
                <flux:input
                    wire:model="secret"
                    :label="__('Network password')"
                    :description="__('Leave blank to generate one.')"
                />
            @endif

            @if (ServiceType::from($serviceType)->requiresStaticIp())
                <flux:input wire:model="staticIp" :label="__('Static IP address')" required />
            @endif

            <flux:input wire:model="label" :label="__('Label')" :description="__('Optional, e.g. “Home line” or “Shop backup”.')" />
            <flux:input wire:model="installationAddress" :label="__('Installation address')" />

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit" data-test="save-service-button">
                    {{ __('Create service') }}
                </flux:button>

                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
            </div>
        </form>
    </flux:modal>
</section>
