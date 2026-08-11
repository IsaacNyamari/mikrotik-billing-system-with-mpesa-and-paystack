<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaystackConfiguration;
use App\Models\Tenant;
use App\Services\Mpesa\CallbackProcessor;
use App\Services\Payments\PaystackGateway;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives Paystack's result for a charge.
 *
 * Unlike Daraja, Paystack signs every webhook, so this endpoint needs no secret in its URL —
 * the signature is the credential, and a body that does not match it is discarded.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, CallbackProcessor $processor, PaystackGateway $gateway): JsonResponse
    {
        $payload = $request->json()->all();
        $reference = $payload['data']['reference'] ?? null;

        if (! is_string($reference)) {
            Log::warning('Paystack webhook with no reference', ['event' => $payload['event'] ?? null]);

            return $this->acknowledge();
        }

        // The reference identifies the payment, and the payment identifies the ISP — so which
        // tenant's key to check the signature against is knowable before trusting anything.
        $payment = Tenancy::withoutScope(
            fn (): ?Payment => Payment::query()->withoutGlobalScopes()->where('checkout_request_id', $reference)->first(),
        );

        if ($payment === null) {
            Log::warning('Paystack webhook for an unknown payment', ['reference' => $reference]);

            return $this->acknowledge();
        }

        $tenant = Tenant::query()->whereKey($payment->tenant_id)->first();

        if ($tenant === null) {
            return $this->acknowledge();
        }

        try {
            Tenancy::forTenant($tenant, function () use ($request, $payment, $payload, $processor, $gateway): void {
                $configuration = PaystackConfiguration::query()->first();

                if ($configuration === null) {
                    Log::warning('Paystack webhook for an ISP with no Paystack set up', ['payment' => $payment->id]);

                    return;
                }

                // Verified against the raw body: re-encoding the decoded JSON would change
                // whitespace and key order, and the signature would never match.
                if (! $gateway->signatureIsValid($configuration, $request->getContent(), $request->header('x-paystack-signature'))) {
                    Log::warning('Paystack webhook with a bad signature', ['payment' => $payment->id]);

                    return;
                }

                // Safe to receive twice: Paystack retries, and an already-settled payment is
                // left exactly as it is.
                if ($payment->status->isSettled()) {
                    return;
                }

                $event = is_string($payload['event'] ?? null) ? (string) $payload['event'] : null;
                $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

                // Paystack sends more than charge results down the same hook — disputes,
                // refunds, transfers. Only a charge decides whether this customer gets online.
                if ($event !== null && ! str_starts_with($event, 'charge.')) {
                    return;
                }

                // Read by the gateway, so a webhook and a poll cannot disagree about what
                // "cancelled" means.
                $result = $gateway->interpret($data, $event);

                match ($result->status) {
                    PaymentStatus::Paid => $processor->settle(
                        payment: $payment,
                        receipt: $result->receipt,
                        amount: $result->amount,
                        phone: null,
                        description: $result->description,
                        payload: $payload,
                    ),
                    PaymentStatus::Cancelled => $processor->cancel($payment, null, $result->description, $payload),
                    PaymentStatus::Failed => $processor->reject(
                        $payment,
                        null,
                        $result->description ?? 'Payment was not completed',
                        $payload,
                    ),
                    // Paystack reporting a charge still in flight is not an outcome to record.
                    default => null,
                };
            });
        } catch (Throwable $e) {
            // The money has already moved by the time this arrives. Recording the fault beats
            // a 500 that makes Paystack retry; the sweeper picks provisioning up either way.
            Log::error('Paystack webhook failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
        }

        return $this->acknowledge();
    }

    protected function acknowledge(): JsonResponse
    {
        return response()->json(['status' => 'received']);
    }
}
