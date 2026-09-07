<?php

declare(strict_types=1);

use GameStore\Core\Http\Request;

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app['kernel']->handle(Request::fromGlobals())->send();
