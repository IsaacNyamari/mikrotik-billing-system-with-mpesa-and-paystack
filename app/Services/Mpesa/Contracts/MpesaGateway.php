<?php

namespace App\Services\Mpesa\Contracts;

use App\Models\MpesaConfiguration;
use App\Services\Mpesa\StkPushResult;

/**
 * Talks to Safaricom's Daraja API.
 *
 * Behind an interface so the portal and the payment flow can be tested without reaching
 * Safaricom, and so a different collector could be added later.
 */
interface MpesaGateway
{
    /**
     * Ask Safaricom to prompt the customer's phone for a payment.
     */
    public function stkPush(
        MpesaConfiguration $configuration,
        string $phone,
        int $amount,
        string $accountReference,
        string $description,
        string $callbackUrl,
    ): StkPushResult;

    /**
     * Ask Safaricom what became of a prompt, for when the callback never arrives.
     *
     * @return array<string, mixed>
     */
    public function query(MpesaConfiguration $configuration, string $checkoutRequestId): array;
}
