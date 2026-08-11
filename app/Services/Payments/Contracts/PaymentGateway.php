<?php

namespace App\Services\Payments\Contracts;

use App\Enums\PaymentProvider;
use App\Models\Plan;
use App\Services\Payments\ChargeResult;

/**
 * Takes money from a customer, whoever the provider is.
 *
 * Both implementations prompt the customer's phone rather than redirecting them, because the
 * customer is sitting on a captive portal: sending them off to a hosted checkout means every
 * asset on that page needs a walled-garden hole first.
 */
interface PaymentGateway
{
    public function provider(): PaymentProvider;

    /**
     * Ask the provider to prompt the customer's phone.
     *
     * @param  string  $reference  Our own reference for this attempt, echoed back where the provider allows it.
     */
    public function charge(Plan $plan, string $phone, string $reference): ChargeResult;

    /**
     * Ask the provider what became of an attempt, for when a webhook never arrives.
     *
     * @return array<string, mixed>
     */
    public function verify(string $reference): array;

    /**
     * Whether this ISP has finished setting the provider up.
     */
    public function isConfigured(): bool;
}
