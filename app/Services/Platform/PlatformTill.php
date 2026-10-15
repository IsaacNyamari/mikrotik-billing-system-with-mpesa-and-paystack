<?php

namespace App\Services\Platform;

use App\Models\MpesaConfiguration;
use App\Models\PlatformPaymentSetting;

/**
 * The platform's own M-PESA credentials.
 *
 * Every other set of credentials in this system belongs to an ISP and lives encrypted in
 * `mpesa_configurations`, one row per tenant. These are the platform's: exactly one set, and
 * they collect from ISPs rather than from their customers.
 *
 * Set by the platform's owner under Platform admin → Platform payments, and kept encrypted in
 * `platform_payment_settings`. The environment is still read when nothing has been saved
 * there, so an installation configured that way keeps working unchanged.
 *
 * The configuration is handed back as an unsaved `MpesaConfiguration` so the Daraja gateway
 * proven against a real Safaricom account can be reused unchanged. It is never saved: writing
 * it would put the platform's till into a table the tenant scope owns.
 */
class PlatformTill
{
    public function isConfigured(): bool
    {
        return $this->fromSettings() !== null || $this->fromEnvironment() !== null;
    }

    /**
     * Why it cannot collect, in words for whoever is looking. Never the name of a setting or
     * a file: an ISP reads this too, and has no access to either.
     */
    public function unconfiguredReason(): ?string
    {
        if ($this->isConfigured()) {
            return null;
        }

        return __('Paying by M-PESA is not available yet.');
    }

    public function configuration(): ?MpesaConfiguration
    {
        return $this->fromSettings() ?? $this->fromEnvironment();
    }

    /**
     * Where Safaricom posts the result of a subscription payment.
     *
     * A route of its own rather than the tenant callback: that endpoint looks the payment up
     * in `payments` and would find nothing, and an ISP paying the platform must never be
     * mistaken for a customer buying wifi.
     */
    public function callbackUrl(): string
    {
        return route('webhooks.platform-mpesa', ['token' => $this->callbackToken()]);
    }

    /**
     * The token Safaricom's callback must carry, from whichever source is in use.
     */
    public function callbackToken(): string
    {
        $settings = PlatformPaymentSetting::current();

        return $settings->mpesaIsComplete()
            ? (string) $settings->mpesa_callback_token
            : (string) config('platform.mpesa.callback_token');
    }

    protected function fromSettings(): ?MpesaConfiguration
    {
        $settings = PlatformPaymentSetting::current();

        if (! $settings->mpesaIsComplete()) {
            return null;
        }

        $configuration = new MpesaConfiguration([
            'environment' => $settings->mpesa_environment,
            'short_code' => (string) $settings->mpesa_short_code,
            'party_b' => $settings->mpesa_party_b ?: null,
            'transaction_type' => $settings->mpesa_transaction_type,
            'consumer_key' => (string) $settings->mpesa_consumer_key,
            'consumer_secret' => (string) $settings->mpesa_consumer_secret,
            'passkey' => (string) $settings->mpesa_passkey,
            'account_reference' => (string) ($settings->mpesa_account_reference ?: 'Subscription'),
        ]);

        // Not fillable — the token authenticates Safaricom's callback and is never accepted
        // from a form on the tenant model either.
        $configuration->callback_token = (string) $settings->mpesa_callback_token;

        return $configuration;
    }

    protected function fromEnvironment(): ?MpesaConfiguration
    {
        if (config('platform.mpesa.enabled') !== true) {
            return null;
        }

        foreach (['consumer_key', 'consumer_secret', 'short_code', 'passkey', 'callback_token'] as $key) {
            if (blank(config("platform.mpesa.{$key}"))) {
                return null;
            }
        }

        $configuration = new MpesaConfiguration([
            'environment' => (string) config('platform.mpesa.environment'),
            'short_code' => (string) config('platform.mpesa.short_code'),
            'transaction_type' => (string) config('platform.mpesa.transaction_type'),
            'consumer_key' => (string) config('platform.mpesa.consumer_key'),
            'consumer_secret' => (string) config('platform.mpesa.consumer_secret'),
            'passkey' => (string) config('platform.mpesa.passkey'),
            'account_reference' => (string) config('platform.mpesa.account_reference'),
        ]);

        $configuration->callback_token = (string) config('platform.mpesa.callback_token');

        return $configuration;
    }
}
