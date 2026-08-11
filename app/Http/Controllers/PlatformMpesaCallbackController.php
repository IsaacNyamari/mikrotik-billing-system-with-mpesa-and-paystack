<?php

namespace App\Http\Controllers;

use App\Services\Platform\SubscriptionCollector;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Safaricom's result for an ISP paying its subscription.
 *
 * A separate endpoint from the tenant callback on purpose: that one looks a payment up in
 * `payments` and would find nothing here, and an ISP paying the platform must never be
 * mistaken for a customer buying wifi.
 */
class PlatformMpesaCallbackController extends Controller
{
    public function __invoke(Request $request, string $token, SubscriptionCollector $collector): JsonResponse
    {
        $expected = (string) config('platform.mpesa.callback_token');

        // Safaricom does not sign callbacks, so the unguessable token in the path is the only
        // credential. Compared in constant time because it guards money.
        if ($expected === '' || ! hash_equals($expected, $token)) {
            Log::warning('Platform callback with a bad token');

            return $this->acknowledge();
        }

        $payload = $request->json('Body.stkCallback');

        if (! is_array($payload)) {
            Log::warning('Platform callback with no stkCallback body');

            return $this->acknowledge();
        }

        try {
            // Platform billing spans every ISP and belongs to none, so it says so rather than
            // depending on there being no tenant resolved on a public request.
            Tenancy::withoutScope(fn () => $collector->settleFromCallback($payload));
        } catch (Throwable $e) {
            // The money has already moved by the time this arrives. Recording the fault beats
            // a 500, which only makes Safaricom retry.
            Log::error('Platform callback failed', ['error' => $e->getMessage()]);
        }

        return $this->acknowledge();
    }

    /**
     * Always 200, whatever happened. A non-200 makes Safaricom retry, which only helps when
     * the problem is transient.
     */
    protected function acknowledge(): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
