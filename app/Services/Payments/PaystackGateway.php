<?php

namespace App\Services\Payments;

use App\Enums\PaymentProvider;
use App\Models\PaystackConfiguration;
use App\Models\Plan;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paystack, for an ISP with no Daraja app of its own.
 *
 * Uses the mobile-money charge rather than a hosted checkout page on purpose: the customer is
 * sitting on a captive portal, and sending them to a hosted page would mean punching a
 * walled-garden hole for every asset that page loads. A mobile-money charge prompts their
 * phone exactly as Daraja does, so the portal behaves identically either way.
 */
class PaystackGateway implements PaymentGateway
{
    public function provider(): PaymentProvider
    {
        return PaymentProvider::Paystack;
    }

    public function charge(Plan $plan, string $phone, string $reference): ChargeResult
    {
        $configuration = $this->configuration();

        if ($configuration === null) {
            return ChargeResult::rejected(__('Paystack is not set up for this ISP.'));
        }

        $normalised = PhoneNumber::normalise($phone);
        $email = $configuration->emailFor($normalised);

        if ($email === null) {
            // Sending a malformed address gets a flat "invalid email" back from Paystack,
            // which says nothing about which of our fields is wrong.
            return ChargeResult::rejected(
                __('Set a billing email under Settings → Payments before customers can pay with Paystack.'),
            );
        }

        try {
            $response = $this->client($configuration)->post('/charge', [
                'email' => $email,
                // Paystack works in the currency's smallest unit, so KES goes over as cents.
                // Rounding up rather than down, for the same reason as Daraja.
                'amount' => (int) ceil((float) $plan->price) * 100,
                'currency' => $configuration->currency,
                'reference' => $reference,
                'mobile_money' => [
                    'phone' => $normalised,
                    'provider' => 'mpesa',
                ],
                'metadata' => [
                    'plan' => $plan->name,
                    'plan_code' => $plan->code,
                ],
            ]);
        } catch (Throwable $e) {
            return ChargeResult::rejected($e->getMessage());
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || ($body['status'] ?? false) !== true) {
            return ChargeResult::rejected(
                (string) ($body['message'] ?? __('Paystack declined the request.')),
                $body,
            );
        }

        // Paystack echoes our own reference back, and it is what its webhook quotes.
        return ChargeResult::accepted(
            (string) ($body['data']['reference'] ?? $reference),
            isset($body['data']['id']) ? (string) $body['data']['id'] : null,
            $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function verify(string $reference): array
    {
        $configuration = $this->configuration();

        if ($configuration === null) {
            return [];
        }

        try {
            return $this->client($configuration)->get('/transaction/verify/'.urlencode($reference))->json() ?? [];
        } catch (Throwable $e) {
            Log::warning('Paystack verification failed', ['reference' => $reference, 'error' => $e->getMessage()]);

            return [];
        }
    }

    public function isConfigured(): bool
    {
        return $this->configuration() !== null;
    }

    /**
     * Whether a webhook really came from Paystack.
     *
     * Unlike Daraja, Paystack signs its webhooks, so this is a genuine check rather than a
     * secret smuggled through the URL. The comparison is timing-safe because it guards access
     * to money.
     */
    public function signatureIsValid(PaystackConfiguration $configuration, string $rawBody, ?string $signature): bool
    {
        if ($signature === null || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $rawBody, $configuration->secret_key), $signature);
    }

    protected function client(PaystackConfiguration $configuration): PendingRequest
    {
        return Http::baseUrl(PaystackConfiguration::BASE_URL)
            ->withToken($configuration->secret_key)
            ->acceptJson()
            ->timeout((int) config('mpesa.timeout'))
            ->connectTimeout((int) config('mpesa.connect_timeout'));
    }

    protected function configuration(): ?PaystackConfiguration
    {
        return PaystackConfiguration::query()->where('is_active', true)->first();
    }
}
