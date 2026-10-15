<?php

namespace App\Services\RouterOs\Outages;

use App\Enums\HotspotUserStatus;
use App\Models\OutageCompensation;
use App\Models\Router;
use App\Models\RouterOutage;
use App\Services\RouterOs\Contracts\RouterOsConnector;
use App\Support\Audit\AuditLogger;
use Throwable;

/**
 * Logs customers back in after their router comes back, so they never see the portal again.
 *
 * A router that loses power forgets every session. Its users are still there — they are
 * configuration, and survive a reboot — but every phone is now an unauthenticated host, and
 * left alone each customer would land on the login page and wonder whether their money had
 * gone. RouterOS can log a host in on a customer's behalf (`/ip/hotspot/active/login`), given
 * the user's credentials and the device's MAC and current address.
 *
 * The MAC was noted while they were online. The address is looked up again now: DHCP may well
 * have handed the phone a different one after the reboot. A phone that has not rejoined the
 * wifi yet has no host entry, so this is retried every minute for a while rather than tried
 * once at the moment of recovery, when hardly anybody has reconnected.
 */
class HotspotRelogin
{
    /** How long after recovery to keep looking for a customer's phone. */
    public const WINDOW_MINUTES = 30;

    /** Refusals from the device itself, as opposed to the phone simply not being back yet. */
    public const MAX_REFUSALS = 5;

    public function __construct(
        protected RouterOsConnector $connector,
        protected AuditLogger $audit,
    ) {}

    public function attempt(Router $router): void
    {
        $pending = OutageCompensation::query()
            ->where('relogin_status', OutageCompensation::RELOGIN_PENDING)
            ->whereIn('router_outage_id', RouterOutage::query()->where('router_id', $router->id)->select('id'))
            ->with(['hotspotUser', 'outage'])
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        try {
            $connection = $this->connector->connect($router);
            $hosts = collect($connection->get('/ip/hotspot/host'))
                ->keyBy(fn (array $host): string => strtoupper((string) ($host['mac-address'] ?? '')));
            $active = collect($connection->get('/ip/hotspot/active'))->pluck('user')->all();
        } catch (Throwable) {
            // Gone again. The next run will try.
            return;
        }

        foreach ($pending as $compensation) {
            $user = $compensation->hotspotUser;
            $outage = $compensation->outage;

            if ($user->status !== HotspotUserStatus::Active || $user->hasExpired()) {
                $this->finish($compensation, OutageCompensation::RELOGIN_GAVE_UP, __('The package is no longer active.'));

                continue;
            }

            // They found the portal and signed in themselves; nothing left to do.
            if (in_array($user->username, $active, true)) {
                $this->finish($compensation, OutageCompensation::RELOGIN_DONE);

                continue;
            }

            $host = $hosts->get(strtoupper((string) $user->last_mac_address));

            if ($host === null || ($host['address'] ?? '') === '') {
                if ($outage->ended_at !== null && $outage->ended_at->lessThan(now()->subMinutes(self::WINDOW_MINUTES))) {
                    $this->finish($compensation, OutageCompensation::RELOGIN_GAVE_UP, __('The device did not come back to the wifi.'));
                }

                continue;
            }

            try {
                $connection->execute('/ip/hotspot/active/login', [
                    'user' => $user->username,
                    'password' => $user->password,
                    'mac-address' => (string) $user->last_mac_address,
                    'ip' => (string) $host['address'],
                ]);

                $this->finish($compensation, OutageCompensation::RELOGIN_DONE);
                $outage->increment('relogged_users');

                $this->audit->record(
                    event: 'hotspot_user.relogged_in',
                    auditable: $user,
                    description: sprintf('Logged %s back in on %s after the outage', $user->username, $router->name),
                );
            } catch (Throwable $e) {
                $compensation->relogin_attempts++;
                $compensation->relogin_error = mb_substr($e->getMessage(), 0, 250);

                if ($compensation->relogin_attempts >= self::MAX_REFUSALS) {
                    $compensation->relogin_status = OutageCompensation::RELOGIN_GAVE_UP;
                }

                $compensation->save();
            }
        }

        $connection->disconnect();
    }

    protected function finish(OutageCompensation $compensation, string $status, ?string $error = null): void
    {
        $compensation->relogin_status = $status;
        $compensation->relogin_error = $error;
        $compensation->relogged_in_at = $status === OutageCompensation::RELOGIN_DONE ? now() : null;
        $compensation->save();
    }
}
