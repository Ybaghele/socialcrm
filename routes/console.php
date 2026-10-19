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

/*
 * Automation heartbeat. Every minute: enrol conversations that have gone quiet
 * into their follow-up sequences and queue the next due step. A customer reply
 * cancels the run, so this only ever chases people who went silent.
 */
Schedule::command('automations:process')->everyMinute()->withoutOverlapping();
