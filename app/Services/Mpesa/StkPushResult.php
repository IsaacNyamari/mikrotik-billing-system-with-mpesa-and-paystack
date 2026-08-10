<?php

namespace App\Services\Mpesa;

/**
 * What Safaricom said when we asked it to prompt a customer's phone.
 *
 * This only reports whether the prompt was accepted for delivery. Whether the customer
 * actually paid arrives later, on the callback.
 */
class StkPushResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly bool $accepted,
        public readonly ?string $checkoutRequestId = null,
        public readonly ?string $merchantRequestId = null,
        public readonly ?string $message = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function accepted(string $checkoutRequestId, string $merchantRequestId, array $raw = []): self
    {
        return new self(
            accepted: true,
            checkoutRequestId: $checkoutRequestId,
            merchantRequestId: $merchantRequestId,
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
