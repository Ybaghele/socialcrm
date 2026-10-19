<?php

$root = dirname(__DIR__);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$auto = function (): int {
    $status = DB::select("SHOW TABLE STATUS LIKE 'jobs'");

    return (int) ($status[0]->Auto_increment ?? 0);
};

$a = $auto();
echo 't0=0s jobs_autoinc='.$a.PHP_EOL;

for ($elapsed = 30; $elapsed <= 420; $elapsed += 30) {
    sleep(30);
    $b = $auto();
    echo 't='.$elapsed.'s jobs_autoinc='.$b.' delta='.($b - $a).PHP_EOL;

    if ($b > $a) {
        echo 'JOB_INSERTED'.PHP_EOL;
    }
}

echo 'final_delta='.($auto() - $a).PHP_EOL;
