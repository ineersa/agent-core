<?php

declare(strict_types=1);

/** Recover conclusively abandoned test artifacts and enforce QA report retention. */

use Castor\Attribute\AsTask;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';

#[AsTask(name: 'cleanup', namespace: 'clean', description: 'Recover abandoned owned test artifacts and retain the latest 10 completed QA reports')]
function cleanup(): void
{
    $root = (false !== ($_rp = realpath(__DIR__.'/..')) ? $_rp : __DIR__.'/..');
    $removed = (new CastorTasks\TestArtifactLifecycle($root))->recover();
    echo 'Recovered '.count($removed)." owned test artifact(s); QA report retention checked.\n";
    echo "Active and unmarked artifacts, build staging, session data and lock files are retained.\n";
}
