<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Process;

use Ineersa\CodingAgent\Process\ProcessTerminator;
use Ineersa\CodingAgent\Process\ToolProcessKindEnum;
use Ineersa\CodingAgent\Process\ToolProcessRecordDTO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ProcessTerminatorTest extends TestCase
{
    /** @var list<Process> */
    private array $processes = [];

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0, \SIGKILL);
            }
        }
    }

    public function testTerminateRunningProcessByPid(): void
    {
        $terminator = new ProcessTerminator(graceSeconds: 0);
        $process = $this->startProcess(['php', '-r', 'sleep(5);']);
        $pid = $this->requirePid($process);

        $this->assertTrue($terminator->terminatePid($pid));
        $this->assertProcessStops($process);
    }

    public function testTerminateNonExistentProcessReturnsFalse(): void
    {
        $terminator = new ProcessTerminator(graceSeconds: 0);

        $this->assertFalse($terminator->terminatePid(999_999_999));
    }

    public function testTerminateDoesNotSignalCurrentProcessGroup(): void
    {
        if (!\function_exists('posix_getpgrp')) {
            $this->markTestSkipped('Current process-group guard requires posix_getpgrp().');
        }

        $terminator = new ProcessTerminator(graceSeconds: 0);
        $process = $this->startProcess(['php', '-r', 'sleep(5);']);
        $pid = $this->requirePid($process);

        // Passing the current process group used to be dangerous: the
        // terminator would signal -$pgid and kill the test runner / Pi shell.
        // The guard must fall back to direct PID termination instead.
        $this->assertTrue($terminator->terminatePid($pid, posix_getpgrp()));
        $this->assertProcessStops($process);
    }

    public function testTerminateViaRecord(): void
    {
        $terminator = new ProcessTerminator(graceSeconds: 0);
        $process = $this->startProcess(['php', '-r', 'sleep(5);']);
        $pid = $this->requirePid($process);

        $this->assertTrue($terminator->terminate($this->record('call-1', $pid)));
        $this->assertProcessStops($process);
    }

    public function testTerminateAllCountsTerminatedProcesses(): void
    {
        $terminator = new ProcessTerminator(graceSeconds: 0);
        $first = $this->startProcess(['php', '-r', 'sleep(5);']);
        $second = $this->startProcess(['php', '-r', 'sleep(5);']);

        $count = $terminator->terminateAll([
            $this->record('call-1', $this->requirePid($first)),
            $this->record('call-2', $this->requirePid($second)),
        ]);

        $this->assertSame(2, $count);
        $this->assertProcessStops($first);
        $this->assertProcessStops($second);
    }

    public function testTerminateAllSkipsNonExistentProcesses(): void
    {
        $terminator = new ProcessTerminator(graceSeconds: 0);

        $this->assertSame(0, $terminator->terminateAll([
            $this->record('call-exited', 999_999_999),
        ]));
    }

    public function testSigkillEscalationForTermIgnoringProcess(): void
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('SIGKILL escalation test requires pcntl.');
        }

        $terminator = new ProcessTerminator(graceSeconds: 0);
        $process = $this->startProcess([
            'php',
            '-r',
            'pcntl_signal(SIGTERM, static function (): void {}); while (true) { pcntl_signal_dispatch(); usleep(100000); }',
        ]);

        $this->assertTrue($terminator->terminatePid($this->requirePid($process)));
        $this->assertProcessStops($process);
    }

    /** @param list<string> $command */
    private function startProcess(array $command, bool $createProcessGroup = false): Process
    {
        $process = new Process($command, timeout: null);
        if ($createProcessGroup) {
            $process->setOptions(['create_new_console' => true]);
        }
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    private function assertProcessStops(Process $process): void
    {
        $deadline = microtime(true) + 1.0;
        while ($process->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $this->assertFalse($process->isRunning(), 'Process should stop within 1 second.');
    }

    private function requirePid(Process $process): int
    {
        $pid = $process->getPid();
        $this->assertNotNull($pid);

        return $pid;
    }

    private function record(string $toolCallId, int $pid): ToolProcessRecordDTO
    {
        return new ToolProcessRecordDTO(
            runId: 'run-test',
            turnNo: 1,
            toolCallId: $toolCallId,
            kind: ToolProcessKindEnum::ForegroundTool,
            pid: $pid,
            processGroupId: null,
            commandPreview: 'php sleep',
            cwd: sys_get_temp_dir(),
            logPath: null,
            startedAt: new \DateTimeImmutable(),
        );
    }
}
