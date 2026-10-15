<?php

use App\Models\PlatformPaymentSetting;
use App\Services\Platform\PlatformTill;
use App\Services\Platform\SubscriptionCollector;
use App\Support\Audit\AuditLogger;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * How the platform collects its own fees from ISPs — set here, by the platform's owner.
 *
 * Before this page the only way to switch collection on was to edit the server's environment,
 * and an ISP looking at a bill was told the name of a variable they could never reach. Secrets
 * are write-only: they are never sent back to the browser, and leaving a field empty keeps
 * what is stored.
 *
 * Super admins only. An ISP never sees, reads or changes any of this.
 */
new #[Title('Platform payments')] class extends Component {
    public string $preferredProvider = '';

    public bool $mpesaEnabled = false;

    public string $mpesaEnvironment = 'production';

    public string $mpesaShortCode = '';

    public string $mpesaPartyB = '';

    public string $mpesaTransactionType = 'CustomerPayBillOnline';

    public string $mpesaAccountReference = '';

    public string $mpesaConsumerKey = '';

    public string $mpesaConsumerSecret = '';

    public string $mpesaPasskey = '';

    public bool $paystackEnabled = false;

    public string $paystackPublicKey = '';

    public string $paystackSecretKey = '';

    public string $paystackCurrency = 'KES';

    public string $paystackBillingEmail = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() === true, 403);

        $settings = PlatformPaymentSetting::current();

        $this->preferredProvider = (string) $settings->preferred_provider;
        $this->mpesaEnabled = $settings->mpesa_enabled;
        $this->mpesaEnvironment = $settings->mpesa_environment;
        $this->mpesaShortCode = (string) $settings->mpesa_short_code;
        $this->mpesaPartyB = (string) $settings->mpesa_party_b;
        $this->mpesaTransactionType = $settings->mpesa_transaction_type;
        $this->mpesaAccountReference = (string) $settings->mpesa_account_reference;
        $this->paystackEnabled = $settings->paystack_enabled;
        $this->paystackPublicKey = (string) $settings->paystack_public_key;
        $this->paystackCurrency = $settings->paystack_currency;
        $this->paystackBillingEmail = (string) $settings->paystack_billing_email;
    }

    /**
     * Which secrets are already stored, so the form can say "saved" without ever showing one.
     *
     * @return array<string, bool>
     */
    #[Computed]
    public function stored(): array
    {
        $settings = PlatformPaymentSetting::current();

        return [
            'mpesa_consumer_key' => filled($settings->mpesa_consumer_key),
            'mpesa_consumer_secret' => filled($settings->mpesa_consumer_secret),
            'mpesa_passkey' => filled($settings->mpesa_passkey),
            'paystack_secret_key' => filled($settings->paystack_secret_key),
        ];
    }

    /**
     * What an ISP sees on their billing page right now.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function available(): array
    {
        return app(SubscriptionCollector::class)->availableProviders();
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless(auth()->user()?->isSuperAdmin() === true, 403);

        $this->validate([
            'preferredProvider' => ['nullable', 'in:mpesa,paystack'],
            'mpesaEnvironment' => ['required', 'in:production,sandbox'],
            'mpesaShortCode' => ['nullable', 'required_if:mpesaEnabled,true', 'regex:/^\d{5,7}$/'],
            'mpesaPartyB' => ['nullable', 'regex:/^\d{5,7}$/'],
            'mpesaTransactionType' => ['required', 'in:CustomerPayBillOnline,CustomerBuyGoodsOnline'],
            'mpesaAccountReference' => ['nullable', 'string', 'max:12'],
            'mpesaConsumerKey' => ['nullable', 'string', 'max:255'],
            'mpesaConsumerSecret' => ['nullable', 'string', 'max:255'],
            'mpesaPasskey' => ['nullable', 'string', 'max:255'],
            'paystackPublicKey' => ['nullable', 'string', 'max:255', 'regex:/^pk_(test|live)_/'],
            'paystackSecretKey' => ['nullable', 'string', 'max:255', 'regex:/^sk_(test|live)_/'],
            'paystackCurrency' => ['required', 'in:KES,NGN,GHS,ZAR,USD'],
            'paystackBillingEmail' => ['nullable', 'email'],
        ], [
            'mpesaShortCode.regex' => __('A paybill or till number is 5 to 7 digits.'),
            'paystackSecretKey.regex' => __('A Paystack secret key starts sk_live_ or sk_test_.'),
            'paystackPublicKey.regex' => __('A Paystack public key starts pk_live_ or pk_test_.'),
        ]);

        $settings = PlatformPaymentSetting::current();

        $settings->fill([
            'preferred_provider' => $this->preferredProvider !== '' ? $this->preferredProvider : null,
            'mpesa_enabled' => $this->mpesaEnabled,
            'mpesa_environment' => $this->mpesaEnvironment,
            'mpesa_short_code' => $this->mpesaShortCode !== '' ? $this->mpesaShortCode : null,
            'mpesa_party_b' => $this->mpesaPartyB !== '' ? $this->mpesaPartyB : null,
            'mpesa_transaction_type' => $this->mpesaTransactionType,
            'mpesa_account_reference' => $this->mpesaAccountReference !== '' ? $this->mpesaAccountReference : null,
            'paystack_enabled' => $this->paystackEnabled,
            'paystack_public_key' => $this->paystackPublicKey !== '' ? $this->paystackPublicKey : null,
            'paystack_currency' => $this->paystackCurrency,
            'paystack_billing_email' => $this->paystackBillingEmail !== '' ? $this->paystackBillingEmail : null,
        ]);

        // Secrets only when typed: an empty box means "keep what is stored", never "erase it".
        foreach ([
            'mpesaConsumerKey' => 'mpesa_consumer_key',
            'mpesaConsumerSecret' => 'mpesa_consumer_secret',
            'mpesaPasskey' => 'mpesa_passkey',
            'paystackSecretKey' => 'paystack_secret_key',
        ] as $property => $column) {
            if (trim($this->{$property}) !== '') {
                $settings->{$column} = trim($this->{$property});
            }

            $this->{$property} = '';
        }

        $settings->save();

        $audit->record(
            event: 'platform.payment_settings_updated',
            auditable: $settings,
            description: sprintf(
                'Platform payments: M-PESA %s, Paystack %s',
                $settings->mpesaIsComplete() ? 'on' : 'off',
                $settings->paystackIsComplete() ? 'on' : 'off',
            ),
        );

        unset($this->stored, $this->available);

        Flux::toast(variant: 'success', text: __('Saved. ISPs see the change on their billing page straight away.'));
    }

    #[Computed]
    public function mpesaCallbackUrl(): ?string
    {
        $settings = PlatformPaymentSetting::current();

        return $settings->exists && filled($settings->mpesa_callback_token)
            ? route('webhooks.platform-mpesa', ['token' => $settings->mpesa_callback_token])
            : null;
    }
}; ?>

<section class="w-full">
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-6">
        <div>
            <flux:heading size="xl">{{ __('Platform payments') }}</flux:heading>
            <flux:subheading>{{ __('How ISPs pay you: the setup fee, the HotSpot commission and the PPPoE lines. They choose an invoice on their billing page and get a prompt on their phone.') }}</flux:subheading>
        </div>

        @if ($this->available === [])
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('ISPs cannot pay online yet') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Switch on M-PESA, Paystack or both below. Until then their billing page says online payment is not available.') }}</flux:callout.text>
            </flux:callout>
        @else
            <flux:callout variant="success" icon="check-circle">
                <flux:callout.heading>{{ __('ISPs can pay with: :methods', ['methods' => implode(', ', $this->available)]) }}</flux:callout.heading>
            </flux:callout>
        @endif

        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:card class="flex flex-col gap-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">M-PESA (Daraja)</flux:heading>
                        <flux:subheading>{{ __('A prompt straight to the ISP\'s phone, into your paybill or till.') }}</flux:subheading>
                    </div>
                    <flux:switch wire:model.live="mpesaEnabled" :label="__('On')" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:select wire:model="mpesaEnvironment" :label="__('Environment')">
                        <flux:select.option value="production">{{ __('Live') }}</flux:select.option>
                        <flux:select.option value="sandbox">{{ __('Sandbox (testing)') }}</flux:select.option>
                    </flux:select>
                    <flux:select wire:model.live="mpesaTransactionType" :label="__('Collect into')">
                        <flux:select.option value="CustomerPayBillOnline">{{ __('A paybill') }}</flux:select.option>
                        <flux:select.option value="CustomerBuyGoodsOnline">{{ __('A till (Buy Goods)') }}</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="mpesaShortCode" :label="__('Business short code')" :description="__('The shortcode your Daraja app is registered to.')" />
                    <flux:input wire:model="mpesaPartyB" :label="__('Till number')" :description="__('Only for a till that differs from the short code.')" />
                    <flux:input wire:model="mpesaAccountReference" :label="__('Account reference')" placeholder="Subscription" :description="__('Shown on the ISP\'s M-PESA message. Up to 12 characters.')" />
                </div>

                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach (['mpesaConsumerKey' => ['Consumer key', 'mpesa_consumer_key'], 'mpesaConsumerSecret' => ['Consumer secret', 'mpesa_consumer_secret'], 'mpesaPasskey' => ['Passkey', 'mpesa_passkey']] as $property => [$label, $column])
                        <flux:input wire:model="{{ $property }}" type="password" viewable :label="__($label)"
                            :placeholder="$this->stored[$column] ? __('Saved — type to replace') : ''" />
                    @endforeach
                </div>

                @if ($this->mpesaCallbackUrl)
                    <flux:text size="sm" class="opacity-70">
                        {{ __('Results come back to:') }} <span class="font-mono break-all">{{ $this->mpesaCallbackUrl }}</span>
                    </flux:text>
                @endif
            </flux:card>

            <flux:card class="flex flex-col gap-5">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <flux:heading size="lg">Paystack</flux:heading>
                        <flux:subheading>{{ __('A mobile-money prompt to the ISP\'s phone, settled into your Paystack account.') }}</flux:subheading>
                    </div>
                    <flux:switch wire:model.live="paystackEnabled" :label="__('On')" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:input wire:model="paystackSecretKey" type="password" viewable :label="__('Secret key')"
                        :placeholder="$this->stored['paystack_secret_key'] ? __('Saved — type to replace') : 'sk_live_…'" />
                    <flux:input wire:model="paystackPublicKey" :label="__('Public key')" placeholder="pk_live_…" />
                    <flux:select wire:model="paystackCurrency" :label="__('Currency')">
                        @foreach (['KES', 'NGN', 'GHS', 'ZAR', 'USD'] as $currency)
                            <flux:select.option :value="$currency">{{ $currency }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input wire:model="paystackBillingEmail" type="email" :label="__('Fallback billing email')" :description="__('Used when an ISP has no contact email; Paystack needs one per charge.')" />
                </div>

                <flux:text size="sm" class="opacity-70">
                    {{ __('Webhook URL to set in your Paystack dashboard:') }} <span class="font-mono break-all">{{ route('webhooks.platform-paystack') }}</span>
                </flux:text>
            </flux:card>

            <flux:card class="flex flex-col gap-4">
                <flux:select wire:model="preferredProvider" :label="__('Offer first')" :description="__('When both are on, ISPs see this one selected.')">
                    <flux:select.option value="">{{ __('No preference') }}</flux:select.option>
                    <flux:select.option value="mpesa">M-PESA</flux:select.option>
                    <flux:select.option value="paystack">Paystack</flux:select.option>
                </flux:select>
            </flux:card>

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </div>
</section>
