<?php

declare(strict_types=1);

/**
 * Focused tests for the Pi task-workflow extension (.pi/extensions/task-workflow).
 * Uses Node built-in test runner + TypeScript stripping (Node 22+) and a test-local ESM resolve hook.
 */

use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../vendor/autoload.php';

/**
 * @return array{0: string, 1: string}
 */
function pi_task_workflow_node_runtime(): array
{
    $node = getenv('NODE_BINARY');
    if (false === $node || '' === $node) {
        $node = 'node';
    }

    $versionProcess = new Process([$node, '--version']);
    $versionProcess->run();
    if (!$versionProcess->isSuccessful()) {
        throw new RuntimeException('Node.js is required for castor test:pi-task-workflow but `'.$node.' --version` failed: '.trim($versionProcess->getErrorOutput() ?: $versionProcess->getOutput()));
    }

    $version = trim($versionProcess->getOutput());
    if (!preg_match('/^v(\d+)\./', $version, $m)) {
        throw new RuntimeException('Unsupported Node.js runtime for Pi task-workflow tests: '.$version);
    }

    $major = (int) $m[1];
    if ($major < 22) {
        throw new RuntimeException('Pi task-workflow tests require Node.js 22+ (TypeScript stripping). Found: '.$version.'. Set NODE_BINARY to a supported Node or upgrade the runtime.');
    }

    return [$node, $version];
}

#[AsTask(name: 'test:pi-task-workflow', description: 'Run Pi task-workflow extension tests (Node built-in test)')]
function test_pi_task_workflow(): void
{
    $root = dirname(__DIR__);
    $testFile = $root.'/tests/pi-task-workflow/worktrees-extensions-vendor.test.mjs';
    $resolveHook = $root.'/tests/pi-task-workflow/register-loader.mjs';
    if (!is_file($testFile)) {
        throw new RuntimeException('Pi task-workflow test file missing: '.$testFile);
    }
    if (!is_file($resolveHook)) {
        throw new RuntimeException('Pi task-workflow resolve hook missing: '.$resolveHook);
    }

    [$node, $version] = pi_task_workflow_node_runtime();

    echo "\n=== Pi task-workflow extension tests ===\n";
    echo 'Node runtime: '.$version.' ('.$node.")\n";
    echo "Loader: tests/pi-task-workflow/register-loader.mjs (extensionless .ts + pi-coding-agent shim)\n\n";

    $process = new Process(
        [
            $node,
            '--import',
            $resolveHook,
            '--test',
            $testFile,
        ],
        $root,
        null,
        null,
        300.0,
    );
    $process->run(static function (string $type, string $buffer): void {
        echo $buffer;
    });

    if (!$process->isSuccessful()) {
        throw new RuntimeException('Pi task-workflow tests failed (exit '.$process->getExitCode().'). Node '.$version.'.');
    }
}
