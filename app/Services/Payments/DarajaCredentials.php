<?php

namespace App\Services\Payments;

use App\Models\MpesaConfiguration;

/**
 * Works out which Daraja app signs an ISP's collections.
 *
 * An ISP should not have to obtain consumer keys, a passkey and a Daraja app of their own
 * before they can sell an hour of wifi. Most of them have a till and nothing else, and the
 * ones who go and get the rest usually come back with something pasted into the wrong field.
 *
 * So the platform's Daraja app does the signing and the ISP supplies only where the money
 * should land. Daraja separates the two: `BusinessShortCode` is who the request authenticates
 * as, `PartyB` is who gets paid. That is the shape aggregators use, and this builds it.
 *
 * **What this cannot do on its own.** Safaricom will not route money to a `PartyB` the
 * platform's shortcode is not authorised for — that is their rule, and no code here changes
 * it. Whoever runs this platform needs the arrangement with Safaricom that links their ISPs'
 * tills to the head-office shortcode. Where that does not exist, Daraja refuses the push and
 * the refusal is recorded on the payment like any other.
 *
 * An ISP that already holds its own Daraja app keeps using it. Their credentials are on their
 * own row, and a working setup is not taken away because a better default arrived.
 */
class DarajaCredentials
{
    /**
     * Whether the platform can sign on an ISP's behalf.
     */
    public function platformCanSign(): bool
    {
        foreach (['consumer_key', 'consumer_secret', 'short_code', 'passkey'] as $key) {
            if (blank(config("platform.mpesa.{$key}"))) {
                return false;
            }
        }

        return true;
    }

    /**
     * The configuration to send a request with, for this ISP.
     *
     * Never saved. It carries the platform's credentials, which have no business being
     * written into a tenant-owned table.
     */
    public function resolve(?MpesaConfiguration $tenantConfiguration): ?MpesaConfiguration
    {
        if ($tenantConfiguration === null) {
            return null;
        }

        // Their own app, their own credentials. Nothing to substitute.
        if ($this->hasOwnApp($tenantConfiguration)) {
            return $tenantConfiguration;
        }

        if (! $this->platformCanSign()) {
            return null;
        }

        $effective = new MpesaConfiguration([
            'environment' => (string) config('platform.mpesa.environment'),
            // Signed as the platform's shortcode…
            'short_code' => (string) config('platform.mpesa.short_code'),
            // …but paid to the ISP's till. This one line is the whole arrangement.
            'party_b' => $tenantConfiguration->collectionNumber(),
            'transaction_type' => $tenantConfiguration->transaction_type,
            'consumer_key' => (string) config('platform.mpesa.consumer_key'),
            'consumer_secret' => (string) config('platform.mpesa.consumer_secret'),
            'passkey' => (string) config('platform.mpesa.passkey'),
            'account_reference' => $tenantConfiguration->account_reference,
        ]);

        // The ISP's own token, so Safaricom's callback still finds the ISP it belongs to
        // rather than the platform. Not fillable — it authenticates the callback.
        $effective->callback_token = $tenantConfiguration->callback_token;

        return $effective;
    }

    /**
     * Whether this ISP brought their own Daraja app.
     */
    public function hasOwnApp(MpesaConfiguration $configuration): bool
    {
        return filled($configuration->consumer_key)
            && filled($configuration->consumer_secret)
            && filled($configuration->passkey);
    }
}
