<?php

declare(strict_types=1);

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$workerId = gethostname() . ':' . getmypid();
$processed = $app['worker']->runOnce($workerId);

fwrite(STDOUT, $processed ? "Processed one job.\n" : "No jobs available.\n");
