<?php

declare(strict_types=1);

use GameStore\Support\Env;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$workerId = gethostname() . ':' . getmypid();
$pollMs = max(10, Env::int('QUEUE_POLL_MS', 200));
$running = true;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void {
        $running = false;
    });
    pcntl_signal(SIGINT, static function () use (&$running): void {
        $running = false;
    });
}

while ($running) {
    if (!$app['worker']->runOnce($workerId)) {
        usleep($pollMs * 1000);
    }
}
