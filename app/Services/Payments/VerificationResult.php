<?php

namespace App\Services\Payments;

use App\Enums\PaymentStatus;

/**
 * What a provider says became of an attempt when we ask it directly.
 *
 * A webhook is the provider telling us; this is us asking. It carries the same facts a callback
 * would, in the shape the callback processor already settles from, so one path handles both.
 *
 * `$status` is the status the payment should take. `null` means the provider did not say —
 * unreachable, an answer we do not recognise, a reference it has never heard of — which is not
 * the same as a failure and must never settle anything.
 */
final class VerificationResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ?PaymentStatus $status,
        public readonly ?string $receipt = null,
        public readonly ?string $amount = null,
        public readonly ?string $phone = null,
        public readonly ?string $description = null,
        public readonly ?int $code = null,
        public readonly array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function paid(
        ?string $receipt = null,
        ?string $amount = null,
        ?string $phone = null,
        ?string $description = null,
        array $raw = [],
    ): self {
        return new self(PaymentStatus::Paid, $receipt, $amount, $phone, $description, 0, $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function failed(?string $description = null, array $raw = [], ?int $code = null): self
    {
        return new self(PaymentStatus::Failed, description: $description, code: $code, raw: $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function cancelled(?string $description = null, array $raw = [], ?int $code = null): self
    {
        return new self(PaymentStatus::Cancelled, description: $description, code: $code, raw: $raw);
    }

    /**
     * The prompt is still sitting on the customer's phone.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function pending(array $raw = []): self
    {
        return new self(PaymentStatus::Pending, raw: $raw);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function unknown(array $raw = []): self
    {
        return new self(null, raw: $raw);
    }

    /**
     * Whether this answer is worth acting on at all.
     */
    public function isConclusive(): bool
    {
        return $this->status !== null && $this->status->isSettled();
    }
}
