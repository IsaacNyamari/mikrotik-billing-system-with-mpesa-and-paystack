<?php

use App\Enums\PaymentProvider;
use App\Models\MpesaConfiguration;
use App\Models\PaystackConfiguration;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\Facades\Tenancy;
use App\Support\Authorization\Permissions;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payments')] class extends Component {
    /** Which provider takes this ISP's money. */
    public string $provider = 'mpesa';

    // Paystack, for an ISP with no Daraja app of its own.
    public string $paystack_secret_key = '';
    public string $paystack_public_key = '';
    public string $paystack_billing_email = '';

    public string $environment = 'sandbox';
    public string $short_code = '';
    public string $party_b = '';
    public string $transaction_type = 'CustomerPayBillOnline';
    public string $account_reference = 'INTERNET';
    public bool $is_active = true;

    // Left blank on an existing configuration, meaning "keep what is stored".
    public string $consumer_key = '';
    public string $consumer_secret = '';
    public string $passkey = '';

    public function mount(): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS);

        $this->provider = Tenancy::tenant()?->payment_provider
            ?? (PaystackConfiguration::query()->exists() && ! MpesaConfiguration::query()->exists()
                ? PaymentProvider::Paystack->value
                : PaymentProvider::Mpesa->value);

        $paystack = $this->paystack;

        if ($paystack !== null) {
            $this->paystack_public_key = (string) $paystack->public_key;
            $this->paystack_billing_email = (string) $paystack->billing_email;
        }

        $configuration = $this->configuration;

        if ($configuration === null) {
            return;
        }

        $this->fill([
            'environment' => $configuration->environment,
            'short_code' => $configuration->short_code,
            'party_b' => (string) $configuration->party_b,
            'transaction_type' => $configuration->transaction_type,
            'account_reference' => $configuration->account_reference,
            'is_active' => $configuration->is_active,
        ]);

        // Credentials are deliberately never sent to the browser.
    }

    #[Computed(persist: false)]
    public function configuration(): ?MpesaConfiguration
    {
        return MpesaConfiguration::query()->first();
    }

    #[Computed(persist: false)]
    public function paystack(): ?PaystackConfiguration
    {
        return PaystackConfiguration::query()->first();
    }

    /**
     * Which providers are ready to take money, so the screen can say plainly whether the one
     * selected will actually work.
     *
     * @return array<string, bool>
     */
    #[Computed(persist: false)]
    public function availability(): array
    {
        return app(PaymentGatewayManager::class)->availability();
    }

    /**
     * Record which provider is live.
     *
     * Kept on the tenant rather than inferred from whichever credentials exist, so an ISP that
     * has filled in both forms is in no doubt about which one customers are paying through.
     */
    public function chooseProvider(string $provider): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS);

        $chosen = PaymentProvider::tryFrom($provider);

        if ($chosen === null) {
            return;
        }

        $this->provider = $chosen->value;

        $tenant = Tenancy::tenant();

        if ($tenant !== null) {
            $tenant->payment_provider = $chosen->value;
            $tenant->save();
        }

        Flux::toast(text: __('Now collecting through :provider.', ['provider' => $chosen->label()]));
    }

    public function savePaystack(AuditLogger $audit): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS);

        $existing = $this->paystack;

        $validated = $this->validate([
            // Paystack encodes the mode in the key, so a test key cannot be mistaken for a
            // live one.
            'paystack_secret_key' => [$existing === null ? 'required' : 'nullable', 'string', 'regex:/^sk_(test|live)_[A-Za-z0-9]+$/'],
            'paystack_public_key' => ['nullable', 'string', 'max:255'],
            // Either a full address or a bare domain. Anything else reaches Paystack as a
            // malformed email and comes back as a flat "invalid email" that names no field.
            'paystack_billing_email' => ['required', 'string', 'max:255', 'regex:/^(?:[^@\s]+@[^@\s]+\.[^@\s]+|[^@\s]+\.[^@\s]+)$/'],
        ], [
            'paystack_secret_key.regex' => __('That does not look like a Paystack secret key — they start sk_test_ or sk_live_.'),
            'paystack_billing_email.regex' => __('Enter an email address like billing@my-isp.co.ke, or just a domain like my-isp.co.ke.'),
        ]);

        $configuration = $existing ?? new PaystackConfiguration;

        $configuration->fill([
            'public_key' => $validated['paystack_public_key'] !== '' ? $validated['paystack_public_key'] : null,
            'billing_email' => $validated['paystack_billing_email'],
            'is_active' => true,
        ]);

        if ($validated['paystack_secret_key'] !== '') {
            $configuration->secret_key = $validated['paystack_secret_key'];
        }

        $configuration->save();

        $this->reset('paystack_secret_key');
        unset($this->paystack, $this->availability);

        $audit->record(
            event: 'paystack.configured',
            auditable: $configuration,
            description: __('Saved Paystack credentials'),
        );

        Flux::toast(variant: 'success', text: __('Paystack settings saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $isNew = $this->configuration === null;

        return [
            'environment' => ['required', Rule::in(['sandbox', 'live'])],
            'short_code' => ['required', 'string', 'max:16'],
            'party_b' => ['nullable', 'string', 'max:16'],
            'transaction_type' => ['required', Rule::in(['CustomerPayBillOnline', 'CustomerBuyGoodsOnline'])],
            'account_reference' => ['required', 'string', 'max:12'],
            'is_active' => ['boolean'],
            'consumer_key' => [$isNew ? 'required' : 'nullable', 'string', 'max:255'],
            'consumer_secret' => [$isNew ? 'required' : 'nullable', 'string', 'max:255'],
            'passkey' => [$isNew ? 'required' : 'nullable', 'string', 'max:255'],
        ];
    }

    public function save(AuditLogger $audit): void
    {
        Gate::authorize(Permissions::SETTINGS_MANAGE_PAYMENT_CREDENTIALS);

        $validated = $this->validate();

        $configuration = $this->configuration ?? new MpesaConfiguration;
        $isNew = ! $configuration->exists;

        $configuration->fill([
            'environment' => $validated['environment'],
            'short_code' => $validated['short_code'],
            'party_b' => $validated['party_b'] !== '' ? $validated['party_b'] : null,
            'transaction_type' => $validated['transaction_type'],
            'account_reference' => $validated['account_reference'],
            'is_active' => $validated['is_active'],
        ]);

        foreach (['consumer_key', 'consumer_secret', 'passkey'] as $secret) {
            if ($validated[$secret] !== '') {
                $configuration->{$secret} = $validated[$secret];
            }
        }

        $configuration->save();

        // The values themselves never go near the audit trail — only the fact of a change.
        $audit->record(
            event: $isNew ? 'mpesa.configured' : 'mpesa.updated',
            auditable: $configuration,
            description: sprintf('M-PESA settings saved (%s, short code %s)', $configuration->environment, $configuration->short_code),
        );

        $this->reset(['consumer_key', 'consumer_secret', 'passkey']);
        unset($this->configuration);

        Flux::toast(variant: 'success', text: __('M-PESA settings saved.'));
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-2xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">{{ __('Payments') }}</flux:heading>
            <flux:subheading>{{ __('How customers pay you from the captive portal.') }}</flux:subheading>
        </div>

        {{-- The choice comes first: an ISP with no Daraja app should see immediately that
             there is a way forward, rather than reading a form they cannot fill in. --}}
        <div class="grid gap-4 sm:grid-cols-2">
            @foreach (PaymentProvider::cases() as $case)
                @php($ready = $this->availability[$case->value] ?? false)
                @php($chosen = $provider === $case->value)

                <flux:card @class(['flex flex-col gap-3', 'ring-2 ring-blue-600/40' => $chosen])>
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <flux:heading size="lg">{{ $case->label() }}</flux:heading>
                            <flux:text size="sm" class="opacity-70">{{ $case->description() }}</flux:text>
                        </div>

                        @if ($chosen)
                            <flux:badge size="sm" color="blue">{{ __('In use') }}</flux:badge>
                        @endif
                    </div>

                    <div class="mt-auto flex items-center gap-2">
                        <flux:badge size="sm" :color="$ready ? 'green' : 'zinc'">
                            {{ $ready ? __('Ready') : __('Not set up') }}
                        </flux:badge>

                        @unless ($chosen)
                            <flux:button size="sm" wire:click="chooseProvider('{{ $case->value }}')">
                                {{ __('Use this') }}
                            </flux:button>
                        @endunless
                    </div>
                </flux:card>
            @endforeach
        </div>

        @if (! ($this->availability[$provider] ?? false))
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Customers cannot pay yet') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('The provider you have chosen is not set up, so the portal tells customers to ask at the counter. Fill in its credentials below.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <flux:separator :text="__('M-PESA (Daraja)')" />

        @if ($this->configuration)
            <flux:card class="flex flex-col gap-3">
                <div>
                    <flux:heading size="lg">{{ __('Callback URL') }}</flux:heading>
                    <flux:subheading>
                        {{ __('Paste this into your Daraja app as the STK push callback. Safaricom posts payment results here.') }}
                    </flux:subheading>
                </div>

                <flux:input
                    readonly
                    copyable
                    value="{{ $this->configuration->callbackUrl() }}"
                    class="font-mono text-xs"
                />

                <flux:text size="sm" class="opacity-70">
                    {{ __('The code at the end is what proves a callback came from your account, so treat this URL as a secret. It must be reachable from the public internet.') }}
                </flux:text>
            </flux:card>
        @else
            <flux:callout icon="information-circle">
                <flux:callout.heading>{{ __('Not set up yet') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Until this is saved, customers cannot buy packages from the captive portal. Your callback URL is generated once you save.') }}
                </flux:callout.text>
            </flux:callout>
        @endif

        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:radio.group wire:model="environment" :label="__('Environment')" variant="segmented">
                <flux:radio value="sandbox" :label="__('Sandbox')" />
                <flux:radio value="live" :label="__('Live')" />
            </flux:radio.group>

            <div class="grid gap-6 sm:grid-cols-2">
                <flux:input
                    wire:model="short_code"
                    :label="__('Short code')"
                    required
                    :description="__('Your paybill or till number.')"
                />
                <flux:input
                    wire:model="party_b"
                    :label="__('Party B')"
                    :description="__('Only if money settles to a different number.')"
                />
            </div>

            <flux:select wire:model="transaction_type" :label="__('Transaction type')">
                <flux:select.option value="CustomerPayBillOnline">{{ __('Pay Bill') }}</flux:select.option>
                <flux:select.option value="CustomerBuyGoodsOnline">{{ __('Buy Goods (Till)') }}</flux:select.option>
            </flux:select>

            <flux:input
                wire:model="account_reference"
                :label="__('Account reference')"
                required
                :description="__('Shown on the customer\'s M-PESA message. Twelve characters at most.')"
            />

            <flux:separator :text="__('API credentials')" />

            @if ($this->configuration)
                <flux:text size="sm" class="opacity-70">
                    {{ __('Stored credentials are never shown again. Leave a field blank to keep it as it is.') }}
                </flux:text>
            @endif

            <flux:input
                wire:model="consumer_key"
                type="password"
                :label="__('Consumer key')"
                :required="! $this->configuration"
                autocomplete="off"
            />
            <flux:input
                wire:model="consumer_secret"
                type="password"
                :label="__('Consumer secret')"
                :required="! $this->configuration"
                autocomplete="off"
            />
            <flux:input
                wire:model="passkey"
                type="password"
                :label="__('Passkey')"
                :required="! $this->configuration"
                autocomplete="off"
                :description="__('The Lipa na M-PESA online passkey from your Daraja app.')"
            />

            <flux:switch
                wire:model="is_active"
                :label="__('Accept payments')"
                :description="__('Turn off to stop the portal selling without losing these settings.')"
            />

            <div>
                <flux:button variant="primary" type="submit" data-test="save-mpesa-button">
                    {{ __('Save settings') }}
                </flux:button>
            </div>
        </form>

        <flux:separator :text="__('Paystack')" />

        <flux:callout icon="information-circle">
            <flux:callout.heading>{{ __('No Daraja app? Use Paystack instead.') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('Paystack prompts the same M-PESA payment on the phone, with no Safaricom application of your own. It takes a cut and settles to your bank.') }}
            </flux:callout.text>
            <flux:callout.text>
                {{ __('Set your webhook URL in the Paystack dashboard to:') }}
                <span class="font-mono">{{ route('webhooks.paystack') }}</span>
            </flux:callout.text>
        </flux:callout>

        <form wire:submit="savePaystack" class="flex flex-col gap-6">
            @if ($this->paystack)
                <flux:text size="sm" class="opacity-70">
                    {{ __('A key is stored and is never shown again. Leave it blank to keep it.') }}
                    @if ($this->paystack->isLive())
                        <flux:badge size="sm" color="green" inset="top bottom">{{ __('live keys') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="amber" inset="top bottom">{{ __('test keys') }}</flux:badge>
                    @endif
                </flux:text>
            @endif

            <flux:input
                wire:model="paystack_secret_key"
                type="password"
                :label="__('Secret key')"
                :required="! $this->paystack"
                autocomplete="off"
                placeholder="sk_test_…"
                :description="__('From Settings → API Keys in your Paystack dashboard.')"
            />

            <flux:input wire:model="paystack_public_key" :label="__('Public key')" placeholder="pk_test_…" />

            <flux:input
                wire:model="paystack_billing_email"
                :label="__('Billing email')"
                required
                placeholder="billing@my-isp.co.ke"
                :description="__('Paystack needs an email on every payment, and a walk-in customer only gives a phone number. Enter your own address to use for all of them, or just a domain to give each customer their own.')"
            />

            <div>
                <flux:button variant="primary" type="submit" data-test="save-paystack-button">
                    {{ __('Save Paystack settings') }}
                </flux:button>
            </div>
        </form>
    </div>
</section>
