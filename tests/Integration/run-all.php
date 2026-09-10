<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$suites = [
    $root . '/tests/Integration/run.php',
    $root . '/tests/Integration/stage2.php',
];

foreach ($suites as $suite) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($suite);
    passthru($command, $exitCode);

    if ($exitCode !== 0) {
        exit($exitCode);
    }

    fwrite(STDOUT, "\n");
}

exit(0);
