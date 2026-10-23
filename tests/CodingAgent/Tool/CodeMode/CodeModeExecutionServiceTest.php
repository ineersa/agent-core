<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CodeModeExecutionServiceTest extends TestCase
{
    public function testIndependentOwnerAcknowledgesWhileHostIsBlockedInsideNestedTool(): void
    {
        // A service fake or a script-only sleep cannot establish retained host
        // ownership across processes. The lock is the barrier; timeout is only
        // a safety cap. Every kernel/DB and process belongs to this fixture.
        $directory = TestDirectoryIsolation::createProjectTempDir('code-mode-backend');
        try {
            $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Support/code-mode-backend-process.php', $directory, 'owner']);
            $process->setTimeout(15);
            $process->mustRun();
            $lines = explode("\n", trim($process->getOutput()));
            $e = json_decode($lines[array_key_last($lines)], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertContains('tool_call_acknowledged', $e['ack_events']);
            $this->assertNotContains('tool_execution_end', $e['ack_events']);
            $this->assertTrue($e['ack_acknowledged']);
            $this->assertSame('pending', $e['ack_status']);
            $this->assertTrue($e['worker_alive_after_ack']);
            $this->assertTrue($e['script_live_after_ack']);
            $this->assertTrue($e['socket_live_after_ack']);
            $this->assertNotSame($e['owner_pid'], $e['host_pid']);
            $this->assertSame([], $e['schedule_in_flight']);
            $this->assertSame(0, $e['duplicate_ack_events']);
            $this->assertSame(1, $e['worker']['processors']);
            $this->assertSame(1, $e['worker']['publications']);
            $this->assertFalse($e['worker']['error']);
            $this->assertSame(['original' => 42], $e['raw']['details']['code_mode_value']['nested']);
            $this->assertSame($e['raw']['details']['code_mode_value']['before'], $e['raw']['details']['code_mode_value']['after']);
            $this->assertSame(['stdout' => 'OUT', 'stderr' => 'ERR'], $e['raw']['details']['code_mode_diagnostics']);
            $this->assertStringContainsString('OUT', $e['notice']);
            $this->assertStringContainsString('ERR', $e['notice']);
            $this->assertStringContainsString($e['raw_path'], $e['notice']);
            $this->assertTrue($e['automatic_batch_cleanup']);
            $this->assertTrue($e['raw_exists_after_batch_delete']);
            $this->assertContains('agent_command_queued', $e['admission_events']);
            $this->assertSame(0, $e['duplicate_terminal_effects']);
            $this->assertSame(0, $e['duplicate_notice_events']);
            $this->assertTrue($e['workspace_cleaned']);
        } finally {
            if (isset($process) && $process->isRunning()) {
                $process->stop(0);
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
