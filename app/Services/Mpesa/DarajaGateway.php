<?php

namespace App\Services\Mpesa;

use App\Models\MpesaConfiguration;
use App\Services\Mpesa\Contracts\MpesaGateway;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DarajaGateway implements MpesaGateway
{
    public function stkPush(
        MpesaConfiguration $configuration,
        string $phone,
        int $amount,
        string $accountReference,
        string $description,
        string $callbackUrl,
    ): StkPushResult {
        $timestamp = now()->format('YmdHis');

        $payload = [
            'BusinessShortCode' => $configuration->short_code,
            'Password' => $configuration->password($timestamp),
            'Timestamp' => $timestamp,
            'TransactionType' => $configuration->transaction_type,
            // Daraja rejects decimals: a package priced 20.50 has to be sent as 21.
            'Amount' => $amount,
            'PartyA' => PhoneNumber::normalise($phone),
            'PartyB' => $configuration->partyB(),
            'PhoneNumber' => PhoneNumber::normalise($phone),
            'CallBackURL' => $callbackUrl,
            'AccountReference' => substr($accountReference, 0, 12),
            'TransactionDesc' => substr($description, 0, 13),
        ];

        try {
            $response = $this->client($configuration)
                ->post('/mpesa/stkpush/v1/processrequest', $payload);
        } catch (Throwable $e) {
            return StkPushResult::rejected($e->getMessage());
        }

        $body = $response->json() ?? [];

        // Daraja signals acceptance with ResponseCode "0" as a string.
        if (! $response->successful() || ($body['ResponseCode'] ?? null) !== '0') {
            return StkPushResult::rejected(
                $body['errorMessage'] ?? $body['ResponseDescription'] ?? 'M-PESA declined the request.',
                $body,
            );
        }

        return StkPushResult::accepted(
            (string) $body['CheckoutRequestID'],
            (string) $body['MerchantRequestID'],
            $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function query(MpesaConfiguration $configuration, string $checkoutRequestId): array
    {
        $timestamp = now()->format('YmdHis');

        try {
            $response = $this->client($configuration)->post('/mpesa/stkpushquery/v1/query', [
                'BusinessShortCode' => $configuration->short_code,
                'Password' => $configuration->password($timestamp),
                'Timestamp' => $timestamp,
                'CheckoutRequestID' => $checkoutRequestId,
            ]);

            return $response->json() ?? [];
        } catch (Throwable $e) {
            Log::warning('M-PESA status query failed', [
                'checkout_request_id' => $checkoutRequestId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function client(MpesaConfiguration $configuration): PendingRequest
    {
        return Http::baseUrl($configuration->baseUrl())
            ->withToken($this->accessToken($configuration))
            ->acceptJson()
            ->timeout((int) config('mpesa.timeout'))
            ->connectTimeout((int) config('mpesa.connect_timeout'));
    }

    /**
     * Daraja tokens last an hour. Fetching one per request would double the latency the
     * customer waits at the portal, so they are cached just short of expiry.
     */
    protected function accessToken(MpesaConfiguration $configuration): string
    {
        return Cache::remember(
            'mpesa.token.'.$configuration->id,
            now()->addMinutes(50),
            function () use ($configuration): string {
                $response = Http::baseUrl($configuration->baseUrl())
                    ->withBasicAuth($configuration->consumer_key, $configuration->consumer_secret)
                    ->acceptJson()
                    ->timeout((int) config('mpesa.timeout'))
                    ->connectTimeout((int) config('mpesa.connect_timeout'))
                    ->get('/oauth/v1/generate', ['grant_type' => 'client_credentials'])
                    ->throw();

                return (string) $response->json('access_token');
            },
        );
    }
}
