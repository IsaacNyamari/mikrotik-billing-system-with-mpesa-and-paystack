<?php

namespace App\Services\RouterOs\Outages;

use App\Models\Router;
use App\Models\RouterOutage;
use App\Models\SmsMessage;
use App\Models\SmsSetting;
use App\Services\Sms\SmsSender;
use App\Support\PhoneNumber;
use App\Support\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tells the ISP the moment one of their routers fails, and again when it is back.
 *
 * Two kinds of failure, worded differently because the ISP does something different about each:
 *
 * - **Down**: it stopped answering — power, the site's internet, or the tunnel. Customers
 *   behind it are offline.
 * - **Failing**: it answers but refuses to be managed (a changed password, a removed API
 *   user). Customers may still be online, but nothing the platform does reaches it.
 *
 * The cause of a down router is only known once it answers again (its uptime and log say
 * whether the power went), so the recovery message is the one that names it.
 *
 * **Getting it to them.** Each alert goes to every number listed, through the ISP's own
 * HostPinnacle account and then the platform's if that one refuses, and is retried every
 * minute until accepted (up to {@see self::MAX_ATTEMPTS} tries per number). A copy goes to the
 * ISP's contact email too, so an SMS outage on its own cannot leave them uninformed. These are
 * for the ISP's own people, so they go at any hour and even with subscriber texts switched off.
 */
class OutageAlerts
{
    /**
     * Attempts per number per alert before giving up: a minute apart, and each round tries the
     * ISP's account and then the platform's, so about ten minutes of an SMS provider outage.
     */
    public const MAX_ATTEMPTS = 20;

    /** How far back unfinished alerts are retried. */
    protected const RETRY_WINDOW_HOURS = 6;

    public function __construct(protected SmsSender $sms) {}

    /**
     * A router has just stopped answering. Called once, when its outage opens.
     */
    public function opened(Router $router, RouterOutage $outage): void
    {
        if ($this->recipients() === [] && $this->email() === null) {
            return;
        }

        // Claimed before sending, so two watchers overlapping on the same router cannot both
        // send. A retry covers a send that then fails; nothing covers a duplicate.
        $claimed = RouterOutage::query()
            ->whereKey($outage->id)
            ->whereNull('alerted_at')
            ->update(['alerted_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $outage->alerted_at = now();

        $this->notify($router, 'router_down', "outage:{$outage->id}:down", __(':router stopped responding at :time. Possible power cut or internet loss at the site; customers on it are offline. We will tell you when it is back.', [
            'router' => $router->name,
            'time' => $outage->started_at->format('H:i'),
        ]));
    }

    /**
     * The outage closed. Only follows a down alert that was sent.
     */
    public function recovered(Router $router, RouterOutage $outage): void
    {
        if ($outage->alerted_at === null) {
            return;
        }

        $this->notify($router, 'router_up', "outage:{$outage->id}:up", __(':router is back online after :duration. Cause: :cause. Customers on running packages were credited the lost time.', [
            'router' => $router->name,
            'duration' => $outage->durationForHumans(),
            'cause' => $outage->cause?->label() ?? __('unknown'),
        ]));
    }

    /**
     * The router answers but the platform cannot manage it. Alerted once per episode.
     */
    public function failing(Router $router): void
    {
        if ($router->error_alerted_at !== null || ($this->recipients() === [] && $this->email() === null)) {
            return;
        }

        $now = now();

        $claimed = Router::query()
            ->whereKey($router->id)
            ->whereNull('error_alerted_at')
            ->update(['error_alerted_at' => $now]);

        if ($claimed === 0) {
            return;
        }

        $router->error_alerted_at = $now;

        $this->notify($router, 'router_error', "error:{$router->id}:{$now->timestamp}", __(':router answers but HumSols cannot manage it (:reason). Customers may still be online, but new logins, payments and changes cannot reach it.', [
            'router' => $router->name,
            'reason' => mb_strimwidth((string) $router->last_error, 0, 80, '…'),
        ]));
    }

    /**
     * Management works again after a failing alert.
     */
    public function working(Router $router): void
    {
        if ($router->error_alerted_at === null) {
            return;
        }

        $key = "error:{$router->id}:{$router->error_alerted_at->timestamp}:ok";

        Router::query()->whereKey($router->id)->update(['error_alerted_at' => null]);
        $router->error_alerted_at = null;

        $this->notify($router, 'router_ok', $key, __(':router can be managed again.', ['router' => $router->name]));
    }

    /**
     * Send again whatever has not been accepted yet. Called every minute for every router,
     * whatever state it is in, so a recovery alert is retried just like a down one.
     */
    public function retry(Router $router): void
    {
        // Switched off means nothing more goes out, including alerts queued before it was.
        if (! SmsSetting::current()->alert_outages) {
            return;
        }

        $attempts = SmsMessage::query()
            ->where('router_id', $router->id)
            ->whereNotNull('alert_key')
            ->where('created_at', '>=', now()->subHours(self::RETRY_WINDOW_HOURS))
            ->orderBy('id')
            ->get()
            ->groupBy(fn (SmsMessage $message): string => $message->alert_key.'|'.$message->phone);

        foreach ($attempts as $tries) {
            if ($tries->contains('status', SmsMessage::SENT) || $tries->count() >= self::MAX_ATTEMPTS) {
                continue;
            }

            $last = $tries->last();

            // A minute apart, which is also how often this runs.
            if ($last === null || $last->created_at?->gt(now()->subSeconds(50))) {
                continue;
            }

            $this->sms->sendAlert($last->phone, $last->body, $last->kind, $router->id, (string) $last->alert_key);
        }
    }

    /**
     * The numbers to text: the ones the ISP listed, otherwise their contact phone.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $settings = SmsSetting::current();

        if (! $settings->alert_outages) {
            return [];
        }

        $listed = array_filter(array_map('trim', explode(',', (string) $settings->alert_phones)));

        if ($listed === []) {
            $listed = [trim((string) Tenancy::tenant()?->contact_phone)];
        }

        $phones = [];

        foreach ($listed as $phone) {
            if ($phone !== '') {
                $phones[] = PhoneNumber::normalise($phone);
            }
        }

        return array_slice(array_values(array_unique($phones)), 0, 5);
    }

    protected function notify(Router $router, string $kind, string $key, string $body): void
    {
        $body = 'Router alert: '.$body;

        foreach ($this->recipients() as $phone) {
            $this->sms->sendAlert($phone, $body, $kind, $router->id, $key);
        }

        $this->emailCopy($body);
    }

    /**
     * A second channel. An SMS account out of credit or a phone that is off should not be
     * the difference between an ISP knowing and not knowing.
     */
    protected function emailCopy(string $body): void
    {
        $email = $this->email();

        if ($email === null) {
            return;
        }

        try {
            Mail::raw($body."\n\n".route('routers.index'), function ($message) use ($email, $body): void {
                $message->to($email)->subject(mb_strimwidth($body, 0, 90, '…'));
            });
        } catch (Throwable $e) {
            Log::warning('Router alert email could not be sent', ['error' => $e->getMessage()]);
        }
    }

    protected function email(): ?string
    {
        if (! SmsSetting::current()->alert_outages) {
            return null;
        }

        $email = trim((string) Tenancy::tenant()?->contact_email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
