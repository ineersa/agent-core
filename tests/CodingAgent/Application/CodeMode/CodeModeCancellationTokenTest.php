<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\CodeMode;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CodeModeCancellationTokenTest extends TestCase
{
    public function testBlockedNestedHandlerKeepsCancellationAfterParentStartsAnotherTurn(): void
    {
        // Independent owner and host processes are required to prove that a
        // nested handler missed the transient parent status. The lock is the
        // barrier. The process timeout is a teardown safety cap, not a delay.
        $directory = TestDirectoryIsolation::createProjectTempDir('code-mode-cancel');
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Support/code-mode-backend-process.php', $directory, 'owner', 'cancel']);
        $process->setTimeout(15);
        try {
            $process->mustRun();
            $lines = explode("\n", trim($process->getOutput()));
            $evidence = json_decode($lines[array_key_last($lines)], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame('running', $evidence['parent_status']);
            $this->assertTrue($evidence['nested_cancelled']);
            $this->assertSame('cancelled', $evidence['reason']);
            $this->assertSame(1, $evidence['worker']['processors']);
            $this->assertSame(0, $evidence['worker']['publications']);
            $this->assertTrue($evidence['worker']['error']);
            $this->assertSame('completed', $evidence['deferred_status']);
            $this->assertTrue($evidence['raw_exists']);
            $this->assertTrue($evidence['workspace_cleaned']);
            $this->assertTrue($evidence['batch_cleaned']);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
