<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Models\MpesaConfiguration;
use App\Models\Plan;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Services\Payments\Contracts\PaymentGateway;

/**
 * The existing Daraja path, behind the provider-agnostic contract.
 *
 * A thin adapter rather than a rewrite: the M-PESA integration is the one that has been proven
 * against a real Safaricom account, so it is left exactly as it was and simply presented in the
 * shape the collector now expects.
 */
class DarajaPaymentGateway implements PaymentGateway
{
    public function __construct(protected MpesaGateway $daraja) {}

    public function provider(): PaymentProvider
    {
        return PaymentProvider::Mpesa;
    }

    public function charge(Plan $plan, string $phone, string $reference): ChargeResult
    {
        $configuration = $this->configuration();

        if ($configuration === null) {
            return ChargeResult::rejected(__('M-PESA is not set up for this ISP.'));
        }

        $result = $this->daraja->stkPush(
            configuration: $configuration,
            phone: $phone,
            // Daraja rejects decimals, and rounding down would undercharge the ISP.
            amount: (int) ceil((float) $plan->price),
            accountReference: $configuration->account_reference,
            description: $plan->name,
            callbackUrl: $configuration->callbackUrl(),
        );

        return $result->accepted
            ? ChargeResult::accepted(
                (string) $result->checkoutRequestId,
                $result->merchantRequestId,
                $result->raw,
            )
            : ChargeResult::rejected((string) $result->message, $result->raw);
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $reference): array
    {
        $configuration = $this->configuration();

        return $configuration === null ? [] : $this->daraja->query($configuration, $reference);
    }

    public function isConfigured(): bool
    {
        return $this->configuration() !== null;
    }

    protected function configuration(): ?MpesaConfiguration
    {
        return MpesaConfiguration::query()->where('is_active', true)->first();
    }
}
