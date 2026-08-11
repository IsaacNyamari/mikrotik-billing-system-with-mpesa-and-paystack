<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Support\Tenancy\Facades\Tenancy;

/**
 * Decides which provider takes this ISP's money.
 *
 * The choice is recorded on the tenant rather than inferred from whichever credentials happen
 * to exist: an ISP that has set both up should still be in no doubt about which one is live.
 */
class PaymentGatewayManager
{
    public function __construct(
        protected DarajaPaymentGateway $daraja,
        protected PaystackGateway $paystack,
    ) {}

    /**
     * The gateway this ISP collects with, or null when nothing is set up.
     */
    public function active(): ?PaymentGateway
    {
        $chosen = $this->chosenProvider();

        if ($chosen !== null) {
            $gateway = $this->for($chosen);

            // A provider chosen but since unconfigured should fail loudly rather than
            // quietly collecting through the other one.
            return $gateway->isConfigured() ? $gateway : null;
        }

        // Nothing chosen: fall back to whichever is actually set up, so an ISP that only ever
        // filled in one form is not blocked by a preference they were never asked for.
        foreach ([$this->daraja, $this->paystack] as $gateway) {
            if ($gateway->isConfigured()) {
                return $gateway;
            }
        }

        return null;
    }

    public function for(PaymentProvider $provider): PaymentGateway
    {
        return match ($provider) {
            PaymentProvider::Mpesa => $this->daraja,
            PaymentProvider::Paystack => $this->paystack,
        };
    }

    /**
     * Every provider this ISP could switch to, and whether it is ready.
     *
     * @return array<string, bool>
     */
    public function availability(): array
    {
        return [
            PaymentProvider::Mpesa->value => $this->daraja->isConfigured(),
            PaymentProvider::Paystack->value => $this->paystack->isConfigured(),
        ];
    }

    protected function chosenProvider(): ?PaymentProvider
    {
        $value = Tenancy::tenant()?->payment_provider;

        return $value === null ? null : PaymentProvider::tryFrom($value);
    }
}
