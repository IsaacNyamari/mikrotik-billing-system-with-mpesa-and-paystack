<?php

namespace App\Services\Platform;

use App\Models\PaystackConfiguration;
use App\Models\PlatformPaymentSetting;
use App\Models\Tenant;

/**
 * The platform's own Paystack account, for collecting subscriptions from ISPs.
 *
 * Distinct from the per-ISP rows in `paystack_configurations`: those collect an ISP's money
 * from their customers, this collects the platform's money from the ISP. Set by the platform's
 * owner under Platform admin → Platform payments; the environment is still read when nothing
 * has been saved there.
 *
 * The configuration is handed back as an unsaved `PaystackConfiguration` so the gateway proven
 * against a real Paystack account is reused unchanged. It is never saved: writing it would put
 * the platform's key into a table the tenant scope owns, where an ISP could reach it.
 */
class PlatformPaystack
{
    public function isConfigured(): bool
    {
        return $this->configuration() !== null;
    }

    /**
     * In words for whoever is looking — never a setting's name: an ISP reads this too.
     */
    public function unconfiguredReason(): ?string
    {
        if ($this->isConfigured()) {
            return null;
        }

        return __('Paying through Paystack is not available yet.');
    }

    public function configuration(): ?PaystackConfiguration
    {
        $settings = PlatformPaymentSetting::current();

        if ($settings->paystackIsComplete()) {
            $configuration = new PaystackConfiguration([
                'currency' => $settings->paystack_currency,
                'public_key' => (string) $settings->paystack_public_key,
            ]);
            // Not fillable on purpose — it is the credential.
            $configuration->secret_key = (string) $settings->paystack_secret_key;

            return $configuration;
        }

        if (config('platform.paystack.enabled') !== true || blank(config('platform.paystack.secret_key'))) {
            return null;
        }

        $configuration = new PaystackConfiguration([
            'currency' => (string) config('platform.paystack.currency'),
            'public_key' => (string) config('platform.paystack.public_key'),
        ]);
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
        $settings = PlatformPaymentSetting::current();

        foreach ([$tenant->contact_email, $settings->paystack_billing_email, config('platform.paystack.billing_email')] as $candidate) {
            if (filter_var((string) $candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return (string) $candidate;
            }
        }

        return null;
    }
}
