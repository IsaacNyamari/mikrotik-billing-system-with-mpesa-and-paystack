<?php

namespace App\Services\Platform;

use App\Models\MpesaConfiguration;

/**
 * The platform's own M-PESA credentials.
 *
 * Every other set of credentials in this system belongs to an ISP and lives encrypted in
 * `mpesa_configurations`, one row per tenant. These are the platform's, there is exactly one
 * set, and they collect from ISPs rather than from their customers — so they come from the
 * environment, like any other single-installation secret.
 *
 * The configuration is handed back as an unsaved `MpesaConfiguration` so the Daraja gateway
 * proven against a real Safaricom account can be reused unchanged. It is never saved: writing
 * it would put the platform's till into a table the tenant scope owns.
 */
class PlatformTill
{
    public function isConfigured(): bool
    {
        if (config('platform.mpesa.enabled') !== true) {
            return false;
        }

        foreach (['consumer_key', 'consumer_secret', 'short_code', 'passkey', 'callback_token'] as $key) {
            if (blank(config("platform.mpesa.{$key}"))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Why an operator cannot collect yet, for a screen that has to explain itself.
     */
    public function unconfiguredReason(): ?string
    {
        if ($this->isConfigured()) {
            return null;
        }

        return config('platform.mpesa.enabled') === true
            ? __('The platform till is missing credentials. Set PLATFORM_MPESA_* in the environment.')
            : __('Collecting subscription payments by M-PESA is switched off. Set PLATFORM_MPESA_ENABLED=true.');
    }

    public function configuration(): ?MpesaConfiguration
    {
        if (! $this->isConfigured()) {
            return null;
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

        // Not fillable — the token authenticates Safaricom's callback and is never accepted
        // from a form on the tenant model either.
        $configuration->callback_token = (string) config('platform.mpesa.callback_token');

        return $configuration;
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
        return route('webhooks.platform-mpesa', ['token' => (string) config('platform.mpesa.callback_token')]);
    }
}
