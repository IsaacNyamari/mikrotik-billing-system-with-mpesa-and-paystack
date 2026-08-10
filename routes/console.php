<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A customer whose hour is up should lose access within the minute, so this runs often and
// is kept cheap: it only touches routers that actually have something to remove.
// --retry also re-attempts packages that were paid for while a router was unreachable.
Schedule::command('hotspot:sweep --retry')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Keeps the dashboard's view of each device honest, and records outages.
Schedule::command('router:test')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
