<?php

declare(strict_types=1);

/**
 * Focused tests for the Pi task-workflow extension (.pi/extensions/task-workflow).
 * Uses Node tsx + node:test from a sibling pi-mono checkout (no npm install in agent-core).
 */

use Castor\Attribute\AsTask;

use function Castor\run;

require_once __DIR__.'/../vendor/autoload.php';

function pi_task_workflow_tsx_binary(): string
{
    $candidates = [
        dirname(__DIR__, 2).'/claw/pi-mono/node_modules/tsx/dist/cli.mjs',
        dirname(__DIR__, 2).'/pi-mono/node_modules/tsx/dist/cli.mjs',
        '/home/ineersa/claw/pi-mono/node_modules/tsx/dist/cli.mjs',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    throw new RuntimeException('tsx not found. Install dependencies in pi-mono (npm install) or set a checkout next to agent-core.');
}

#[AsTask(name: 'test:pi-task-workflow', description: 'Run Pi task-workflow extension tests (Node/tsx)')]
function test_pi_task_workflow(): void
{
    $root = dirname(__DIR__);
    $testFile = $root.'/tests/pi-task-workflow/worktrees-extensions-vendor.test.ts';
    $tsconfig = $root.'/tests/pi-task-workflow/tsconfig.json';
    if (!is_file($testFile)) {
        throw new RuntimeException('Pi task-workflow test file missing: '.$testFile);
    }

    $tsx = pi_task_workflow_tsx_binary();
    $cmd = sprintf(
        '%s %s --tsconfig %s --test %s',
        escapeshellarg(\PHP_BINARY),
        escapeshellarg($tsx),
        escapeshellarg($tsconfig),
        escapeshellarg($testFile),
    );
    run($cmd);
}
