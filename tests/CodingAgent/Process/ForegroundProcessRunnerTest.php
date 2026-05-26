<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Process;

use Doctrine\DBAL\DriverManager;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCancelledException;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionContextInterface;
use Ineersa\CodingAgent\Process\ForegroundProcessRunner;
use Ineersa\CodingAgent\Process\ProcessSpec;
use Ineersa\CodingAgent\Process\ProcessTerminator;
use Ineersa\CodingAgent\Process\ToolProcessRegistry;
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
        @mkdir($this->tmpDir, 0o775, true);

        // In-memory SQLite connection for the registry.
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->registry = new ToolProcessRegistry($connection);
        $this->runner = new ForegroundProcessRunner(
            $this->registry,
            new ProcessTerminator(graceSeconds: 0),
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
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
        $context = $this->context(toolCallId: 'call-visible-during-run');

        // Start a php script that queries the process table via SQLite to verify
        // the record exists while the process runs.
        $runId = $context->runId();
        $toolCallId = $context->toolCallId();
        $dbPath = $this->tmpDir.'/.hatfield/test-registry.sqlite';
        $dir = \dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o775, true);
        }

        $script = <<<PHP
\$dbPath = \$argv[1];
\$runId = \$argv[2];
\$toolCallId = \$argv[3];
\$pdo = new \PDO('sqlite:' . \$dbPath);
\$deadline = microtime(true) + 1.0;
while (microtime(true) < \$deadline) {
    \$stmt = \$pdo->prepare('SELECT COUNT(*) FROM hatfield_tool_processes WHERE run_id = ? AND tool_call_id = ?');
    \$stmt->execute([\$runId, \$toolCallId]);
    if ((int) \$stmt->fetchColumn() > 0) {
        echo "registered\n";
        exit(0);
    }
    usleep(20_000);
}
echo "not found\n";
exit(2);
PHP;

        // Create a second registry that writes to the same sqlite db so the
        // test process can share the db.
        mkdir($this->tmpDir.'/.hatfield', 0o775, true);
        $sharedDbPath = $this->tmpDir.'/.hatfield/messenger.sqlite';
        $sharedConn = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'path' => $sharedDbPath,
        ]);
        $sharedRegistry = new ToolProcessRegistry($sharedConn);

        // Override the runner's registry to use the shared db registry.
        $runnerWithShared = new ForegroundProcessRunner(
            $sharedRegistry,
            new ProcessTerminator(graceSeconds: 0),
        );

        $result = $runnerWithShared->run(
            new ProcessSpec(
                command: [\PHP_BINARY, '-r', $script, $sharedDbPath, $runId, $toolCallId],
                cwd: $this->tmpDir,
                timeoutSeconds: 2,
                createProcessGroup: false,
            ),
            $context,
        );

        self::assertSame(0, $result->exitCode, $result->stderr);
        self::assertSame("registered\n", $result->stdout);
        self::assertSame([], $sharedRegistry->foregroundForRun($runId));
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
            new ProcessTerminator(graceSeconds: 0),
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
            new ProcessTerminator(graceSeconds: 0),
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
