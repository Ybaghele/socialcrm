<?php

namespace App\Console\Commands;

use App\Jobs\Automations\ProcessAutomationSequences;
use Illuminate\Console\Command;

/**
 * Runs every minute from the scheduler. Like the campaigns heartbeat it only
 * queues the real work, so a cron tick stays fast.
 *
 *   php artisan automations:process
 */
class ProcessAutomationsCommand extends Command
{
    protected $signature = 'automations:process';

    protected $description = 'Enrol quiet conversations into follow-up sequences and queue their next step';

    public function handle(): int
    {
        ProcessAutomationSequences::dispatch();

        $this->info('Automation sequences queued.');

        return self::SUCCESS;
    }
}
