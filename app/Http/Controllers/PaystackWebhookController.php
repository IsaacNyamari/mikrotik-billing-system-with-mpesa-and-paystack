<?php

namespace App\Http\Controllers;

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

                $event = $payload['event'] ?? null;
                $data = $payload['data'] ?? [];

                if ($event !== 'charge.success' || ($data['status'] ?? null) !== 'success') {
                    $processor->reject(
                        $payment,
                        null,
                        (string) ($data['gateway_response'] ?? $event ?? 'Payment was not completed'),
                        $payload,
                    );

                    return;
                }

                $processor->settle(
                    payment: $payment,
                    // Paystack's own receipt, which becomes the customer's login.
                    receipt: $this->receipt($data, $reference = (string) $data['reference']),
                    // Reported in the currency's smallest unit, so cents come back to shillings.
                    amount: isset($data['amount']) ? (string) ((int) $data['amount'] / 100) : null,
                    phone: null,
                    description: isset($data['gateway_response']) ? (string) $data['gateway_response'] : null,
                    payload: $payload,
                );
            });
        } catch (Throwable $e) {
            // The money has already moved by the time this arrives. Recording the fault beats
            // a 500 that makes Paystack retry; the sweeper picks provisioning up either way.
            Log::error('Paystack webhook failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
        }

        return $this->acknowledge();
    }

    /**
     * The code the customer logs in with.
     *
     * Paystack's M-PESA receipt is the one a customer would recognise from their SMS, so it is
     * preferred over Paystack's own transaction reference.
     *
     * @param  array<string, mixed>  $data
     */
    protected function receipt(array $data, string $fallback): string
    {
        $mpesaReceipt = $data['authorization']['receiver_bank_account_number']
            ?? $data['authorization']['mobile_money_number']
            ?? null;

        // Paystack exposes the mobile-money confirmation under different keys depending on the
        // channel, so anything that is not clearly a receipt falls back to our own reference.
        return is_string($mpesaReceipt) && $mpesaReceipt !== '' && ! str_starts_with($mpesaReceipt, '+')
            ? $mpesaReceipt
            : $fallback;
    }

    protected function acknowledge(): JsonResponse
    {
        return response()->json(['status' => 'received']);
    }
}
