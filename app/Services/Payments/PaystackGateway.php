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
    /**
     * Statuses that mean the charge is still with the customer rather than finished with.
     *
     * @var array<int, string>
     */
    protected const IN_FLIGHT = [
        'pending', 'ongoing', 'processing', 'queued',
        'send_otp', 'send_pin', 'send_phone', 'send_birthday', 'send_address', 'open_url', 'pay_offline',
    ];

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
                    // E.164 with the plus. Paystack rejects both 2547XXXXXXXX — which is
                    // exactly what Daraja insists on — and the local 07XXXXXXXX, as an
                    // "invalid phone number format".
                    'phone' => PhoneNumber::e164($normalised),
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
            // The useful reason is nested. A declined charge answers with a top-level message
            // of "Charge attempted", which says nothing, while data.message carries the actual
            // cause — the wrong test number, an unsupported channel, and so on.
            return ChargeResult::rejected(
                (string) ($body['data']['message'] ?? $body['message'] ?? __('Paystack declined the request.')),
                $body,
            );
        }

        // A 200 with a failed charge inside it is still a refusal.
        if (($body['data']['status'] ?? null) === 'failed') {
            return ChargeResult::rejected(
                (string) ($body['data']['message'] ?? __('Paystack declined the request.')),
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

    public function verify(string $reference): VerificationResult
    {
        $configuration = $this->configuration();

        if ($configuration === null) {
            return VerificationResult::unknown();
        }

        try {
            $response = $this->client($configuration)->get('/transaction/verify/'.urlencode($reference));
        } catch (Throwable $e) {
            Log::warning('Paystack verification failed', ['reference' => $reference, 'error' => $e->getMessage()]);

            return VerificationResult::unknown();
        }

        $body = $response->json() ?? [];

        // A reference Paystack has never heard of, or an outage, says nothing about the
        // customer — it must not be read as a refusal.
        if (! $response->successful() || ($body['status'] ?? false) !== true || ! is_array($body['data'] ?? null)) {
            return VerificationResult::unknown(is_array($body) ? $body : []);
        }

        return $this->interpret($body['data']);
    }

    /**
     * Paystack's own answer is complete — it names the transaction and reports the amount — so
     * there is nothing to wait for a webhook to add.
     */
    public function verificationGraceSeconds(): int
    {
        return 0;
    }

    /**
     * Read a Paystack transaction object, from a webhook or from a verification.
     *
     * Both carry the same `data` shape, and both have to agree about what counts as a
     * cancellation — otherwise whichever arrived first would decide what the customer is told.
     *
     * @param  array<string, mixed>  $data
     */
    public function interpret(array $data, ?string $event = null): VerificationResult
    {
        $status = is_string($data['status'] ?? null) ? strtolower((string) $data['status']) : null;
        $response = isset($data['gateway_response']) ? (string) $data['gateway_response'] : null;

        if ($event === 'charge.success' || $status === 'success') {
            return VerificationResult::paid(
                receipt: $this->receipt($data),
                // Reported in the currency's smallest unit, so cents come back to shillings.
                amount: isset($data['amount']) ? (string) ((int) $data['amount'] / 100) : null,
                description: $response,
                raw: $data,
            );
        }

        // Still with the customer: mobile money sits here for as long as the prompt is unanswered.
        if (in_array($status, self::IN_FLIGHT, true)) {
            return VerificationResult::pending($data);
        }

        if ($status === null) {
            return VerificationResult::unknown($data);
        }

        // "abandoned" is Paystack for a charge the customer walked away from, which to them is
        // the same event as pressing cancel.
        return $status === 'abandoned' || $this->readsAsRefusal($response)
            ? VerificationResult::cancelled($response, $data)
            : VerificationResult::failed($response, $data);
    }

    /**
     * The code the customer logs in with.
     *
     * `receipt_number` is where Paystack puts the M-PESA code — the one the customer has in
     * their confirmation SMS, and so the only one worth naming their login after. Verified
     * against a real settled charge, which carried "UHB6H1ZT6H" there.
     *
     * `authorization.mobile_money_number` is deliberately **not** used as a fallback: it is the
     * payer's own phone number, so treating it as a receipt hands out a login named after the
     * customer's number, which is both wrong and theirs to keep private.
     *
     * @param  array<string, mixed>  $data
     */
    protected function receipt(array $data): ?string
    {
        $candidate = $data['receipt_number'] ?? null;

        if (is_string($candidate) && trim($candidate) !== '') {
            return trim($candidate);
        }

        // No code from the provider: our own reference at least names the purchase after
        // something that exists on both sides.
        return isset($data['reference']) ? (string) $data['reference'] : null;
    }

    /**
     * Whether the provider's own words describe the customer saying no.
     *
     * Paystack has no distinct status for it — a cancelled mobile-money charge is a failed one
     * whose gateway_response says so — so the wording is what there is to read.
     */
    protected function readsAsRefusal(?string $response): bool
    {
        return $response !== null
            && preg_match('/cancel|declin|reject|refus|abort|denied|not authoriz/i', $response) === 1;
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
