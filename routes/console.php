<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Every overlap guard below carries an explicit expiry, and that number is load-bearing.
 *
 * withoutOverlapping() takes a lock before a task runs and releases it after. A task that is
 * killed rather than finished — a deploy restarting the scheduler, an OOM, a reboot — never
 * reaches the release, and the lock then sits there until it expires. Left at the default that
 * is **24 hours**, during which the scheduler dutifully reports "no scheduled commands are
 * ready to run" every single minute while packages never expire and payments are never chased.
 *
 * Observed in production on 2026-08-13: three stuck locks, all three per-minute tasks silently
 * skipped. Nothing was broken and nothing complained; the work simply stopped.
 *
 * So each expiry is set to a few multiples of the task's own interval — long enough that a
 * genuinely slow run is not started twice, short enough that a killed one heals itself before
 * anybody notices. deploy/release.sh also clears these after restarting the scheduler, so a
 * deploy is not a way to wedge it.
 */

// A customer whose hour is up should lose access within the minute, so this runs often and
// is kept cheap: it only touches routers that actually have something to remove.
// --retry also re-attempts packages that were paid for while a router was unreachable.
Schedule::command('hotspot:sweep --retry')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// For customers who paid and then closed the page: the portal asks the provider on every poll,
// but nobody is polling once the tab is gone. Their purchase still has to be recorded and their
// login still has to exist.
Schedule::command('payments:reconcile')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// Bills each ISP for the month just ended and ages the bills that went unpaid. Daily rather
// than monthly: raising an invoice that already exists does nothing, whereas a scheduler that
// missed the first of the month would skip a month's income outright.
//
// A longer guard than the rest, because this one walks every tenant — but nothing like a day,
// or a single killed run costs the platform a night's billing.
Schedule::command('billing:run')
    ->dailyAt('01:30')
    ->withoutOverlapping(60)
    ->runInBackground();

// Keeps the dashboard's view of each device honest, and records outages.
Schedule::command('router:test')
    ->everyFiveMinutes()
    ->withoutOverlapping(15)
    ->runInBackground();
