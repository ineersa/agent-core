<?php

declare(strict_types=1);
use Ineersa\CodingAgent\Session\JsonlAppendJournal;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$journal = new JsonlAppendJournal();
$journal->reconcile($argv[1]);
fwrite(\STDOUT, "reconciled\n");
