<?php

namespace App\Services\RouterOs\Outages;

use App\Enums\OutageCause;
use App\Enums\RouterStatus;
use App\Models\HotspotUser;
use App\Models\Router;
use App\Models\RouterOutage;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Services\RouterOs\RouterHealthProbe;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Notices when a router goes down, and puts things right when it comes back.
 *
 * Run once a minute per router. Each run is one of three things:
 *
 *  - the router does not answer: an outage is opened, stamped from the last moment it did;
 *  - it answers after an outage: the outage is closed, its cause read off the device, the
 *    customers who lost time are given it back, and those who were online are logged in again;
 *  - it answers as usual: who is online, and on which device, is noted — that is what makes
 *    logging them back in possible after the next power cut.
 *
 * Only "could not connect" opens an outage. A router that answered but refused something
 * (RouterStatus::Error) is up; its customers are online, and crediting them would be giving
 * away time nobody lost.
 */
class RouterWatcher
{
    /**
     * Allowance either side of a boot time read from the device's uptime: clocks differ, and
     * the uptime is read a moment after the boot it measures.
     */
    protected const CLOCK_SLACK_SECONDS = 90;

    public function __construct(
        protected RouterHealthProbe $probe,
        protected RouterOsConnector $connector,
        protected OutageCompensator $compensator,
        protected HotspotRelogin $relogin,
        protected AuditLogger $audit,
        protected SetupFinisher $finisher,
        protected OutageAlerts $alerts,
    ) {}

    public function watch(Router $router): void
    {
        // Read before the probe overwrites it: this is the last moment the router was known to
        // be up, and so the earliest it can have gone down.
        $lastSeen = $router->last_seen_at;

        $this->probe->probe($router);

        // Alerts not yet accepted by the SMS provider go again, whatever state the router is in.
        $this->alerts->retry($router);

        if ($router->status === RouterStatus::Offline) {
            $this->down($router, $lastSeen);

            return;
        }

        if ($router->status === RouterStatus::Error) {
            // It answered, but the platform cannot manage it.
            $this->alerts->failing($router);

            return;
        }

        if ($router->status !== RouterStatus::Online) {
            return;
        }

        $this->alerts->working($router);

        $open = RouterOutage::query()->where('router_id', $router->id)->open()->latest('started_at')->first();

        if ($open !== null) {
            $this->recovered($router, $open);
        }

        // Re-login runs every minute for a while after an outage, not only on the minute it
        // ended: phones take their time finding the wifi again after a router reboots.
        $this->relogin->attempt($router);

        $this->noteWhoIsOnline($router);

        // A router the platform just rebuilt: confirm its HotSpot, or build it over the API
        // if its first boot could not.
        $this->finisher->finish($router);
    }

    /**
     * Open an outage, unless one is already open.
     */
    protected function down(Router $router, ?CarbonImmutable $lastSeen): void
    {
        // Never seen at all is a router still being set up, not an outage.
        if ($lastSeen === null) {
            return;
        }

        $open = RouterOutage::query()->where('router_id', $router->id)->open()->exists();

        if ($open) {
            return;
        }

        $outage = new RouterOutage;
        $outage->router_id = $router->id;
        $outage->started_at = $lastSeen;
        $outage->detected_at = now();
        $outage->last_error = mb_substr((string) $router->last_error, 0, 250);
        $outage->save();

        $this->audit->record(
            event: 'router.outage.started',
            auditable: $router,
            description: sprintf('%s stopped answering. Last seen %s.', $router->name, $outage->started_at->toDateTimeString()),
        );

        // The ISP hears about it now, not when it is over.
        $this->alerts->opened($router, $outage);
    }

    /**
     * Close an outage: when, why, and what it cost the customers.
     */
    protected function recovered(Router $router, RouterOutage $outage): void
    {
        $outage->ended_at = now();

        [$cause, $bootedAt] = $this->cause($router, $outage);

        $outage->cause = $cause;
        $outage->device_booted_at = $bootedAt;
        $outage->save();

        $this->audit->record(
            event: 'router.outage.ended',
            auditable: $router,
            description: sprintf(
                '%s is back after %s (%s).',
                $router->name,
                $outage->durationForHumans(),
                $cause->label(),
            ),
        );

        $this->compensator->compensate($outage);

        $this->alerts->recovered($router, $outage);
    }

    /**
     * Why it went down, from the device's own account.
     *
     * Its uptime says whether it restarted during the outage. If it did, its log says how: a
     * router that lost power announces on its next boot that it "was rebooted without proper
     * shutdown". The log lives in memory and starts afresh at boot, so that line is there to
     * be read precisely when it matters.
     *
     * @return array{OutageCause, CarbonImmutable|null}
     */
    protected function cause(Router $router, RouterOutage $outage): array
    {
        if ($router->uptime_seconds === null) {
            return [OutageCause::Unknown, null];
        }

        $bootedAt = CarbonImmutable::now()->subSeconds($router->uptime_seconds);

        // Booted before the outage began: the router never went down, the road to it did.
        if ($bootedAt->lessThan($outage->started_at->subSeconds(self::CLOCK_SLACK_SECONDS))) {
            return [OutageCause::Connectivity, null];
        }

        return [$this->restartedUncleanly($router) ? OutageCause::Power : OutageCause::Reboot, $bootedAt];
    }

    protected function restartedUncleanly(Router $router): bool
    {
        try {
            $connection = $this->connector->connect($router);
            $log = $connection->get('/log');
            $connection->disconnect();
        } catch (Throwable) {
            // Without the log there is no telling a power cut from a reboot. An unclean one
            // is by far the likelier when nobody chose to restart it.
            return true;
        }

        foreach ($log as $entry) {
            if (str_contains(strtolower((string) ($entry['message'] ?? '')), 'without proper shutdown')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Note which device each logged-in customer is on.
     *
     * After a power cut the router has forgotten every session. This is how the platform
     * knows, when it returns, whose phone to log back in and by which MAC address.
     */
    protected function noteWhoIsOnline(Router $router): void
    {
        try {
            $connection = $this->connector->connect($router);
            $active = $connection->get('/ip/hotspot/active');
            $connection->disconnect();
        } catch (Throwable) {
            return;
        }

        foreach ($active as $session) {
            $username = (string) ($session['user'] ?? '');

            if ($username === '') {
                continue;
            }

            HotspotUser::query()
                ->where('router_id', $router->id)
                ->where('username', $username)
                ->update([
                    'last_mac_address' => strtoupper((string) ($session['mac-address'] ?? '')) ?: null,
                    'last_ip_address' => ($session['address'] ?? '') ?: null,
                    'last_online_at' => now(),
                ]);
        }
    }
}
