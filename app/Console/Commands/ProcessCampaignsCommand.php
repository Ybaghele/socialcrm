<?php

namespace App\Console\Commands;

use App\Jobs\Campaigns\ProcessCampaignEnrollments;
use Illuminate\Console\Command;

/**
 * Runs every minute from the scheduler. It only queues the real work — the same
 * queue worker that delivers messages picks it up — so the cron tick stays quick.
 *
 *   php artisan campaigns:process
 */
class ProcessCampaignsCommand extends Command
{
    protected $signature = 'campaigns:process';

    protected $description = 'Enrol opted-in contacts into active broadcasts and queue their deliveries';

    public function handle(): int
    {
        ProcessCampaignEnrollments::dispatch();

        $this->info('Campaign enrolment + delivery queued.');

        return self::SUCCESS;
    }
}
