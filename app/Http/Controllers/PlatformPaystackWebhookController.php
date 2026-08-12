<?php

namespace App\Http\Controllers;

use App\Models\TenantPayment;
use App\Services\Payments\PaystackGateway;
use App\Services\Platform\PlatformPaystack;
use App\Services\Platform\SubscriptionCollector;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paystack's result for an ISP paying the platform.
 *
 * Its own endpoint, checked against the platform's own key: the tenant webhook looks a
 * reference up in `payments`, which is an ISP collecting from their customers and is a
 * different table with different money in it.
 */
class PlatformPaystackWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        PaystackGateway $gateway,
        PlatformPaystack $platform,
        SubscriptionCollector $collector,
    ): JsonResponse {
        $configuration = $platform->configuration();

        if ($configuration === null) {
            Log::warning('Platform Paystack webhook with no platform account configured');

            return $this->acknowledge();
        }

        // Verified against the raw body: re-encoding decoded JSON changes whitespace and key
        // order, and the signature would never match.
        if (! $gateway->signatureIsValid($configuration, $request->getContent(), $request->header('x-paystack-signature'))) {
            Log::warning('Platform Paystack webhook with a bad signature');

            return $this->acknowledge();
        }

        $payload = $request->json()->all();
        $event = is_string($payload['event'] ?? null) ? (string) $payload['event'] : null;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $reference = $data['reference'] ?? null;

        if (! is_string($reference) || ($event !== null && ! str_starts_with($event, 'charge.'))) {
            return $this->acknowledge();
        }

        // Platform billing belongs to no ISP and spans them all, so it says so rather than
        // relying on nobody being signed in on a public request.
        $payment = Tenancy::withoutScope(
            fn (): ?TenantPayment => TenantPayment::query()->where('checkout_request_id', $reference)->first(),
        );

        if ($payment === null) {
            Log::warning('Platform Paystack webhook for an unknown payment', ['reference' => $reference]);

            return $this->acknowledge();
        }

        try {
            Tenancy::withoutScope(
                fn () => $collector->settleFromResult($payment, $gateway->interpret($data, $event)),
            );
        } catch (Throwable $e) {
            // The money has moved by the time this arrives. Recording the fault beats a 500,
            // which only makes Paystack retry.
            Log::error('Platform Paystack webhook failed', ['payment' => $payment->id, 'error' => $e->getMessage()]);
        }

        return $this->acknowledge();
    }

    protected function acknowledge(): JsonResponse
    {
        return response()->json(['status' => 'received']);
    }
}
