<?php

namespace App\Services\RouterOs\Outages;

use App\Enums\HotspotUserStatus;
use App\Models\HotspotUser;
use App\Models\OutageCompensation;
use App\Models\RouterOutage;
use App\Services\RouterOs\HotspotUserProvisioner;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Gives customers back the time an outage took from them.
 *
 * A HotSpot package is sold by the clock, so an hour bought at two ends at three even if the
 * router spent twenty minutes of it without power. Each customer is credited the part of the
 * outage that fell inside *their* package — somebody whose hour began after the lights came
 * back lost nothing, and somebody whose hour ended ten minutes in lost ten minutes, not twenty.
 *
 * Exactly once per customer per outage: the unique index on the compensation row is the
 * arbiter, so a watcher run that is retried, or two that overlap, cannot credit anybody twice.
 */
class OutageCompensator
{
    /**
     * Too short to be worth a line on anyone's receipt, and inside the watcher's own minute of
     * uncertainty about when the router actually went down.
     */
    public const MINIMUM_SECONDS = 60;

    public function __construct(
        protected HotspotUserProvisioner $provisioner,
        protected AuditLogger $audit,
    ) {}

    public function compensate(RouterOutage $outage): void
    {
        if ($outage->compensated_at !== null || $outage->ended_at === null) {
            return;
        }

        $start = $outage->started_at;
        $end = $outage->ended_at;

        // Who had paid-for time running at some point during the outage. Failed is included:
        // that is a customer who paid while the router was unreachable and has been waiting
        // for their login ever since.
        $candidates = HotspotUser::query()
            ->where('router_id', $outage->router_id)
            ->whereIn('status', [HotspotUserStatus::Active, HotspotUserStatus::Failed])
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $start)
            ->where('created_at', '<', $end)
            ->with(['plan', 'router'])
            ->get();

        $affected = 0;
        $credited = 0;

        foreach ($candidates as $user) {
            $seconds = $this->lostSeconds($user, $start, $end);

            if ($seconds < self::MINIMUM_SECONDS) {
                continue;
            }

            if (! $this->credit($outage, $user, $seconds)) {
                continue;
            }

            $affected++;
            $credited += $seconds;

            // Put the longer package on the device: the expiry in its comment, and the user
            // itself for anyone whose provisioning failed while the router was down.
            $this->provisioner->provision($user->refresh()->loadMissing(['plan', 'router']));
        }

        $outage->affected_users = $affected;
        $outage->credited_seconds = $credited;
        $outage->compensated_at = now();
        $outage->save();

        if ($affected > 0) {
            $this->audit->record(
                event: 'router.outage.compensated',
                auditable: $outage->router,
                description: sprintf(
                    'Gave %d customer(s) back %s of package time after the outage on %s.',
                    $affected,
                    RouterOutage::humanDuration($credited),
                    $outage->router->name,
                ),
            );
        }
    }

    /**
     * How much of this customer's package the outage overlapped.
     */
    protected function lostSeconds(HotspotUser $user, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $from = $user->provisioned_at !== null && $user->provisioned_at->greaterThan($start)
            ? $user->provisioned_at
            : ($user->created_at !== null && $user->created_at->greaterThan($start) ? $user->created_at : $start);

        /** @var CarbonImmutable $expires */
        $expires = $user->expires_at;
        $to = $expires->lessThan($end) ? $expires : $end;

        return max(0, (int) $from->diffInSeconds($to, absolute: false));
    }

    /**
     * Record the credit and extend the package, together or not at all.
     */
    protected function credit(RouterOutage $outage, HotspotUser $user, int $seconds): bool
    {
        return DB::transaction(function () use ($outage, $user, $seconds): bool {
            $compensation = OutageCompensation::query()->createOrFirst(
                ['router_outage_id' => $outage->id, 'hotspot_user_id' => $user->id],
                ['credited_seconds' => $seconds],
            );

            // Already credited for this outage by an earlier run.
            if (! $compensation->wasRecentlyCreated) {
                return false;
            }

            // Online in the minute or so before the router went down: they were using it, and
            // are worth logging back in rather than leaving to find the portal again.
            $wasOnline = $user->last_online_at !== null
                && $user->last_online_at->greaterThanOrEqualTo($outage->started_at->subMinutes(3))
                && $user->last_mac_address !== null;

            $compensation->was_online = $wasOnline;
            $compensation->relogin_status = $wasOnline ? OutageCompensation::RELOGIN_PENDING : null;
            $compensation->save();

            /** @var HotspotUser $locked */
            $locked = HotspotUser::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->expires_at = $locked->expires_at?->addSeconds($seconds);
            $locked->compensated_seconds = $locked->compensated_seconds + $seconds;
            $locked->save();

            return true;
        });
    }
}
