<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Executes the real Pi TypeScript task-workflow modules under Node 22 type-stripping.
 *
 * This is the Castor/PHPUnit entrypoint for the checked-in behavioral harness at
 * tests/CodingAgent/Extension/PiTaskWorkflow/task-workflow.behavior.test.ts.
 *
 * Portability assumptions:
 * - Node.js >= 22 is available on PATH (type-stripping of .ts without a build step).
 *   When missing, this test is skipped (external optional dependency), not failed.
 * - Extension-relative imports use extensionless paths; the local ESM resolve hook
 *   under tests/CodingAgent/Extension/PiTaskWorkflow/ts-extension-resolve.mjs maps
 *   them to .ts files. No absolute monorepo paths and no Bun dependency.
 */
final class PiTaskWorkflowBehaviorTest extends TestCase
{
    public function testPiTaskWorkflowBehaviorHarness(): void
    {
        $projectRoot = \dirname(__DIR__, 3);
        $harness = $projectRoot.'/tests/CodingAgent/Extension/PiTaskWorkflow/task-workflow.behavior.test.ts';
        $resolver = $projectRoot.'/tests/CodingAgent/Extension/PiTaskWorkflow/ts-extension-resolve.mjs';
        $extensionRoot = $projectRoot.'/.pi/extensions/task-workflow';

        self::assertFileExists($harness);
        self::assertFileExists($resolver);
        self::assertDirectoryExists($extensionRoot);
        self::assertFileExists($extensionRoot.'/task-store.ts');
        self::assertFileExists($extensionRoot.'/worktrees.ts');

        $node = $this->resolveNodeBinary();
        if (null === $node) {
            self::markTestSkipped('Node.js >= 22 is required for Pi task-workflow behavioral tests (not found on PATH).');
        }

        $process = new Process(
            [
                $node,
                '--experimental-strip-types',
                '--import',
                $resolver,
                $harness,
            ],
            $projectRoot,
            null,
            null,
            60.0,
        );
        $process->run();

        $output = trim($process->getOutput()."\n".$process->getErrorOutput());
        self::assertSame(
            0,
            $process->getExitCode(),
            "Pi task-workflow behavioral harness failed (exit {$process->getExitCode()}):\n".$output,
        );
        self::assertStringContainsString(
            'PI_TASK_WORKFLOW_BEHAVIOR_OK',
            $output,
            "Harness did not emit success marker:\n".$output,
        );
        self::assertStringContainsString('5 behavioral case(s) passed', $output);
    }

    private function resolveNodeBinary(): ?string
    {
        $finder = new ExecutableFinder();
        $candidates = [];
        $fromPath = $finder->find('node');
        if (null !== $fromPath && '' !== $fromPath) {
            $candidates[] = $fromPath;
        }
        // Common local install locations; still no monorepo absolute imports.
        $candidates[] = '/usr/bin/node';
        $candidates[] = '/usr/local/bin/node';

        $seen = [];
        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate]) || !is_executable($candidate)) {
                continue;
            }
            $seen[$candidate] = true;

            $versionProcess = new Process([$candidate, '--version']);
            $versionProcess->run();
            if (0 !== $versionProcess->getExitCode()) {
                continue;
            }
            $version = trim($versionProcess->getOutput());
            if (1 === preg_match('/^v(\d+)\./', $version, $m) && (int) $m[1] >= 22) {
                return $candidate;
            }
        }

        return null;
    }
}
