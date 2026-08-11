<?php

namespace App\Services\Mpesa;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Services\Payments\PaymentGatewayManager;
use App\Support\PhoneNumber;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Starts a payment: records the intent, then asks the ISP's provider to prompt the phone.
 *
 * Which provider that is depends on what the ISP has set up — Daraja for one with its own
 * Safaricom app, Paystack for one without. Nothing above this class needs to know which.
 */
class PaymentCollector
{
    public function __construct(protected PaymentGatewayManager $gateways) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function collect(Plan $plan, Router $router, string $phone, array $attributes = []): Payment
    {
        $gateway = $this->gateways->active();

        if ($gateway === null) {
            throw new RuntimeException('This ISP has not set up a way to take payments yet.');
        }

        // Normalised here rather than inside a gateway, so the stored number is comparable
        // whichever provider collected it.
        $normalised = PhoneNumber::normalise($phone);

        // Recorded before the prompt goes out, so a customer who pays against a request we
        // failed to record cannot happen.
        $payment = new Payment(array_merge([
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'provider' => $gateway->provider()->value,
            'phone' => $normalised,
            'amount' => $plan->price,
        ], $attributes));

        $payment->save();

        // Our own reference, which Paystack echoes back and which gives every attempt an id
        // even before the provider issues one.
        $reference = 'ISP-'.$payment->id.'-'.Str::upper(Str::random(6));

        $result = $gateway->charge($plan, $normalised, $reference);

        if (! $result->accepted) {
            $payment->status = PaymentStatus::Failed;
            $payment->result_description = $result->message;
            $payment->failed_at = now();
            $payment->callback_payload = $result->raw;
            $payment->save();

            return $payment;
        }

        // Whatever the provider calls its handle, this is the value its webhook will quote.
        $payment->checkout_request_id = $result->reference;
        $payment->merchant_request_id = $result->secondaryReference;
        $payment->request_payload = $result->raw;
        $payment->save();

        return $payment;
    }
}
