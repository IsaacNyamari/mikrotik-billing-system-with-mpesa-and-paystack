<?php

namespace App\Services\Payments\Contracts;

use App\Enums\PaymentProvider;
use App\Models\Plan;
use App\Services\Payments\ChargeResult;
use App\Services\Payments\VerificationResult;

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
     * Ask the provider what became of an attempt.
     *
     * This is the portal's real source of truth, not a fallback: a webhook only arrives if the
     * provider can reach this installation, which it cannot while the ISP is on a laptop, behind
     * NAT, or on a host whose tunnel is down. Asking works from anywhere with outbound internet.
     */
    public function verify(string $reference): VerificationResult;

    /**
     * How long to let a webhook land before asking.
     *
     * Only for providers whose answer is poorer than their callback: Daraja's query reports
     * whether the money moved but not the M-PESA code, and that code is what the customer logs
     * in with. Zero for a provider whose answer is complete.
     */
    public function verificationGraceSeconds(): int;

    /**
     * Whether this ISP has finished setting the provider up.
     */
    public function isConfigured(): bool;
}
