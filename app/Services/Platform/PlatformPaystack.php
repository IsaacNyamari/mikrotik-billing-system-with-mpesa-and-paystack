<?php

namespace App\Services\Platform;

use App\Models\PaystackConfiguration;
use App\Models\Tenant;

/**
 * The platform's own Paystack account, for collecting subscriptions from ISPs.
 *
 * Distinct from the per-ISP rows in `paystack_configurations`: those collect an ISP's money
 * from their customers, this collects the platform's money from the ISP. One set for the whole
 * installation, so it lives in the environment like any other single-installation secret.
 *
 * The configuration is handed back as an unsaved `PaystackConfiguration` so the gateway proven
 * against a real Paystack account is reused unchanged. It is never saved: writing it would put
 * the platform's key into a table the tenant scope owns, where an ISP could reach it.
 */
class PlatformPaystack
{
    public function isConfigured(): bool
    {
        return config('platform.paystack.enabled') === true
            && filled(config('platform.paystack.secret_key'));
    }

    public function unconfiguredReason(): ?string
    {
        if ($this->isConfigured()) {
            return null;
        }

        return config('platform.paystack.enabled') === true
            ? __('The platform Paystack account is missing its secret key.')
            : __('Collecting subscriptions through Paystack is switched off.');
    }

    public function configuration(): ?PaystackConfiguration
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $configuration = new PaystackConfiguration([
            'currency' => (string) config('platform.paystack.currency'),
            'public_key' => (string) config('platform.paystack.public_key'),
        ]);

        // Not fillable on purpose — it is the credential.
        $configuration->secret_key = (string) config('platform.paystack.secret_key');

        return $configuration;
    }

    /**
     * The address Paystack is given for a subscription charge.
     *
     * The ISP's own, so their receipts reach them. Paystack rejects a malformed address with a
     * flat "invalid email" that says nothing about which field is at fault, so nothing
     * unvalidated is ever sent.
     */
    public function emailFor(Tenant $tenant): ?string
    {
        foreach ([$tenant->contact_email, config('platform.paystack.billing_email')] as $candidate) {
            if (filter_var((string) $candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
