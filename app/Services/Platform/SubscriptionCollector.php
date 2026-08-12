<?php

namespace App\Services\Platform;

use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Services\Payments\PaystackGateway;
use App\Services\Payments\VerificationResult;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prompts an ISP's phone for a subscription payment, and settles what comes back.
 *
 * The same rule as everywhere else in this system: access is restored on a verified result,
 * never on the push being accepted. An ISP whose console unlocked the moment the prompt was
 * sent could dismiss it and carry on working.
 */
class SubscriptionCollector
{
    public function __construct(
        protected MpesaGateway $daraja,
        protected PlatformTill $till,
        protected PlatformBilling $billing,
        protected PlatformPaystack $paystack,
        protected PaystackGateway $paystackGateway,
    ) {}

    /**
     * Whether a subscription can be collected at all, and how.
     */
    public function isConfigured(): bool
    {
        return $this->paystack->isConfigured() || $this->till->isConfigured();
    }

    public function unconfiguredReason(): ?string
    {
        return $this->isConfigured() ? null : $this->till->unconfiguredReason();
    }

    /**
     * Prompt the payer's phone for this invoice.
     *
     * Paystack first where it is set up: it needs no Safaricom paperwork of its own, and the
     * ISP sees the same prompt either way.
     */
    public function collect(TenantInvoice $invoice, string $phone): TenantPayment
    {
        $normalised = PhoneNumber::normalise($phone);

        // Recorded before the prompt goes out, so an ISP that pays against a request we failed
        // to record cannot happen.
        $payment = TenantPayment::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'tenant_invoice_id' => $invoice->id,
            'provider' => $this->paystack->isConfigured()
                ? PaymentProvider::Paystack->value
                : PaymentProvider::Mpesa->value,
            'phone' => $normalised,
            'amount' => $invoice->total,
        ]);

        return $this->paystack->isConfigured()
            ? $this->collectWithPaystack($invoice, $payment, $normalised)
            : $this->collectWithDaraja($invoice, $payment, $normalised);
    }

    protected function collectWithPaystack(TenantInvoice $invoice, TenantPayment $payment, string $phone): TenantPayment
    {
        $configuration = $this->paystack->configuration();
        $tenant = $invoice->tenant;

        if ($configuration === null || $tenant === null) {
            return $this->fail($payment, (string) $this->paystack->unconfiguredReason());
        }

        $email = $this->paystack->emailFor($tenant);

        if ($email === null) {
            // Sending a malformed address gets a flat "invalid email" back, which says nothing
            // about which of our fields is wrong.
            return $this->fail($payment, __('This ISP has no usable contact email for Paystack to bill.'));
        }

        $result = $this->paystackGateway->chargeMobileMoney(
            configuration: $configuration,
            amount: (float) $invoice->total,
            phone: $phone,
            email: $email,
            reference: $invoice->number.'-'.$payment->id,
            metadata: ['invoice' => $invoice->number, 'tenant' => $tenant->slug],
        );

        if (! $result->accepted) {
            Log::warning('Platform Paystack charge refused', [
                'invoice' => $invoice->number,
                'message' => $result->message,
            ]);

            return $this->fail($payment, (string) $result->message, $result->raw);
        }

        $payment->checkout_request_id = $result->reference;
        $payment->merchant_request_id = $result->secondaryReference;
        $payment->request_payload = $result->raw;
        $payment->save();

        return $payment;
    }

    protected function collectWithDaraja(TenantInvoice $invoice, TenantPayment $payment, string $normalised): TenantPayment
    {
        $configuration = $this->till->configuration();

        if ($configuration === null) {
            return $this->fail($payment, (string) $this->till->unconfiguredReason());
        }

        $result = $this->daraja->stkPush(
            configuration: $configuration,
            phone: $normalised,
            // Daraja rejects decimals, and rounding down would undercharge the platform.
            amount: (int) ceil((float) $invoice->total),
            accountReference: $invoice->number,
            description: 'Subscription',
            callbackUrl: $this->till->callbackUrl(),
        );

        if (! $result->accepted) {
            Log::warning('Platform STK push refused', [
                'invoice' => $invoice->number,
                'message' => $result->message,
            ]);

            return $this->fail($payment, (string) $result->message, $result->raw);
        }

        $payment->checkout_request_id = $result->checkoutRequestId;
        $payment->merchant_request_id = $result->merchantRequestId;
        $payment->request_payload = $result->raw;
        $payment->save();

        return $payment;
    }

    /**
     * Settle from Safaricom's callback.
     *
     * Safe to run repeatedly — Safaricom delivers callbacks more than once, and an ISP whose
     * invoice was marked paid twice would see a credit they never had.
     *
     * @param  array<string, mixed>  $payload  The decoded Body.stkCallback object.
     */
    public function settleFromCallback(array $payload): ?TenantPayment
    {
        $checkoutRequestId = $payload['CheckoutRequestID'] ?? null;

        if (! is_string($checkoutRequestId)) {
            return null;
        }

        $payment = TenantPayment::query()->where('checkout_request_id', $checkoutRequestId)->first();

        if ($payment === null) {
            Log::warning('Platform callback for an unknown payment', ['checkout_request_id' => $checkoutRequestId]);

            return null;
        }

        // A callback and the ISP's own polling can arrive together; only one of them may
        // unlock the console.
        $lock = Cache::lock('platform:payment:'.$payment->id, 30);

        if (! $lock->get()) {
            return $payment;
        }

        try {
            $payment->refresh();

            if ($payment->status->isSettled()) {
                return $payment;
            }

            $resultCode = (int) ($payload['ResultCode'] ?? -1);
            $description = isset($payload['ResultDesc']) ? (string) $payload['ResultDesc'] : null;

            if ($resultCode !== 0) {
                return $this->close($payment, $resultCode, $description, $payload);
            }

            $metadata = $this->metadata($payload);

            $payment->status = PaymentStatus::Paid;
            $payment->result_code = 0;
            $payment->result_description = $description;
            $payment->receipt = isset($metadata['MpesaReceiptNumber']) ? (string) $metadata['MpesaReceiptNumber'] : null;
            $payment->callback_payload = $payload;
            $payment->paid_at = now();

            // What Safaricom says moved is what the platform's books should show.
            if (isset($metadata['Amount'])) {
                $payment->amount = (string) $metadata['Amount'];
            }

            $payment->save();

            if ($payment->invoice !== null) {
                $this->billing->markPaid($payment->invoice);
            }

            return $payment;
        } finally {
            $lock->release();
        }
    }

    /**
     * Settle a Paystack subscription charge from whatever Paystack says about it.
     *
     * Used by the webhook and by the billing page's own polling. Both go through here so that
     * whichever arrives first cannot tell the ISP something different from the other.
     */
    public function settleFromResult(TenantPayment $payment, VerificationResult $result): TenantPayment
    {
        // A webhook and a poll landing together must not unlock the console twice.
        $lock = Cache::lock('platform:payment:'.$payment->id, 30);

        if (! $lock->get()) {
            return $payment;
        }

        try {
            $payment->refresh();

            if ($payment->status->isSettled() || $result->status === null) {
                return $payment;
            }

            if ($result->status !== PaymentStatus::Paid) {
                return $result->status === PaymentStatus::Pending
                    ? $payment
                    : $this->close($payment, $result->code, $result->description, $result->raw, $result->status);
            }

            $payment->status = PaymentStatus::Paid;
            $payment->result_code = 0;
            $payment->result_description = $result->description;
            $payment->receipt = $result->receipt;
            $payment->callback_payload = $result->raw;
            $payment->paid_at = now();

            if ($result->amount !== null) {
                $payment->amount = $result->amount;
            }

            $payment->save();

            if ($payment->invoice !== null) {
                $this->billing->markPaid($payment->invoice);
            }

            return $payment;
        } finally {
            $lock->release();
        }
    }

    /**
     * Ask Paystack what became of a subscription charge still waiting.
     *
     * The same lesson as the captive portal: a webhook only arrives if Paystack can reach this
     * installation, and an ISP holding an M-PESA confirmation while their console stays locked
     * will pay again rather than wait.
     */
    public function reconcile(TenantPayment $payment): TenantPayment
    {
        if ($payment->status->isSettled() || $payment->checkout_request_id === null) {
            return $payment;
        }

        $configuration = $this->paystack->configuration();

        if ($configuration === null || $payment->provider !== PaymentProvider::Paystack->value) {
            return $payment;
        }

        try {
            $result = $this->paystackGateway->verifyWith($configuration, $payment->checkout_request_id);
        } catch (Throwable $e) {
            Log::warning('Subscription verification failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);

            return $payment;
        }

        return $this->settleFromResult($payment, $result);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function close(
        TenantPayment $payment,
        ?int $code,
        ?string $description,
        array $payload,
        ?PaymentStatus $status = null,
    ): TenantPayment {
        // Daraja says a dismissed prompt with ResultCode 1032; Paystack says it only in the
        // wording, which its gateway has already read. Where the caller knows, it says so —
        // deriving it from a Safaricom code would turn every Paystack cancellation into a
        // plain failure, which is not what the payer just did.
        $payment->status = $status ?? ($code === 1032 ? PaymentStatus::Cancelled : PaymentStatus::Failed);
        $payment->result_code = $code;
        $payment->result_description = $description;
        $payment->callback_payload = $payload;
        $payment->failed_at = now();
        $payment->save();

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    protected function fail(TenantPayment $payment, string $message, array $raw = []): TenantPayment
    {
        $payment->status = PaymentStatus::Failed;
        $payment->result_description = $message;
        $payment->callback_payload = $raw;
        $payment->failed_at = now();
        $payment->save();

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function metadata(array $payload): array
    {
        $items = $payload['CallbackMetadata']['Item'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        $metadata = [];

        foreach ($items as $item) {
            if (is_array($item) && isset($item['Name'])) {
                $metadata[$item['Name']] = $item['Value'] ?? null;
            }
        }

        return $metadata;
    }
}
