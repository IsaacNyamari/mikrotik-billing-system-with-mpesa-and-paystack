<?php

namespace App\Services\RouterOs\Outages;

use App\Models\Router;
use App\Models\RouterOutage;
use App\Models\SmsSetting;
use App\Services\Sms\SmsSender;
use App\Support\PhoneNumber;
use App\Support\Tenancy\Facades\Tenancy;

/**
 * Texts the ISP when one of their routers goes down, and again when it is back.
 *
 * The down text waits until the router has been silent for a few minutes: a single missed
 * probe is usually a tunnel catching its breath, and an ISP woken at 3am for a blip learns to
 * ignore the next one. The cause is only known once the router answers again (its uptime and
 * log say whether the power went), so the recovery text is the one that names it.
 *
 * These go to the ISP's own people, not subscribers, so they are sent even when texts to
 * subscribers are switched off, and at any hour.
 */
class OutageAlerts
{
    /** How long a router must be silent before anyone is texted. */
    public const GRACE_MINUTES = 3;

    public function __construct(protected SmsSender $sms) {}

    /**
     * Called each minute a router stays down. Texts once per outage, after the grace period.
     */
    public function stillDown(Router $router, RouterOutage $outage): void
    {
        if ($outage->alerted_at !== null || $outage->ended_at !== null) {
            return;
        }

        if ($outage->started_at->addMinutes(self::GRACE_MINUTES)->isFuture()) {
            return;
        }

        $recipients = $this->recipients();

        if ($recipients === []) {
            return;
        }

        // Claimed before sending, so two watchers overlapping on the same router cannot both
        // text. A missed text is better than two of every text.
        $claimed = RouterOutage::query()
            ->whereKey($outage->id)
            ->whereNull('alerted_at')
            ->update(['alerted_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $outage->alerted_at = now();

        $body = __(':router is down since :time (:minutes min). No power or no internet at the site. We will text you when it is back.', [
            'router' => $router->name,
            'time' => $outage->started_at->format('H:i'),
            'minutes' => (int) $outage->started_at->diffInMinutes(now()),
        ]);

        foreach ($recipients as $phone) {
            $this->sms->send($phone, $this->prefixed($body), 'router_down', operator: true);
        }
    }

    /**
     * Called when the outage closes. Only follows a down text that was actually sent.
     */
    public function recovered(Router $router, RouterOutage $outage): void
    {
        if ($outage->alerted_at === null) {
            return;
        }

        $body = __(':router is back online after :duration. Cause: :cause. Customers on running packages were credited the lost time.', [
            'router' => $router->name,
            'duration' => $outage->durationForHumans(),
            'cause' => $outage->cause?->label() ?? __('unknown'),
        ]);

        foreach ($this->recipients() as $phone) {
            $this->sms->send($phone, $this->prefixed($body), 'router_up', operator: true);
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

    protected function prefixed(string $body): string
    {
        return 'Router alert: '.$body;
    }
}
