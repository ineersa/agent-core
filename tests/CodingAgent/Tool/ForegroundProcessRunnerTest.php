<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCancelledException;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionContextInterface;
use Ineersa\CodingAgent\Tool\ForegroundProcessRunner;
use Ineersa\CodingAgent\Tool\ProcessSpec;
use Ineersa\CodingAgent\Tool\ToolProcessRegistry;
use Ineersa\CodingAgent\Tool\ToolProcessTerminator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ForegroundProcessRunnerTest extends TestCase
{
    private string $tmpDir;
    private ToolProcessRegistry $registry;
    private ForegroundProcessRunner $runner;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield-fgr-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir.'/.hatfield/tmp', 0o775, true);

        $this->registry = new ToolProcessRegistry($this->tmpDir);
        $this->runner = new ForegroundProcessRunner(
            $this->registry,
            new ToolProcessTerminator(graceSeconds: 0),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function testSuccessfulProcessReturnsStdoutStderrAndExitCode(): void
    {
        $result = $this->runner->run(
            new ProcessSpec(
                command: ['php', '-r', 'fwrite(STDERR, "err\\n"); echo "out\\n";'],
                cwd: $this->tmpDir,
                timeoutSeconds: 2,
                createProcessGroup: false,
            ),
            $this->context(),
        );

        self::assertSame("out\n", $result->stdout);
        self::assertSame("err\n", $result->stderr);
        self::assertSame(0, $result->exitCode);
        self::assertFalse($result->cancelled);
        self::assertFalse($result->timedOut);
    }

    public function testProcessCanObserveRegistryRecordBeforeItExitsAndRecordIsUnregisteredAfter(): void
    {
        $registryPath = $this->tmpDir.'/.hatfield/tmp/tool_process_registry.jsonl';
        $script = <<<'PHP'
$path = $argv[1];
$needle = $argv[2];
$deadline = microtime(true) + 1.0;
while (microtime(true) < $deadline) {
    if (is_file($path) && str_contains((string) file_get_contents($path), $needle)) {
        echo "registered\n";
        exit(0);
    }
    usleep(20_000);
}
fwrite(STDERR, "record not found\n");
exit(2);
PHP;

        $context = $this->context(toolCallId: 'call-visible-during-run');

        $result = $this->runner->run(
            new ProcessSpec(
                command: ['php', '-r', $script, $registryPath, $context->toolCallId()],
                cwd: $this->tmpDir,
                timeoutSeconds: 2,
                createProcessGroup: false,
            ),
            $context,
        );

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame("registered\n", $result->stdout);
        self::assertSame([], $this->registry->foregroundForRun($context->runId()));
    }

    public function testTimeoutTerminatesProcessAndReturnsTimedOut(): void
    {
        $result = $this->runner->run(
            new ProcessSpec(
                command: ['php', '-r', 'sleep(5);'],
                cwd: $this->tmpDir,
                timeoutSeconds: 1,
                createProcessGroup: false,
            ),
            $this->context(),
        );

        self::assertTrue($result->timedOut);
        self::assertFalse($result->cancelled);
    }

    public function testCancelledTokenTerminatesProcessAndReturnsCancelled(): void
    {
        $context = $this->context(cancelled: true);

        $result = $this->runner->run(
            new ProcessSpec(
                command: ['php', '-r', 'sleep(5);'],
                cwd: $this->tmpDir,
                timeoutSeconds: 5,
                createProcessGroup: false,
            ),
            $context,
        );

        self::assertTrue($result->cancelled);
        self::assertFalse($result->timedOut);
        self::assertSame([], $this->registry->foregroundForRun($context->runId()));
    }

    public function testDetachBackgroundDecisionLeavesRecordRegistered(): void
    {
        $runner = new ForegroundProcessRunner(
            $this->registry,
            new ToolProcessTerminator(graceSeconds: 0),
            decisionHook: static fn (Process $process, ToolExecutionContextInterface $context, int $durationMs): string => ForegroundProcessRunner::DECISION_DETACH_BACKGROUND,
        );
        $context = $this->context(toolCallId: 'call-detached');

        $runner->run(
            new ProcessSpec(
                command: ['php', '-r', 'echo "detach\n";'],
                cwd: $this->tmpDir,
                timeoutSeconds: 2,
                createProcessGroup: false,
            ),
            $context,
        );

        $records = $this->registry->foregroundForRun($context->runId());
        self::assertCount(1, $records);
        self::assertSame('call-detached', $records[0]->toolCallId);
    }

    public function testSignalTerminatedProcessIsReportedAsCancelled(): void
    {
        if (!\function_exists('posix_kill')) {
            self::markTestSkipped('Signal test requires posix_kill().');
        }

        $result = $this->runner->run(
            new ProcessSpec(
                command: ['php', '-r', 'posix_kill(posix_getpid(), SIGTERM);'],
                cwd: $this->tmpDir,
                timeoutSeconds: 2,
                createProcessGroup: false,
            ),
            $this->context(),
        );

        self::assertTrue($result->cancelled);
    }

    public function testEmptyCommandPreviewFallsBackToNonEmptyRecordPreview(): void
    {
        $runner = new ForegroundProcessRunner(
            $this->registry,
            new ToolProcessTerminator(graceSeconds: 0),
            decisionHook: static fn (Process $process, ToolExecutionContextInterface $context, int $durationMs): string => ForegroundProcessRunner::DECISION_DETACH_BACKGROUND,
        );
        $context = $this->context(toolCallId: 'call-empty-preview');

        $runner->run(
            new ProcessSpec(
                command: ['php', '-r', 'echo "preview\n";'],
                cwd: $this->tmpDir,
                timeoutSeconds: 2,
                createProcessGroup: false,
                commandPreview: '',
            ),
            $context,
        );

        $records = $this->registry->foregroundForRun($context->runId());
        self::assertCount(1, $records);
        self::assertSame('unknown command', $records[0]->commandPreview);
    }

    private function context(bool $cancelled = false, string $toolCallId = 'call-test'): ToolExecutionContextInterface
    {
        return new class($cancelled, $toolCallId) implements ToolExecutionContextInterface {
            public function __construct(
                private readonly bool $cancelled,
                private readonly string $toolCallId,
            ) {
            }

            public function runId(): string
            {
                return 'run-test-fgr';
            }

            public function turnNo(): int
            {
                return 1;
            }

            public function toolCallId(): string
            {
                return $this->toolCallId;
            }

            public function toolName(): string
            {
                return 'test_foreground';
            }

            public function timeoutSeconds(): int
            {
                return 5;
            }

            public function cancellationToken(): CancellationTokenInterface
            {
                return new class($this->cancelled) implements CancellationTokenInterface {
                    public function __construct(private readonly bool $cancelled)
                    {
                    }

                    public function isCancellationRequested(): bool
                    {
                        return $this->cancelled;
                    }
                };
            }

            public function throwIfCancellationRequested(): void
            {
                if ($this->cancelled) {
                    throw new ToolCancelledException('Cancelled');
                }
            }
        };
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir((string) $file->getRealPath()) : @unlink((string) $file->getRealPath());
        }

        @rmdir($path);
    }
}
