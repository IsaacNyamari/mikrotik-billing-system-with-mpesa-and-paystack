<?php

namespace App\Services\Platform;

use App\Enums\PaymentStatus;
use App\Models\TenantInvoice;
use App\Models\TenantPayment;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
    ) {}

    /**
     * Ask Safaricom to prompt the payer for this invoice.
     */
    public function collect(TenantInvoice $invoice, string $phone): TenantPayment
    {
        $configuration = $this->till->configuration();

        $normalised = PhoneNumber::normalise($phone);

        // Recorded before the prompt goes out, so an ISP that pays against a request we failed
        // to record cannot happen.
        $payment = TenantPayment::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'tenant_invoice_id' => $invoice->id,
            'phone' => $normalised,
            'amount' => $invoice->total,
        ]);

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
     * @param  array<string, mixed>  $payload
     */
    protected function close(TenantPayment $payment, ?int $code, ?string $description, array $payload): TenantPayment
    {
        // 1032 is Safaricom for the payer dismissing the prompt, which is worth telling them
        // apart from a payment that failed on its own.
        $payment->status = $code === 1032 ? PaymentStatus::Cancelled : PaymentStatus::Failed;
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
