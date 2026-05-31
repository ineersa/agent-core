<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BashBackgroundPromptAdapterInterface;
use Ineersa\CodingAgent\Tool\BashTool;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

/**
 * @covers \Ineersa\CodingAgent\Tool\BashTool
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcessManager
 * @covers \Ineersa\CodingAgent\Config\BashToolConfig
 * @covers \Ineersa\CodingAgent\Config\BackgroundProcessConfig
 * @covers \Ineersa\CodingAgent\Config\OutputCapConfig
 * @covers \Ineersa\CodingAgent\Tool\OutputCap
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 *
 * Sleep budget: tests use short-lived commands and minimal polling.
 * Backgrounded processes have at most ~100ms teardown overhead.
 */
final class BashToolTest extends TestCase
{
    private const string TEST_SESSION = 'bash-test-session';

    private Connection $connection;
    private BackgroundProcessManager $manager;
    private BackgroundProcessConfig $bgConfig;
    private BashToolConfig $bashConfig;
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;
    private OutputCap $outputCap;
    private string $tmpDir;
    private ?BashTool $tool = null;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->tmpDir = sys_get_temp_dir().'/hatfield_bashtool_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);

        $this->bgConfig = new BackgroundProcessConfig(
            storageDir: $this->tmpDir,
            stopGraceSeconds: 1,
            logTailChars: 5000,
        );
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 2,
            backgroundPromptThresholdSeconds: 1,
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );

        $denormalizer = new ObjectNormalizer(nameConverter: new CamelCaseToSnakeCaseNameConverter());
        $store = new ProcessStore($this->connection, $denormalizer, new NullLogger());
        $lifecycle = new ProcessLifecycle($this->bgConfig, new NullLogger());
        $this->manager = new BackgroundProcessManager($store, $lifecycle, $this->bgConfig, new NullLogger());
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);
        $this->outputCap = new OutputCap(new OutputCapConfig(storageDir: $this->tmpDir.'/output-cap'));
    }

    protected function tearDown(): void
    {
        // Direct SIGKILL via .pid files to avoid grace sleep in shutdownCleanup
        if (isset($this->manager)) {
            $bgDir = $this->bgConfig->storageDir;
            if (is_dir($bgDir)) {
                foreach (new \FilesystemIterator($bgDir, \FilesystemIterator::SKIP_DOTS) as $file) {
                    if ('pid' === $file->getExtension()) {
                        $pid = (int) file_get_contents((string) $file);
                        if ($pid > 0 && is_dir('/proc/'.$pid)) {
                            @exec('kill -KILL -'.$pid.' 2>/dev/null');
                            @exec('kill -KILL '.$pid.' 2>/dev/null');
                        }
                    }
                }
            }
        }

        $this->rmDir($this->tmpDir);
    }

    /* ── Successful completion ── */

    public function testSuccessfulCommand(): void
    {
        $this->createTool();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => 'echo "hello from bash"']);
        });

        $this->assertStringContainsString('hello from bash', $result);
        $this->assertStringNotContainsString('timed out', $result);
        $this->assertStringNotContainsString('cancelled', $result);
        $this->assertStringNotContainsString('background', $result);
        $this->assertStringNotContainsString('failed', $result);
    }

    public function testSuccessfulCommandWithNewlines(): void
    {
        $this->createTool();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => "printf 'line1\nline2\nline3\n'"]);
        });

        $this->assertStringContainsString('line1', $result);
        $this->assertStringContainsString('line2', $result);
        $this->assertStringContainsString('line3', $result);
    }

    /* ── Non-zero exit code ── */

    public function testNonZeroExitCode(): void
    {
        $this->createTool();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => 'echo "before error" && exit 42']);
        });

        $this->assertStringContainsString('exit code 42', $result);
        $this->assertStringContainsString('before error', $result);
    }

    public function testNonExistentCommand(): void
    {
        $this->createTool();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => 'nonexistent_command_xyz_123']);
        });

        $this->assertStringContainsString('failed', $result);
        $this->assertStringContainsString('exit code', $result);
    }

    /* ── Timeout ── */

    public function testTimeoutStopsProcess(): void
    {
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 1,
            backgroundPromptThresholdSeconds: 30, // never trigger background prompt
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );
        $this->createTool();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => 'echo "partial" && sleep 10 && echo "should not see"']);
        });

        $this->assertStringContainsString('timed out', $result);
        $this->assertStringContainsString('partial', $result);
    }

    /* ── Cancellation ── */

    public function testCancellationStopsProcess(): void
    {
        $callCount = 0;
        $cancellationToken = $this->createStub(CancellationTokenInterface::class);
        $cancellationToken
            ->method('isCancellationRequested')
            ->willReturnCallback(function () use (&$callCount) {
                ++$callCount;

                // First call is the pre-check in ToolRuntime::run().
                // Second+ are from the supervision loop and will trigger cancellation.
                return $callCount >= 2;
            });

        $context = new ToolContext(
            runId: self::TEST_SESSION,
            turnNo: 1,
            toolCallId: 'tc_bash',
            toolName: 'bash',
            cancellationToken: $cancellationToken,
            timeoutSeconds: 30,
        );

        $this->createTool();

        // ToolRuntime::run() detects stale cancellation AFTER the callback
        // returns, so we expect a RuntimeException about stale result.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('due to run cancellation');

        $this->contextAccessor->with($context, function (): string {
            return ($this->tool)(['command' => 'echo "before cancel" && sleep 10']);
        });
    }

    /* ── Argument validation ── */

    public function testMissingCommandThrows(): void
    {
        $this->createTool();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('command');

        $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)([]));
    }

    public function testEmptyCommandThrows(): void
    {
        $this->createTool();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('command');

        $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['command' => '']));
    }

    public function testInvalidTimeoutThrows(): void
    {
        $this->createTool();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('timeout');

        $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['command' => 'echo hi', 'timeout' => -5]));
    }

    /* ── Background prompt acceptance ── */

    public function testBackgroundPromptAcceptance(): void
    {
        $promptAdapter = $this->createMock(BashBackgroundPromptAdapterInterface::class);
        $promptAdapter
            ->expects($this->once())
            ->method('shouldBackground')
            ->with(
                $this->stringContains('echo "background me"'),
                $this->isInt(),
                $this->stringContains('.log'),
                $this->greaterThan(0),
            )
            ->willReturn(true);

        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 30,
            backgroundPromptThresholdSeconds: 0, // trigger immediately
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );
        $this->createTool($promptAdapter);

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => 'echo "background me" && sleep 10']);
        });

        $this->assertStringContainsString('background', $result);
        $this->assertStringContainsString('PID:', $result);
        $this->assertStringContainsString('Log:', $result);
        $this->assertStringNotContainsString('timed out', $result);
        $this->assertStringNotContainsString('cancelled', $result);
    }

    public function testBackgroundPromptWithAlreadyFinishedProcess(): void
    {
        $promptAdapter = $this->createMock(BashBackgroundPromptAdapterInterface::class);
        $promptAdapter
            ->expects($this->never())
            ->method('shouldBackground');

        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 30,
            backgroundPromptThresholdSeconds: 5, // won't be reached for a fast command
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );
        $this->createTool($promptAdapter);

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => 'echo "quick command"']);
        });

        $this->assertStringContainsString('quick command', $result);
        $this->assertStringNotContainsString('background', $result);
    }

    /* ── Background prompt decline (default behaviour) ── */

    public function testDefaultPromptDecline(): void
    {
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 30,
            backgroundPromptThresholdSeconds: 0, // trigger immediately
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );
        $this->createTool();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => 'echo "decline test"']);
        });

        $this->assertStringContainsString('decline test', $result);
        $this->assertStringNotContainsString('background', $result);
    }

    /* ── No duplicate command execution ── */

    public function testNoDuplicateCommandExecution(): void
    {
        $promptAdapter = $this->createStub(BashBackgroundPromptAdapterInterface::class);
        $promptAdapter
            ->method('shouldBackground')
            ->willReturn(true);

        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 30,
            backgroundPromptThresholdSeconds: 0, // trigger immediately
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );
        $this->createTool($promptAdapter);

        // Process writes unique marker, background acceptance leaves it running
        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => 'echo "background-marker-12345" && sleep 5']);
        });

        $this->assertStringContainsString('background', $result);
        $this->assertStringContainsString('PID:', $result);

        // Extract PID from result
        \preg_match('/PID: (\d+)/', $result, $matches);
        $this->assertNotEmpty($matches, 'PID should be present in result');
        $pid = (int) $matches[1];

        // Verify there's exactly 1 background process
        $records = $this->manager->list(self::TEST_SESSION);
        $this->assertCount(1, $records, 'There should be exactly one process');

        // Verify it's the same PID (single execution, no duplicate)
        $this->assertSame($pid, $records[0]->pid, 'The background process should have the same PID');

        // Verify the log contains our unique marker
        \usleep(200_000); // Wait for log flush
        $logContent = \file_get_contents($records[0]->logPath);
        $this->assertStringContainsString('background-marker-12345', $logContent ?: '');

        // Clean up
        $this->manager->stop($pid, self::TEST_SESSION);
    }

    /* ── Output capping ── */

    public function testOutputCapping(): void
    {
        $tinyCap = new OutputCapConfig(
            storageDir: $this->tmpDir.'/output-cap-tiny',
            defaultCap: 10,
            docCap: 10,
        );
        $this->outputCap = new OutputCap($tinyCap);
        $this->createTool();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => 'echo "this is a very long output that should be truncated"']);
        });

        $this->assertStringContainsString('Output capped', $result);
    }

    /* ── Registry exposure ── */

    public function testRegistryExposesTool(): void
    {
        $this->createTool();

        $registry = new ToolRegistry([$this->tool]);

        $definitions = $registry->activeToolDefinitions();

        $bashDef = null;
        foreach ($definitions as $def) {
            if ('bash' === $def->name) {
                $bashDef = $def;
                break;
            }
        }

        $this->assertNotNull($bashDef, 'bash should be registered');
        $this->assertSame($this->tool, $bashDef->handler);
    }

    public function testToolDefinitionProperties(): void
    {
        $this->createTool();

        $def = $this->tool->definition();

        $this->assertSame('bash', $def->name);
        $this->assertSame($this->tool, $def->handler);

        // Schema must have 'command' required
        $this->assertContains('command', $def->parametersJsonSchema['required'] ?? []);
        // Schema must NOT have 'run_in_background'
        $this->assertArrayNotHasKey('run_in_background', $def->parametersJsonSchema['properties'] ?? []);
    }

    /* ── Session scoping ── */

    public function testBackgroundProcessIsScopedToSession(): void
    {
        $this->createTool();

        $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->tool)(['command' => 'echo "session test"']);
        });

        $records = $this->manager->list(self::TEST_SESSION);
        $this->assertCount(1, $records);

        $otherRecords = $this->manager->list('other-session');
        $this->assertCount(0, $otherRecords);
    }

    /* ── Helper ── */

    private function createTool(?BashBackgroundPromptAdapterInterface $promptAdapter = null): void
    {
        $this->tool = new BashTool(
            manager: $this->manager,
            contextAccessor: $this->contextAccessor,
            toolRuntime: $this->toolRuntime,
            outputCap: $this->outputCap,
            logger: new NullLogger(),
            config: $this->bashConfig,
            promptAdapter: $promptAdapter ?? new BashToolDeclineAdapter(),
        );
    }

    private function withContext(string $sessionId, callable $callback): mixed
    {
        $cancellationToken = $this->createStub(CancellationTokenInterface::class);
        $cancellationToken->method('isCancellationRequested')->willReturn(false);

        $context = new ToolContext(
            runId: $sessionId,
            turnNo: 1,
            toolCallId: 'tc_bash',
            toolName: 'bash',
            cancellationToken: $cancellationToken,
            timeoutSeconds: 30,
        );

        return $this->contextAccessor->with($context, $callback);
    }

    private function rmDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir((string) $item);
            } else {
                @unlink((string) $item);
            }
        }

        @rmdir($path);
    }
}

/**
 * Test-only prompt adapter that always declines.
 */
final class BashToolDeclineAdapter implements BashBackgroundPromptAdapterInterface
{
    public function shouldBackground(string $command, int $pid, string $logPath, float $elapsedSeconds): bool
    {
        return false;
    }
}
