<?php

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// MySQL 8 caches table stats (default 24h), which makes AUTO_INCREMENT stale.
// Force fresh reads for this session.
DB::statement('SET SESSION information_schema_stats_expiry = 0');

$auto = function (): int {
    $status = DB::select("SHOW TABLE STATUS LIKE 'jobs'");

    return (int) ($status[0]->Auto_increment ?? 0);
};

$rows = fn (): int => (int) DB::table('jobs')->count();
$failedAuto = function (): int {
    $status = DB::select("SHOW TABLE STATUS LIKE 'failed_jobs'");

    return (int) ($status[0]->Auto_increment ?? 0);
};

$a = $auto();
$fa = $failedAuto();
echo 't0 jobs_autoinc='.$a.' rows='.$rows().' failed_autoinc='.$fa.PHP_EOL;

for ($elapsed = 15; $elapsed <= 360; $elapsed += 15) {
    sleep(15);
    $b = $auto();
    $fb = $failedAuto();
    echo 't='.$elapsed.'s jobs_autoinc='.$b.' delta='.($b - $a).' rows='.$rows().' failed_delta='.($fb - $fa).PHP_EOL;

    if ($b > $a) {
        echo 'JOB_INSERTED'.PHP_EOL;
    }
}

echo 'final_delta='.($auto() - $a).PHP_EOL;
