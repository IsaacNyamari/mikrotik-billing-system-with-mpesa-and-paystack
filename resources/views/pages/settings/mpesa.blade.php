<?php

use App\Models\MpesaConfiguration;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\Permissions;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('M-PESA settings')] class extends Component {
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
            <flux:heading size="xl">{{ __('M-PESA') }}</flux:heading>
            <flux:subheading>
                {{ __('Lipa na M-PESA credentials for collecting HotSpot payments into your own till.') }}
            </flux:subheading>
        </div>

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
    </div>
</section>
