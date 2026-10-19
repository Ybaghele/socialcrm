<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Campaigns heartbeat. Every minute: enrol any new opted-in contacts into the
 * active broadcasts and queue a batch of deliveries. Overlapping is skipped so a
 * slow tick cannot pile up on top of itself.
 */
Schedule::command('campaigns:process')->everyMinute()->withoutOverlapping();
