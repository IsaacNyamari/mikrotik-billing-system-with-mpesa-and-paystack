<?php

namespace App\Services\Mpesa;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\RouterOs\HotspotUserProvisioner;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns an M-PESA callback into access.
 *
 * Safaricom delivers callbacks more than once and in no guaranteed order, so everything here
 * has to be safe to run repeatedly against the same payment. The rule the specification is
 * emphatic about holds: service is granted on a verified callback, never on the STK push
 * having been accepted.
 */
class CallbackProcessor
{
    public function __construct(
        protected HotspotUserProvisioner $provisioner,
        protected AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  The decoded Body.stkCallback object.
     */
    public function process(array $payload): ?Payment
    {
        $checkoutRequestId = $payload['CheckoutRequestID'] ?? null;

        if (! is_string($checkoutRequestId)) {
            Log::warning('M-PESA callback with no CheckoutRequestID', ['payload' => $payload]);

            return null;
        }

        $payment = Payment::query()->where('checkout_request_id', $checkoutRequestId)->first();

        if ($payment === null) {
            // Not ours, or for a payment that was never recorded. Nothing to do, and
            // certainly nothing to provision.
            Log::warning('M-PESA callback for an unknown payment', ['checkout_request_id' => $checkoutRequestId]);

            return null;
        }

        // A payment already settled has been through here; re-running must not double-issue
        // access or overwrite the receipt.
        if ($payment->status->isSettled()) {
            return $payment;
        }

        $resultCode = (int) ($payload['ResultCode'] ?? -1);

        return $resultCode === 0
            ? $this->settlePaid($payment, $payload)
            : $this->settleFailed($payment, $payload, $resultCode);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function settlePaid(Payment $payment, array $payload): Payment
    {
        $metadata = $this->metadata($payload);

        return $this->settle(
            payment: $payment,
            receipt: isset($metadata['MpesaReceiptNumber']) ? (string) $metadata['MpesaReceiptNumber'] : null,
            amount: isset($metadata['Amount']) ? (string) $metadata['Amount'] : null,
            phone: isset($metadata['PhoneNumber']) ? (string) $metadata['PhoneNumber'] : null,
            description: isset($payload['ResultDesc']) ? (string) $payload['ResultDesc'] : null,
            payload: $payload,
        );
    }

    /**
     * Mark a payment paid and hand the customer their access.
     *
     * Shared by every provider: whoever reported the money, what happens next is the same, and
     * it is safe to run again because an already-settled payment never reaches here.
     *
     * @param  array<string, mixed>  $payload
     */
    public function settle(
        Payment $payment,
        ?string $receipt,
        ?string $amount,
        ?string $phone,
        ?string $description,
        array $payload,
    ): Payment {
        DB::transaction(function () use ($payment, $payload, $receipt, $amount, $phone, $description): void {
            $payment->status = PaymentStatus::Paid;
            $payment->receipt = $receipt;
            $payment->result_code = 0;
            $payment->result_description = $description;
            $payment->callback_payload = $payload;
            $payment->paid_at = now();

            // Providers report what was actually paid, which can differ from what was asked
            // for. The ISP's record should show the money that moved.
            if ($amount !== null) {
                $payment->amount = $amount;
            }

            if ($phone !== null) {
                $payment->phone = $phone;
            }

            $payment->save();
        });

        $this->audit->record(
            event: 'payment.confirmed',
            auditable: $payment,
            description: sprintf('Received %s from %s (%s)', $payment->amount, $payment->phone, $payment->receipt ?? 'no receipt'),
        );

        return $this->grantAccess($payment);
    }

    /**
     * Record a failed attempt, whoever reported it.
     *
     * @param  array<string, mixed>  $payload
     */
    public function reject(Payment $payment, ?int $code, ?string $description, array $payload): Payment
    {
        $payment->status = PaymentStatus::Failed;
        $payment->result_code = $code;
        $payment->result_description = $description;
        $payment->callback_payload = $payload;
        $payment->failed_at = now();
        $payment->save();

        return $payment;
    }

    /**
     * Issue the package the customer paid for.
     *
     * Provisioning failure does not undo the payment: the money has moved, so the record
     * stands and the sweeper's retry picks the access up.
     */
    protected function grantAccess(Payment $payment): Payment
    {
        if ($payment->hotspot_user_id !== null || $payment->plan_id === null || $payment->router_id === null) {
            return $payment;
        }

        $payment->loadMissing(['plan', 'router']);

        // The receipt becomes the customer's username and password. Without one there is
        // nothing to name the login after, so fall back to a generated code.
        $reference = $payment->receipt ?? HotspotUserProvisioner::generateReference();

        $user = $this->provisioner->issue($payment->plan, $payment->router, $reference, [
            'customer_id' => $payment->customer_id,
            'phone' => $payment->phone,
            'amount' => $payment->amount,
            'reference' => $reference,
        ]);

        $payment->hotspot_user_id = $user->id;
        $payment->save();

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function settleFailed(Payment $payment, array $payload, int $resultCode): Payment
    {
        $payment->status = PaymentStatus::Failed;
        $payment->result_code = $resultCode;
        $payment->result_description = $payload['ResultDesc'] ?? null;
        $payment->callback_payload = $payload;
        $payment->failed_at = now();
        $payment->save();

        return $payment;
    }

    /**
     * Flatten CallbackMetadata.Item, which arrives as a list of name/value pairs.
     *
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
