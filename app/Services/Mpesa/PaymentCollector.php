<?php

namespace App\Services\Mpesa;

use App\Enums\PaymentStatus;
use App\Models\MpesaConfiguration;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Support\PhoneNumber;
use RuntimeException;

/**
 * Starts a payment: records the intent, then asks Safaricom to prompt the phone.
 */
class PaymentCollector
{
    public function __construct(protected MpesaGateway $gateway) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function collect(Plan $plan, Router $router, string $phone, array $attributes = []): Payment
    {
        $configuration = MpesaConfiguration::query()->where('is_active', true)->first();

        if ($configuration === null) {
            throw new RuntimeException('This ISP has not set up M-PESA yet.');
        }

        // Normalised here rather than inside the gateway, so the stored number is comparable
        // whichever collector is in use.
        $normalised = PhoneNumber::normalise($phone);

        // Recorded before the prompt goes out, so a customer who pays against a request we
        // failed to record cannot happen.
        $payment = new Payment(array_merge([
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'phone' => $normalised,
            'amount' => $plan->price,
        ], $attributes));

        $payment->save();

        $result = $this->gateway->stkPush(
            configuration: $configuration,
            phone: $normalised,
            // Daraja rejects decimals, and rounding down would undercharge the ISP.
            amount: (int) ceil((float) $plan->price),
            accountReference: $configuration->account_reference,
            description: $plan->name,
            callbackUrl: $configuration->callbackUrl(),
        );

        if (! $result->accepted) {
            $payment->status = PaymentStatus::Failed;
            $payment->result_description = $result->message;
            $payment->failed_at = now();
            $payment->callback_payload = $result->raw;
            $payment->save();

            return $payment;
        }

        $payment->checkout_request_id = $result->checkoutRequestId;
        $payment->merchant_request_id = $result->merchantRequestId;
        $payment->request_payload = $result->raw;
        $payment->save();

        return $payment;
    }
}
