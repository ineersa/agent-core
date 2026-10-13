<?php

declare(strict_types=1);

/** Recover only completed QA scopes; active and unknown resources are retained. */

use Castor\Attribute\AsTask;

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/shared.php';

#[AsTask(name: 'cleanup', namespace: 'clean', description: 'Recover completed QA scopes and retain ten completed reports')]
function cleanup(): void
{
    \CastorTasks\prune_completed_qa_reports(\CastorTasks\project_root_dir());
    echo "Completed QA scopes reclaimed; active, unmarked and unrelated artifacts retained.\n";
}
