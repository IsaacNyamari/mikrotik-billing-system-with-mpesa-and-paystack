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
 * Texts a subscriber, or the ISP's own people, through HostPinnacle.
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

    /**
     * @param  bool  $operator  A message to the ISP's own people (a router alert) rather than a
     *                          subscriber: sent even when texts to subscribers are switched off.
     */
    public function send(string $phone, string $body, string $kind, ?ServiceAccount $account = null, bool $operator = false): SmsMessage
    {
        $message = $this->newMessage($phone, $body, $kind, ['service_account_id' => $account?->id]);

        $credentials = $this->candidates($operator)[0] ?? null;

        if ($credentials === null) {
            $message->error = __('No SMS account is set up.');
            $message->save();

            return $message;
        }

        return $this->deliver($message, $credentials);
    }

    /**
     * A router alert to the ISP: tried through every account available — theirs, then the
     * platform's — until one accepts it. Each attempt is its own row under the alert's key,
     * which is what the retry counts.
     */
    public function sendAlert(string $phone, string $body, string $kind, int $routerId, string $alertKey): SmsMessage
    {
        $attributes = ['router_id' => $routerId, 'alert_key' => $alertKey];
        $candidates = $this->candidates(operator: true);

        if ($candidates === []) {
            $message = $this->newMessage($phone, $body, $kind, $attributes);
            $message->error = __('No SMS account is set up.');
            $message->save();

            return $message;
        }

        $message = null;

        foreach ($candidates as $credentials) {
            $message = $this->deliver($this->newMessage($phone, $body, $kind, $attributes), $credentials);

            if ($message->status === SmsMessage::SENT) {
                return $message;
            }
        }

        return $message;
    }

    /**
     * Whether a message sent now would actually go anywhere.
     */
    public function canSend(): bool
    {
        return $this->candidates() !== [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function newMessage(string $phone, string $body, string $kind, array $attributes = []): SmsMessage
    {
        return new SmsMessage(array_merge([
            'phone' => PhoneNumber::normalise($phone),
            'kind' => $kind,
            'body' => $body,
            'status' => SmsMessage::SKIPPED,
        ], $attributes));
    }

    protected function deliver(SmsMessage $message, HostpinnacleCredentials $credentials): SmsMessage
    {
        try {
            $response = (new Hostpinnacle($credentials))->sendQuickSMS([
                'mobile' => $message->phone,
                'msg' => $message->body,
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

            Log::warning('SMS could not be sent', ['kind' => $message->kind, 'error' => $e->getMessage()]);
        }

        $message->save();

        return $message;
    }

    /**
     * The accounts a message could go through, best first: the ISP's own, then the platform's.
     *
     * @return list<HostpinnacleCredentials>
     */
    protected function candidates(bool $operator = false): array
    {
        $settings = SmsSetting::current();

        if (! $settings->is_enabled && ! $operator) {
            return [];
        }

        $candidates = [];

        if ($settings->isComplete()) {
            $candidates[] = new HostpinnacleCredentials(
                (string) $settings->api_key,
                (string) $settings->sender_id,
                (string) $settings->username,
                (string) $settings->password,
                self::BASE_URL,
            );
        }

        $platform = config('services.hostpinnacle');

        if (is_array($platform) && filled($platform['api_key'] ?? null) && filled($platform['sender_id'] ?? null)) {
            $candidates[] = new HostpinnacleCredentials(
                (string) $platform['api_key'],
                (string) $platform['sender_id'],
                (string) ($platform['username'] ?? ''),
                (string) ($platform['password'] ?? ''),
                (string) ($platform['base_url'] ?? self::BASE_URL),
            );
        }

        return $candidates;
    }
}
