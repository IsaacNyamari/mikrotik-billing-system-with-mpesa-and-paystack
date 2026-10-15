<?php

namespace App\Services\Sms;

use App\Models\ServiceAccount;
use App\Models\SmsMessage;
use App\Models\SmsSetting;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;
use Itsmurumba\Hostpinnacle\Hostpinnacle;
use Itsmurumba\Hostpinnacle\HostpinnacleCredentials;
use Throwable;

/**
 * Texts a subscriber through HostPinnacle.
 *
 * An ISP's own account is used when they have entered one, so messages arrive under their
 * sender ID. Without it the platform's account (from the environment) is used, and without
 * either the message is recorded as skipped: a reminder that could not be sent is still worth
 * an operator knowing about.
 *
 * Never throws. Texting is a courtesy around billing, and a provider outage must not stop a
 * line being reconnected or a renewal being recorded.
 */
class SmsSender
{
    public const BASE_URL = 'https://smsportal.hostpinnacle.co.ke/SMSApi';

    public function send(string $phone, string $body, string $kind, ?ServiceAccount $account = null): SmsMessage
    {
        $message = new SmsMessage([
            'service_account_id' => $account?->id,
            'phone' => PhoneNumber::normalise($phone),
            'kind' => $kind,
            'body' => $body,
            'status' => SmsMessage::SKIPPED,
        ]);

        $credentials = $this->credentials();

        if ($credentials === null) {
            $message->error = __('No SMS account is set up.');
            $message->save();

            return $message;
        }

        try {
            $response = (new Hostpinnacle($credentials))->sendQuickSMS([
                'mobile' => $message->phone,
                'msg' => $body,
            ]);

            $json = $response->json();
            $message->response = is_array($json) ? $json : ['body' => mb_substr($response->body(), 0, 500)];

            // HostPinnacle answers 200 with "status":"error" for a refusal, so the status code
            // alone is not the verdict.
            $refused = is_array($json) && strtolower((string) ($json['status'] ?? '')) === 'error';

            if ($response->successful() && ! $refused) {
                $message->status = SmsMessage::SENT;
            } else {
                $message->status = SmsMessage::FAILED;
                $message->error = mb_substr((string) ($json['reason'] ?? $json['message'] ?? 'HTTP '.$response->status()), 0, 250);
            }
        } catch (Throwable $e) {
            $message->status = SmsMessage::FAILED;
            $message->error = mb_substr($e->getMessage(), 0, 250);

            Log::warning('SMS could not be sent', ['kind' => $kind, 'error' => $e->getMessage()]);
        }

        $message->save();

        return $message;
    }

    /**
     * Whether a message sent now would actually go anywhere.
     */
    public function canSend(): bool
    {
        return $this->credentials() !== null;
    }

    protected function credentials(): ?HostpinnacleCredentials
    {
        $settings = SmsSetting::current();

        if (! $settings->is_enabled) {
            return null;
        }

        if ($settings->isComplete()) {
            return new HostpinnacleCredentials(
                (string) $settings->api_key,
                (string) $settings->sender_id,
                (string) $settings->username,
                (string) $settings->password,
                self::BASE_URL,
            );
        }

        $platform = config('services.hostpinnacle');

        if (! is_array($platform) || blank($platform['api_key'] ?? null) || blank($platform['sender_id'] ?? null)) {
            return null;
        }

        return new HostpinnacleCredentials(
            (string) $platform['api_key'],
            (string) $platform['sender_id'],
            (string) ($platform['username'] ?? ''),
            (string) ($platform['password'] ?? ''),
            (string) ($platform['base_url'] ?? self::BASE_URL),
        );
    }
}
