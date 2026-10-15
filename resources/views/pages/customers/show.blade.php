<?php

use App\Actions\ServiceAccounts\ChangeServiceAccountStatus;
use App\Actions\ServiceAccounts\CreateServiceAccount;
use App\Enums\PlanType;
use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceType;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Router;
use App\Models\ServiceAccount;
use App\Services\Billing\BillingMessages;
use App\Services\Billing\ServiceAccountRenewal;
use App\Services\Mpesa\PaymentCollector;
use App\Services\RouterOs\PppoeSecretProvisioner;
use App\Services\Sms\SmsSender;
use App\Support\Authorization\Permissions;
use Carbon\CarbonImmutable;
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
    public string $planId = '';
    public string $routerId = '';
    public string $paidUntil = '';

    // Billing of an existing PPPoE line.
    public ?int $billingAccountId = null;
    public string $billingPlanId = '';
    public string $billingRouterId = '';
    public string $billingPaidUntil = '';

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
        return $this->customer->serviceAccounts()->with(['plan', 'router'])->orderBy('account_number')->get();
    }

    /**
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function pppoePlans(): Collection
    {
        return Plan::query()->ofType(PlanType::Pppoe)->where('is_active', true)->orderBy('price')->get();
    }

    /**
     * @return Collection<int, Router>
     */
    #[Computed]
    public function routers(): Collection
    {
        return Router::query()->orderBy('name')->get();
    }

    #[Computed]
    public function billingAccount(): ?ServiceAccount
    {
        return $this->billingAccountId === null ? null : $this->customer->serviceAccounts()->with('plan')->find($this->billingAccountId);
    }

    public function editBilling(int $accountId): void
    {
        $account = $this->customer->serviceAccounts()->findOrFail($accountId);

        Gate::authorize('update', $account);

        $this->billingAccountId = $account->id;
        $this->billingPlanId = (string) ($account->plan_id ?? '');
        $this->billingRouterId = (string) ($account->router_id ?? '');
        $this->billingPaidUntil = (string) $account->expires_at?->toDateString();
        $this->resetErrorBag();

        unset($this->billingAccount);

        Flux::modal('edit-billing')->show();
    }

    /**
     * Package, router and paid-until date for a PPPoE line.
     *
     * Moving the date forward on a line the system cut for non-payment reconnects it — that is
     * what an operator who has just taken cash at the counter means by it.
     */
    public function saveBilling(ChangeServiceAccountStatus $change, PppoeSecretProvisioner $pppoe): void
    {
        $account = $this->customer->serviceAccounts()->findOrFail((int) $this->billingAccountId);

        Gate::authorize('update', $account);

        $validated = $this->validate([
            'billingPlanId' => ['required', Rule::exists('plans', 'id')->where('tenant_id', $this->customer->tenant_id)->where('type', PlanType::Pppoe->value)],
            'billingRouterId' => ['required', Rule::exists('routers', 'id')->where('tenant_id', $this->customer->tenant_id)],
            'billingPaidUntil' => ['nullable', 'date'],
        ], [], [
            'billingPlanId' => __('package'),
            'billingRouterId' => __('router'),
            'billingPaidUntil' => __('paid until'),
        ]);

        $expires = $validated['billingPaidUntil'] ? CarbonImmutable::parse($validated['billingPaidUntil'])->endOfDay() : null;
        $networkChanged = (int) $validated['billingPlanId'] !== $account->plan_id || (int) $validated['billingRouterId'] !== $account->router_id;

        $account->plan_id = (int) $validated['billingPlanId'];
        $account->router_id = (int) $validated['billingRouterId'];

        if ($expires?->toDateString() !== $account->expires_at?->toDateString()) {
            $account->expires_at = $expires;
            $account->reminded_before_for = null;
            $account->reminded_due_for = null;
        }

        $account->save();

        $paidUp = $expires !== null && $expires->isFuture();

        if ($paidUp && ($account->suspended_for_nonpayment || $account->status === ServiceAccountStatus::Pending)) {
            $account->suspended_for_nonpayment = false;
            $account->save();
            $change->handle($account, ServiceAccountStatus::Active, sprintf('Paid until %s set by hand', $expires->toDateString()));
        } elseif ($networkChanged && $account->status === ServiceAccountStatus::Active) {
            $pppoe->push($account);
        }

        unset($this->serviceAccounts, $this->billingAccount);

        Flux::modal('edit-billing')->close();
        Flux::toast(variant: 'success', text: __('Billing saved.'));
    }

    /**
     * Ring the subscriber's phone for their next period, from the ISP's side of the counter.
     */
    public function sendPrompt(int $accountId, PaymentCollector $collector): void
    {
        $account = $this->billedAccount($accountId);

        if ($account === null || blank($this->customer->phone)) {
            Flux::toast(variant: 'warning', text: __('This line needs a package, a router and a customer phone number first.'));

            return;
        }

        try {
            $payment = $collector->collectRenewal($account, (string) $this->customer->phone);
        } catch (\Throwable $e) {
            Flux::toast(variant: 'danger', text: __('Payments are not set up yet. Open Settings → Payments.'));

            return;
        }

        $payment->status === App\Enums\PaymentStatus::Failed
            ? Flux::toast(variant: 'danger', text: __('The prompt could not be sent: :reason', ['reason' => $payment->result_description]))
            : Flux::toast(variant: 'success', text: __('Prompt sent to :phone. The line renews as soon as they pay.', ['phone' => $payment->phone]));
    }

    public function sendReminder(int $accountId, SmsSender $sms, BillingMessages $messages): void
    {
        $account = $this->billedAccount($accountId);

        if ($account === null || blank($this->customer->phone)) {
            Flux::toast(variant: 'warning', text: __('This line needs a package, a router and a customer phone number first.'));

            return;
        }

        $message = $sms->send((string) $this->customer->phone, $messages->current($account), BillingMessages::MANUAL, $account);

        match ($message->status) {
            App\Models\SmsMessage::SENT => Flux::toast(variant: 'success', text: __('Reminder sent.')),
            App\Models\SmsMessage::SKIPPED => Flux::toast(variant: 'warning', text: __('No SMS account is set up. Open Settings → SMS.')),
            default => Flux::toast(variant: 'danger', text: __('The SMS was not sent: :reason', ['reason' => $message->error])),
        };
    }

    /**
     * Cash taken at the counter: renews the line exactly as a phone payment would.
     */
    public function recordCash(int $accountId, ServiceAccountRenewal $renewal): void
    {
        Gate::authorize(Permissions::PAYMENTS_RECORD);

        $account = $this->billedAccount($accountId);

        if ($account === null) {
            Flux::toast(variant: 'warning', text: __('Give this line a package and a router first.'));

            return;
        }

        $renewal->recordManual($account);

        unset($this->serviceAccounts);

        Flux::toast(variant: 'success', text: __('Payment recorded. The line is paid until :date.', ['date' => $account->refresh()->expires_at?->format('j M Y')]));
    }

    protected function billedAccount(int $accountId): ?ServiceAccount
    {
        $account = $this->customer->serviceAccounts()->with(['plan', 'router'])->findOrFail($accountId);

        Gate::authorize('update', $account);

        return $account->isBilled() ? $account : null;
    }

    public function addServiceAccount(CreateServiceAccount $create, ChangeServiceAccountStatus $change): void
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
            'planId' => ['nullable', Rule::exists('plans', 'id')->where('tenant_id', $this->customer->tenant_id)->where('type', PlanType::Pppoe->value)],
            'routerId' => ['nullable', 'required_with:planId', Rule::exists('routers', 'id')->where('tenant_id', $this->customer->tenant_id)],
            'paidUntil' => ['nullable', 'date'],
        ], [], [
            'planId' => __('package'),
            'routerId' => __('router'),
        ]);

        $isPppoe = $validated['serviceType'] === ServiceType::Pppoe->value;

        $account = $create->handle($this->customer, [
            'plan_id' => $isPppoe && $validated['planId'] ? (int) $validated['planId'] : null,
            'router_id' => $isPppoe && $validated['routerId'] ? (int) $validated['routerId'] : null,
            'type' => ServiceType::from($validated['serviceType']),
            'username' => $validated['username'],
            'secret' => $validated['secret'] !== '' ? $validated['secret'] : null,
            'label' => $validated['label'] !== '' ? $validated['label'] : null,
            'static_ip' => $validated['staticIp'] !== '' ? $validated['staticIp'] : null,
            'installation_address' => $validated['installationAddress'] !== '' ? $validated['installationAddress'] : null,
        ]);

        // Paid up already: the line goes live now. Otherwise it waits for its first payment,
        // which activates it.
        if ($isPppoe && $validated['paidUntil'] && $account->router_id !== null) {
            $account->expires_at = CarbonImmutable::parse($validated['paidUntil'])->endOfDay();
            $account->save();

            if ($account->expires_at->isFuture()) {
                $change->handle($account, ServiceAccountStatus::Active);
            }
        }

        $this->reset(['serviceType', 'username', 'secret', 'label', 'staticIp', 'installationAddress', 'planId', 'routerId', 'paidUntil']);
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
                <flux:table.column>{{ __('Package') }}</flux:table.column>
                <flux:table.column>{{ __('Paid until') }}</flux:table.column>
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
                        <flux:table.cell>{{ $account->plan?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            @php($days = $account->daysLeft())
                            @if ($account->suspended_for_nonpayment)
                                <flux:badge size="sm" color="red" inset="top bottom">{{ __('Cut — unpaid') }}</flux:badge>
                            @elseif ($days === null)
                                <flux:text size="sm" class="opacity-60">{{ $account->isBilled() ? __('Not paid yet') : '—' }}</flux:text>
                            @else
                                <div class="flex flex-col gap-0.5">
                                    <span class="text-sm">{{ $account->expires_at->format('j M Y') }}</span>
                                    @if ($days < 0)
                                        <flux:badge size="sm" color="red" inset="top bottom">{{ __('Overdue') }}</flux:badge>
                                    @elseif ($days === 0)
                                        <flux:badge size="sm" color="amber" inset="top bottom">{{ __('Due today') }}</flux:badge>
                                    @elseif ($days <= 3)
                                        <flux:badge size="sm" color="amber" inset="top bottom">{{ trans_choice('Due in :count day|Due in :count days', $days) }}</flux:badge>
                                    @endif
                                </div>
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
                                            @if ($account->type === App\Enums\ServiceType::Pppoe)
                                                @can('update', $account)
                                                    <flux:menu.item icon="calendar-days" wire:click="editBilling({{ $account->id }})">{{ __('Package & paid until') }}</flux:menu.item>
                                                    @if ($account->isBilled())
                                                        <flux:menu.item icon="device-phone-mobile" wire:click="sendPrompt({{ $account->id }})">{{ __('Send payment prompt') }}</flux:menu.item>
                                                        <flux:menu.item icon="chat-bubble-left-ellipsis" wire:click="sendReminder({{ $account->id }})">{{ __('Send reminder SMS') }}</flux:menu.item>
                                                        @can(App\Support\Authorization\Permissions::PAYMENTS_RECORD)
                                                            <flux:menu.item icon="banknotes" wire:click="recordCash({{ $account->id }})"
                                                                wire:confirm="{{ __('Record a cash payment of KES :amount and renew this line?', ['amount' => number_format((float) $account->plan?->price, 0)]) }}">
                                                                {{ __('Record cash payment') }}
                                                            </flux:menu.item>
                                                        @endcan
                                                    @endif
                                                    <flux:menu.separator />
                                                @endcan
                                            @endif

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
                        <flux:table.cell colspan="7" class="text-center">
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

            @if ($serviceType === ServiceType::Pppoe->value)
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="planId" :label="__('Package')" :placeholder="__('Choose…')">
                        @foreach ($this->pppoePlans as $plan)
                            <flux:select.option value="{{ $plan->id }}">{{ $plan->name }} — KES {{ number_format((float) $plan->price, 0) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="routerId" :label="__('Router')" :placeholder="__('Choose…')">
                        @foreach ($this->routers as $router)
                            <flux:select.option value="{{ $router->id }}">{{ $router->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:input type="date" wire:model="paidUntil" :label="__('Paid until')"
                    :description="__('Leave blank if they have not paid yet: the line goes live with their first payment.')" />
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

    <flux:modal name="edit-billing" class="w-full max-w-lg">
        <form wire:submit="saveBilling" class="flex flex-col gap-6">
            <div>
                <flux:heading size="lg">{{ __('Package & paid until') }}</flux:heading>
                <flux:subheading>
                    {{ __('Subscribers are texted before and on this date, cut off when it passes, and reconnected as soon as they pay.') }}
                </flux:subheading>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="billingPlanId" :label="__('Package')" :placeholder="__('Choose…')">
                    @foreach ($this->pppoePlans as $plan)
                        <flux:select.option value="{{ $plan->id }}">{{ $plan->name }} — KES {{ number_format((float) $plan->price, 0) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="billingRouterId" :label="__('Router')" :placeholder="__('Choose…')">
                    @foreach ($this->routers as $router)
                        <flux:select.option value="{{ $router->id }}">{{ $router->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:input type="date" wire:model="billingPaidUntil" :label="__('Paid until')" />

            @if ($this->billingAccount?->isBilled())
                <flux:input :value="$this->billingAccount->payUrl()" :label="__('Payment link')" readonly copyable
                    :description="__('Sent in every reminder. The subscriber pays from it with no account.')" />
            @endif

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save') }}</flux:button>
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
            </div>
        </form>
    </flux:modal>
</section>
