<?php

namespace App\Http\Controllers;

use App\Models\MpesaConfiguration;
use App\Services\Mpesa\CallbackProcessor;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Receives Safaricom's result for an STK push.
 *
 * Safaricom does not sign callbacks, so the unguessable token in the URL is what identifies
 * both the ISP and the caller. The endpoint always answers with success: a non-200 makes
 * Safaricom retry, and a retry is only useful when the problem is transient.
 */
class MpesaCallbackController extends Controller
{
    public function __invoke(Request $request, string $token, CallbackProcessor $processor): JsonResponse
    {
        // The token identifies the tenant, so this lookup deliberately spans all of them.
        $configuration = Tenancy::withoutScope(
            fn (): ?MpesaConfiguration => MpesaConfiguration::query()
                ->withoutGlobalScopes()
                ->where('callback_token', $token)
                ->first(),
        );

        if ($configuration === null) {
            Log::warning('M-PESA callback with an unrecognised token', ['ip' => $request->ip()]);

            // Deliberately vague, and still a 200: an attacker probing tokens learns nothing,
            // and Safaricom has nothing to retry.
            return $this->acknowledge();
        }

        $payload = $request->input('Body.stkCallback');

        if (! is_array($payload)) {
            Log::warning('M-PESA callback with an unexpected shape', [
                'tenant_id' => $configuration->tenant_id,
                'body' => $request->all(),
            ]);

            return $this->acknowledge();
        }

        try {
            $configuration->loadMissing('tenant');

            // Everything downstream is tenant-owned, so the callback runs as the ISP the
            // token belongs to.
            Tenancy::forTenant(
                $configuration->tenant,
                fn () => $processor->process($payload),
            );
        } catch (Throwable $e) {
            // The money has already moved by the time this arrives. Swallowing the error and
            // recording it beats a 500 that makes Safaricom hammer the endpoint; the sweeper
            // retries provisioning either way.
            Log::error('M-PESA callback failed', [
                'tenant_id' => $configuration->tenant_id,
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);
        }

        return $this->acknowledge();
    }

    /**
     * The shape Safaricom expects back.
     */
    protected function acknowledge(): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
