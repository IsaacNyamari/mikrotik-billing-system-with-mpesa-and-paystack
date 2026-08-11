<?php

namespace App\Services\Payments;

/**
 * What a provider said when asked to take money from a customer.
 *
 * Reports only whether the request was accepted for delivery. Whether the customer actually
 * paid arrives later, on a webhook — no provider is trusted to grant access at this point.
 */
class ChargeResult
{
    /**
     * @param  string|null  $reference  The provider's handle for this attempt, quoted back on the webhook.
     * @param  string|null  $secondaryReference  A second id where the provider issues one.
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $reference = null,
        public readonly ?string $secondaryReference = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function accepted(string $reference, ?string $secondaryReference = null, array $raw = []): self
    {
        return new self(
            accepted: true,
            reference: $reference,
            secondaryReference: $secondaryReference,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function rejected(string $message, array $raw = []): self
    {
        return new self(accepted: false, message: $message, raw: $raw);
    }
}
