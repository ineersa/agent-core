<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Covers claude/bin/task-workflow-mcp, the plugin's stdio entry point.
 */
final class TaskWorkflowMcpLauncherTest extends TestCase
{
    public function testRefusesUnexpandedOrRelativeDataDirectoryWithoutInstallingIntoCwd(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('mcp-launcher');
        try {
            $this->assertSame($directory, realpath($directory));
            foreach (['${CLAUDE_PLUGIN_DATA}', 'relative/data', ''] as $dataDir) {
                $process = new Process([\PHP_BINARY, \dirname(__DIR__).'/claude/bin/task-workflow-mcp'], $directory, ['CLAUDE_PLUGIN_DATA' => $dataDir, 'CLAUDE_PROJECT_DIR' => $directory, 'COMPOSER_BINARY' => '/nonexistent/composer'], '', 10.0);
                $process->run();

                $this->assertSame(1, $process->getExitCode(), $dataDir);
                $this->assertStringContainsString('CLAUDE_PLUGIN_DATA must be an absolute path', $process->getErrorOutput());
                $this->assertSame('', $process->getOutput());
                $this->assertSame([], array_values(array_diff((array) scandir($directory), ['.', '..'])));
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
