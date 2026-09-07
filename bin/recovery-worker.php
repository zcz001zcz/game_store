<?php

declare(strict_types=1);

use GameStore\Support\Env;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$intervalSeconds = max(5, Env::int('RECOVERY_INTERVAL_SECONDS', 15));
$olderThan = max($intervalSeconds, Env::int('RECOVERY_AFTER_SECONDS', 30));
$batchSize = max(1, Env::int('RECOVERY_BATCH_SIZE', 20));

while (true) {
    $recovered = $app['reconciliation']->recoverStuck($batchSize, $olderThan);

    if ($recovered !== []) {
        fwrite(STDOUT, json_encode([
            'timestamp' => gmdate('c'),
            'event' => 'orders_requeued',
            'orders' => $recovered,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    sleep($intervalSeconds);
}
