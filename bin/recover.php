<?php

declare(strict_types=1);

use GameStore\Support\Env;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$limit = max(1, Env::int('RECOVERY_BATCH_SIZE', 20));
$olderThan = max(1, Env::int('RECOVERY_AFTER_SECONDS', 30));
$recovered = $app['reconciliation']->recoverStuck($limit, $olderThan);

fwrite(STDOUT, json_encode([
    'count' => count($recovered),
    'requeued_orders' => $recovered,
], JSON_UNESCAPED_SLASHES) . PHP_EOL);
