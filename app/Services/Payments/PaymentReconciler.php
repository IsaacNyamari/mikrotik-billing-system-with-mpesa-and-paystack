<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Mpesa\CallbackProcessor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks the provider what happened, rather than waiting to be told.
 *
 * A webhook only arrives if the provider can reach this installation. On a laptop, behind NAT,
 * or with a tunnel that has quietly dropped, it never does — and the customer, who has the
 * M-PESA confirmation SMS in their hand, is left looking at a spinner. Outbound requests work
 * in all of those places, so the portal asks on every poll and settles from the answer.
 *
 * Nothing here weakens the rule that access is granted on a verified result and never on a
 * prompt having been sent: this *is* a verification, from the same provider, carrying the same
 * facts its callback would.
 */
class PaymentReconciler
{
    public function __construct(
        protected PaymentGatewayManager $gateways,
        protected CallbackProcessor $processor,
    ) {}

    /**
     * Settle a pending payment from the provider's own answer, if it has one yet.
     *
     * Returns the payment either way. Never throws: this runs while a customer waits, and a
     * provider having a bad minute must not take the portal down with it.
     */
    public function reconcile(Payment $payment): Payment
    {
        if ($payment->status->isSettled() || $payment->checkout_request_id === null) {
            return $payment;
        }

        $provider = PaymentProvider::tryFrom($payment->provider);

        if ($provider === null) {
            return $payment;
        }

        $gateway = $this->gateways->for($provider);

        if (! $gateway->isConfigured()) {
            return $payment;
        }

        // Some providers answer a query with less than their callback carries, so their callback
        // gets a head start before we settle from the poorer answer.
        if ($payment->created_at?->addSeconds($gateway->verificationGraceSeconds())->isFuture()) {
            return $payment;
        }

        // One question at a time per payment. The portal polls every few seconds and the
        // scheduled sweep runs every minute; two answers arriving together could otherwise
        // issue two logins for one purchase.
        $lock = Cache::lock('payments:reconcile:'.$payment->id, 30);

        if (! $lock->get()) {
            return $payment;
        }

        try {
            return $this->apply($payment, $gateway->verify($payment->checkout_request_id));
        } catch (Throwable $e) {
            Log::warning('Payment verification failed', [
                'payment' => $payment->id,
                'provider' => $payment->provider,
                'error' => $e->getMessage(),
            ]);

            return $payment;
        } finally {
            $lock->release();
        }
    }

    protected function apply(Payment $payment, VerificationResult $result): Payment
    {
        return match ($result->status) {
            PaymentStatus::Paid => $this->processor->settle(
                payment: $payment,
                receipt: $result->receipt,
                amount: $result->amount,
                phone: $result->phone,
                description: $result->description,
                payload: $result->raw,
            ),
            PaymentStatus::Cancelled => $this->processor->cancel(
                $payment,
                $result->code,
                $result->description,
                $result->raw,
            ),
            PaymentStatus::Failed => $this->processor->reject(
                $payment,
                $result->code,
                $result->description,
                $result->raw,
            ),
            // Still on the customer's phone, or the provider said nothing we can act on.
            default => $payment,
        };
    }
}
