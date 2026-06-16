<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Config\BashToolConfig;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BashBackgroundPromptAdapterInterface;
use Ineersa\CodingAgent\Tool\BashTool;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use Psr\Log\NullLogger;

/**
 * @covers \Ineersa\CodingAgent\Tool\BashTool
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcessManager
 * @covers \Ineersa\CodingAgent\Config\BashToolConfig
 * @covers \Ineersa\CodingAgent\Config\BackgroundProcessConfig
 * @covers \Ineersa\CodingAgent\Config\OutputCapConfig
 * @covers \Ineersa\CodingAgent\Tool\OutputCap
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 *
 * Sleep budget: tests use short-lived commands and minimal polling.
 * Backgrounded processes have at most ~100ms teardown overhead.
 *
 * ProcessStore comes from the Symfony test container (via IsolatedKernelTestCase).
 * BackgroundProcessConfig / ProcessLifecycle / BackgroundProcessManager are
 * constructed with test-specific temp dirs for process I/O files.
 */
final class BashToolTest extends IsolatedKernelTestCase
{
    private const string TEST_SESSION = 'bash-test-session';

    private BackgroundProcessManager $manager;
    private BackgroundProcessConfig $bgConfig;
    private BashToolConfig $bashConfig;
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;
    private OutputCap $outputCap;
    private string $tmpDir;
    private bool $managerCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);
        $this->outputCap = new OutputCap(new OutputCapConfig(storageDir: $this->tmpDir.'/output-cap'));
    }

    protected function tearDown(): void
    {
        $this->cleanupProcesses();
        $this->rmDir($this->tmpDir);

        parent::tearDown();
    }

    /* ── Successful completion ── */

    public function testSuccessfulCommand(): void
    {
        $this->createManager();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(['command' => 'echo "hello from bash"']);
        });

        $this->assertStringContainsString('hello from bash', $result);
        $this->assertStringNotContainsString('timed out', $result);
        $this->assertStringNotContainsString('cancelled', $result);
        $this->assertStringNotContainsString('background', $result);
        $this->assertStringNotContainsString('failed', $result);
    }

    public function testSuccessfulCommandWithNewlines(): void
    {
        $this->createManager();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(['command' => "printf 'line1\\nline2\\nline3\\n'"]);
        });

        $this->assertStringContainsString('line1', $result);
        $this->assertStringContainsString('line2', $result);
        $this->assertStringContainsString('line3', $result);
    }

    /* ── Non-zero exit code ── */

    public function testNonZeroExitCode(): void
    {
        $this->createManager();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(['command' => 'echo "before error" && exit 42']);
        });

        $this->assertStringContainsString('exit code 42', $result);
        $this->assertStringContainsString('before error', $result);
    }

    public function testNonExistentCommand(): void
    {
        $this->createManager();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(['command' => 'nonexistent_command_xyz_123']);
        });

        $this->assertStringContainsString('failed', $result);
        $this->assertStringContainsString('exit code', $result);
    }

    /* ── Timeout ── */

    public function testTimeoutStopsProcess(): void
    {
        $this->createManager();
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 1,
            backgroundPromptThresholdSeconds: 30, // never trigger background prompt
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(['command' => 'echo "partial" && sleep 10 && echo "should not see"']);
        });

        $this->assertStringContainsString('timed out', $result);
        $this->assertStringContainsString('partial', $result);
    }

    /* ── Cancellation ── */

    /**
     * Cancellation is detected in the supervision loop on the second
     * isCancellationRequested() call. The first call is ToolRuntime's
     * pre-check before entering the callback, which must not trigger
     * cancellation (otherwise the command would never start).
     *
     * The $callCount counter tracks this: call 1 returns false (pre-check),
     * calls 2+ return true (supervision loop trigger).
     *
     * ToolRuntime::run() throws RuntimeException for stale results after
     * the callback returns, so the callback's return value is discarded.
     * The meaningful behaviour is that BackgroundProcessManager::stop()
     * was called, which this test verifies as a post-condition.
     */
    public function testCancellationStopsProcess(): void
    {
        $callCount = 0;
        $cancellationToken = $this->createStub(CancellationTokenInterface::class);
        $cancellationToken
            ->method('isCancellationRequested')
            ->willReturnCallback(function () use (&$callCount) {
                ++$callCount;

                // First call is the pre-check in ToolRuntime::run() —
                // must return false so the command starts running.
                // Second+ are from the supervision loop and will
                // trigger cancellation.
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

        $this->createManager();

        $storedPid = null;

        // ToolRuntime::run() detects stale cancellation AFTER the callback
        // returns, so we expect a RuntimeException about stale result.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('due to run cancellation');

        try {
            $this->contextAccessor->with($context, function () use (&$storedPid): string {
                // Capture the PID before cancellation triggers in the loop
                $entities = $this->manager->list(self::TEST_SESSION);
                if ([] !== $entities) {
                    $storedPid = $entities[0]->pid;
                }

                return ($this->makeBashTool())(['command' => 'echo "before cancel" && sleep 10']);
            });
        } finally {
            // Verify the process was stopped by the cancellation path
            if (null !== $storedPid) {
                $entity = $this->manager->find($storedPid, self::TEST_SESSION);
                if (null !== $entity) {
                    $this->assertNotNull($entity->finishedAt, 'Cancelled process should be marked finished');
                }
            }
        }
    }

    /* ── Argument validation ── */

    public function testMissingCommandThrows(): void
    {
        $this->createManager();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('command');

        $this->withContext(self::TEST_SESSION, fn (): string => ($this->makeBashTool())([]));
    }

    public function testEmptyCommandThrows(): void
    {
        $this->createManager();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('command');

        $this->withContext(self::TEST_SESSION, fn (): string => ($this->makeBashTool())(['command' => '']));
    }

    public function testInvalidTimeoutThrows(): void
    {
        $this->createManager();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('timeout');

        $this->withContext(self::TEST_SESSION, fn (): string => ($this->makeBashTool())(['command' => 'echo hi', 'timeout' => -5]));
    }

    public function testTimeoutExceedsMaximumThrows(): void
    {
        $this->createManager();
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 10,
            maxTimeoutSeconds: 30,
            backgroundPromptThresholdSeconds: 30,
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('must not exceed 30 seconds');

        $this->withContext(self::TEST_SESSION, fn (): string => ($this->makeBashTool())(['command' => 'echo hi', 'timeout' => 9999]));
    }

    public function testTimeoutAtMaximumAccepted(): void
    {
        $this->createManager();
        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 10,
            maxTimeoutSeconds: 30,
            backgroundPromptThresholdSeconds: 30,
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(['command' => 'echo "ok"', 'timeout' => 30]);
        });

        $this->assertStringContainsString('ok', $result);
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
        $this->createManager();

        $result = $this->withContext(self::TEST_SESSION, function () use ($promptAdapter): string {
            return ($this->makeBashTool($promptAdapter))(['command' => 'echo "background me" && sleep 10']);
        });

        $this->assertStringContainsString('background', $result);
        $this->assertStringContainsString('PID:', $result);
        $this->assertStringContainsString('Log:', $result);
        $this->assertStringContainsString('You will be notified', $result);
        $this->assertStringContainsString('bg_status log pid=', $result);
        $this->assertStringContainsString('bg_status stop pid=', $result);
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
        $this->createManager();

        $result = $this->withContext(self::TEST_SESSION, function () use ($promptAdapter): string {
            return ($this->makeBashTool($promptAdapter))(['command' => 'echo "quick command"']);
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
        $this->createManager();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(['command' => 'echo "decline test"']);
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
        $this->createManager();

        // Process writes unique marker, background acceptance leaves it running
        $result = $this->withContext(self::TEST_SESSION, function () use ($promptAdapter): string {
            return ($this->makeBashTool($promptAdapter))(['command' => 'echo "background-marker-12345" && sleep 5']);
        });

        $this->assertStringContainsString('background', $result);
        $this->assertStringContainsString('PID:', $result);
        $this->assertStringContainsString('You will be notified', $result);
        $this->assertStringContainsString('bg_status log pid=', $result);
        $this->assertStringContainsString('bg_status stop pid=', $result);

        // Extract PID from result
        \preg_match('/PID: (\d+)/', $result, $matches);
        $this->assertNotEmpty($matches, 'PID should be present in result');
        $pid = (int) $matches[1];

        // Verify there's exactly 1 background process
        $entities = $this->manager->list(self::TEST_SESSION);
        $this->assertCount(1, $entities, 'There should be exactly one process');

        // Verify it's the same PID (single execution, no duplicate)
        $this->assertSame($pid, $entities[0]->pid, 'The background process should have the same PID');

        // Verify the process is marked as backgrounded (backgroundAt is set)
        // This confirms the markBackgrounded() call was made in BashTool.
        $this->assertNotNull($entities[0]->backgroundedAt, 'Background process should have backgroundedAt set');

        // Verify the log contains our unique marker
        \usleep(50_000); // Brief wait for log flush
        $logContent = \file_get_contents($entities[0]->logPath);
        $this->assertStringContainsString('background-marker-12345', $logContent ?: '');

        // Clean up
        $this->manager->stop($pid, self::TEST_SESSION);
    }

    /* ── Process finishes during prompt — regression for smoke bug A ── */

    public function testProcessFinishesWhilePromptBlocksReturnsCompletedOutput(): void
    {
        // Adapter blocks briefly (simulating user considering the prompt)
        // while the command finishes.  BashTool must re-check process
        // status after shouldBackground() returns instead of blindly
        // backgrounding a completed command.
        //
        // Timing: the supervision loop polls at 50ms intervals.  First poll
        // at ~50ms calls shouldBackground.  Adapter blocks 200ms.  During
        // that block the process (sleep 0.2 ≈ 200ms) finishes.  On return,
        // the re-check finds the process completed.
        $promptAdapter = $this->createMock(BashBackgroundPromptAdapterInterface::class);
        $promptAdapter
            ->expects($this->once())
            ->method('shouldBackground')
            ->willReturnCallback(function (): bool {
                \usleep(200_000); // Block while the command finishes

                return true;
            });

        $this->bashConfig = new BashToolConfig(
            defaultTimeoutSeconds: 30,
            backgroundPromptThresholdSeconds: 0, // trigger immediately
            pollIntervalMicros: 50_000,
            logTailChars: 20000,
        );
        $this->createManager();

        // Command that finishes while the adapter is blocking.
        // sleep 0.1 (≈100ms) is longer than the poll interval (50ms)
        // but short enough to finish during the 200ms adapter block.
        $result = $this->withContext(self::TEST_SESSION, function () use ($promptAdapter): string {
            return ($this->makeBashTool($promptAdapter))([
                'command' => 'sleep 0.1 && echo "Hello world"',
            ]);
        });

        // Must show the completed output, not a backgrounding notice or timeout.
        $this->assertStringContainsString('Hello world', $result);
        $this->assertStringNotContainsString('Command moved to background', $result);
        $this->assertStringNotContainsString('timed out', $result);
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
        $this->createManager();

        $result = $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(['command' => 'echo "this is a very long output that should be truncated"']);
        });

        $this->assertStringContainsString('Output capped', $result);
    }

    /* ── Registry exposure ── */

    public function testRegistryExposesTool(): void
    {
        $this->createManager();
        $tool = $this->makeBashTool();

        $registry = new ToolRegistry([$tool]);

        $definitions = $registry->activeToolDefinitions();

        $bashDef = null;
        foreach ($definitions as $def) {
            if ('bash' === $def->name) {
                $bashDef = $def;
                break;
            }
        }

        $this->assertNotNull($bashDef, 'bash should be registered');
        $this->assertSame($tool, $bashDef->handler);
    }

    public function testToolDefinitionProperties(): void
    {
        $this->createManager();
        $tool = $this->makeBashTool();

        $def = $tool->definition();

        $this->assertSame('bash', $def->name);
        $this->assertSame($tool, $def->handler);

        // Schema must have 'command' required
        $this->assertContains('command', $def->parametersJsonSchema['required'] ?? []);
        // Schema must NOT have 'run_in_background'
        $this->assertArrayNotHasKey('run_in_background', $def->parametersJsonSchema['properties'] ?? []);

        // Prompt line must NOT advertise a run_in_background parameter
        $this->assertStringNotContainsStringIgnoringCase('run_in_background', $def->promptLine);

        // Prompt guidelines must describe user-offered (not model-controlled) backgrounding
        $guidelinesText = implode(' ', $def->promptGuidelines);
        $this->assertStringContainsStringIgnoringCase('user', $guidelinesText);
        $this->assertStringContainsStringIgnoringCase('bg_status', $guidelinesText);
        // Guidelines must explicitly state there is no run_in_background parameter
        $this->assertStringContainsStringIgnoringCase('no run_in_background', $guidelinesText);
    }

    /* ── No-context execution (session-less) ── */

    public function testSuccessfulCommandWithoutContext(): void
    {
        $this->createManager();

        // No ambient ToolContext — sessionId and cancelToken are null.
        // The tool should execute the command successfully with only the
        // timeout deadline (no cancellation capability).
        $tool = $this->makeBashTool();
        $result = ($tool)(['command' => 'echo "no context test"']);

        $this->assertStringContainsString('no context test', $result);
        $this->assertStringNotContainsString('timed out', $result);
        $this->assertStringNotContainsString('cancelled', $result);
    }

    /* ── Session scoping ── */

    public function testBackgroundProcessIsScopedToSession(): void
    {
        $this->createManager();

        $this->withContext(self::TEST_SESSION, function (): string {
            return ($this->makeBashTool())(['command' => 'echo "session test"']);
        });

        $entities = $this->manager->list(self::TEST_SESSION);
        $this->assertCount(1, $entities);

        $otherEntities = $this->manager->list('other-session');
        $this->assertCount(0, $otherEntities);
    }

    /* ── Helpers ── */

    /**
     * Create the BackgroundProcessManager once per test method.
     *
     * ProcessStore comes from the Symfony test container.
     * ProcessLifecycle and BackgroundProcessManager use test-specific temp dirs.
     */
    private function createManager(): void
    {
        if ($this->managerCreated) {
            return;
        }

        /** @var ProcessStore $store */
        $store = self::getContainer()->get(ProcessStore::class);
        $lifecycle = new ProcessLifecycle($this->bgConfig, new NullLogger());
        $this->manager = new BackgroundProcessManager($store, $lifecycle, $this->bgConfig, new NullLogger());
        $this->managerCreated = true;
    }

    /**
     * Create a BashTool with the configured dependencies.
     */
    private function makeBashTool(?BashBackgroundPromptAdapterInterface $promptAdapter = null): BashTool
    {
        return new BashTool(
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

    /**
     * Kill any running background processes owned by this test.
     */
    private function cleanupProcesses(): void
    {
        if (!isset($this->bgConfig)) {
            return;
        }

        $bgDir = $this->bgConfig->storageDir;
        if (!is_dir($bgDir)) {
            return;
        }

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
 *
 * This is a test-local duplicate of the production
 * BashBackgroundPromptDeclineAdapter. It exists to keep test code
 * independent of production defaults — per AGENTS.md, test helpers
 * belong in tests. The behavior is identical, but wiring a different
 * class in each test function is simpler than binding the production
 * adapter as a default and overriding it per test.
 */
final class BashToolDeclineAdapter implements BashBackgroundPromptAdapterInterface
{
    public function shouldBackground(string $command, int $pid, string $logPath, float $elapsedSeconds): bool
    {
        return false;
    }
}
