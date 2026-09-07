<?php

declare(strict_types=1);

use GameStore\Core\Database\ConnectionFactory;
use GameStore\Core\Database\MigrationRunner;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$runner = new MigrationRunner(ConnectionFactory::create(), dirname(__DIR__) . '/database/migrations');
$applied = $runner->run();

if ($applied === []) {
	fwrite(STDOUT, "Database is up to date.\n");
    exit(0);
}

foreach ($applied as $migration) {
    fwrite(STDOUT, 'Applied ' . $migration . PHP_EOL);
}
