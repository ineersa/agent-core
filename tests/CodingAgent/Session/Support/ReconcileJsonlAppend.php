<?php

declare(strict_types=1);

require dirname(__DIR__, 4).'/vendor/autoload.php';

$journal = new Ineersa\CodingAgent\Session\JsonlAppendJournal();
$journal->reconcile($argv[1]);
fwrite(\STDOUT, "reconciled\n");
