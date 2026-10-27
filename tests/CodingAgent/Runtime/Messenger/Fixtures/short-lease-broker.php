<?php

declare(strict_types=1);

// Run the normal broker entry point with its supported short lease option.
$console = $argv[1];
$_SERVER['argv'] = [$console, ...array_slice($argv, 2), '--redeliver-timeout=2'];
$_SERVER['argc'] = count($_SERVER['argv']);
require $console;
