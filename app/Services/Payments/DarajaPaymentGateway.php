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
    /** Safaricom's code for a prompt the customer is still looking at. */
    protected const STILL_PROCESSING = '500.001.1001';

    /** Safaricom's ResultCode for a prompt the customer dismissed. */
    protected const CANCELLED_BY_USER = 1032;

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

    public function verify(string $reference): VerificationResult
    {
        $configuration = $this->configuration();

        return $configuration === null
            ? VerificationResult::unknown()
            : $this->interpret($this->daraja->query($configuration, $reference));
    }

    /**
     * Daraja's callback carries the M-PESA code; its query does not. Since that code is what the
     * customer's HotSpot login is named after, the callback is given a moment to arrive before
     * we settle from an answer that cannot supply one.
     */
    public function verificationGraceSeconds(): int
    {
        return 20;
    }

    /**
     * Read an stkpushquery answer.
     *
     * @param  array<string, mixed>  $body
     */
    public function interpret(array $body): VerificationResult
    {
        if ($body === []) {
            return VerificationResult::unknown();
        }

        // Daraja answers a query about a prompt still on the customer's phone with an error
        // rather than a status: "the transaction is being processed".
        if (isset($body['errorCode'])) {
            return (string) $body['errorCode'] === self::STILL_PROCESSING
                ? VerificationResult::pending($body)
                : VerificationResult::unknown($body);
        }

        if (! isset($body['ResultCode'])) {
            return VerificationResult::unknown($body);
        }

        $code = (int) $body['ResultCode'];
        $description = isset($body['ResultDesc']) ? (string) $body['ResultDesc'] : null;

        if ($code === 0) {
            return VerificationResult::paid(description: $description, raw: $body);
        }

        // 1032 is Safaricom for "the customer pressed cancel", and is the one failure they
        // already know about before the page tells them.
        return $code === self::CANCELLED_BY_USER
            ? VerificationResult::cancelled($description, $body, $code)
            : VerificationResult::failed($description, $body, $code);
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
