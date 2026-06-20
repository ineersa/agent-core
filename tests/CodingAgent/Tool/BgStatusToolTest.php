<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BgStatusTool;
use Ineersa\CodingAgent\Tool\OutputCap;
use Psr\Log\NullLogger;

/**
 * @covers \Ineersa\CodingAgent\Tool\BgStatusTool
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcessManager
 * @covers \Ineersa\CodingAgent\Config\BackgroundProcessConfig
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 *
 * DB is provided by the Symfony test container (IsolatedKernelTestCase).
 * ProcessStore and BackgroundProcessRepository come from the container.
 * Only BackgroundProcessConfig / ProcessLifecycle / BackgroundProcessManager
 * are constructed with test-specific temp dirs — no manual EntityManager setup.
 */
final class BgStatusToolTest extends IsolatedKernelTestCase
{
    private const string TEST_SESSION = 'test-session-001';

    private BackgroundProcessManager $manager;
    private BackgroundProcessConfig $config;
    private StackToolExecutionContextAccessor $contextAccessor;
    private BgStatusTool $tool;
    private string $tmpDir;
    private OutputCapConfig $outputCapCfg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_bgtool_test', 0o750);

        // Use a high default cap so existing tests are unaffected.
        $this->outputCapCfg = new OutputCapConfig(
            storageDir: $this->tmpDir.'/output-cap',
            defaultCap: 20000,
            docCap: 50000,
        );

        $this->config = new BackgroundProcessConfig(
            storageDir: $this->tmpDir,
            stopGraceSeconds: 1,
            logTailChars: 5000,
        );

        // ProcessStore comes from the container (real Doctrine schema, no manual ORM).
        $store = static::getContainer()->get(ProcessStore::class);
        $lifecycle = new ProcessLifecycle($this->config, new NullLogger());
        $this->manager = new BackgroundProcessManager($store, $lifecycle, $this->config, new NullLogger());
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->tool = new BgStatusTool(
            $this->manager,
            $this->config,
            $this->contextAccessor,
        );
    }

    protected function tearDown(): void
    {
        // Direct SIGKILL via .pid files to avoid 1s grace sleep in shutdownCleanup
        if (isset($this->manager)) {
            $bgDir = $this->config->storageDir;
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

        TestDirectoryIsolation::removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    /* ── list action ── */

    public function testListReturnsProcesses(): void
    {
        $this->withContext(self::TEST_SESSION, function (): void {
            $this->manager->start('echo "bg process"', self::TEST_SESSION);
        });

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'list']));

        $data = json_decode($result, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('processes', $data);
        self::assertCount(1, $data['processes']);
        self::assertStringContainsString('bg process', $data['processes'][0]['command']);
        self::assertArrayHasKey('pid', $data['processes'][0]);
        self::assertArrayHasKey('log_path', $data['processes'][0]);
        self::assertArrayHasKey('hint', $data);
    }

    public function testListEmpty(): void
    {
        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'list']));

        $data = json_decode($result, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey('processes', $data);
        self::assertEmpty($data['processes']);
        self::assertArrayHasKey('hint', $data);
    }

    /* ── log action ── */

    public function testLogReturnsContent(): void
    {
        $started = $this->withContext(self::TEST_SESSION, fn () => $this->manager->start('echo "hello from bg"', self::TEST_SESSION));

        usleep(150_000);

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'log', 'pid' => $started->pid]));

        self::assertStringContainsString('hello from bg', $result);
        self::assertStringContainsString('BEGIN LOG', $result);
    }

    public function testLogThrowsOnMissingPid(): void
    {
        $this->expectException(\Throwable::class);
        $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'log']));
    }

    public function testLogThrowsOnUnknownPid(): void
    {
        $this->withContext(self::TEST_SESSION, fn () => $this->manager->start('echo "test"', self::TEST_SESSION));

        $this->expectException(\Throwable::class);
        $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'log', 'pid' => 999999]));
    }

    /* ── stop action ── */

    public function testStopAction(): void
    {
        $started = $this->withContext(self::TEST_SESSION, fn () => $this->manager->start('sleep 30', self::TEST_SESSION));
        usleep(100_000);

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'stop', 'pid' => $started->pid]));

        self::assertStringContainsString('PID '.$started->pid, $result);
        self::assertStringContainsString('stopped', $result);
    }

    public function testStopAlreadyFinished(): void
    {
        $started = $this->withContext(self::TEST_SESSION, fn () => $this->manager->start('echo "quick"', self::TEST_SESSION));
        usleep(200_000);

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'stop', 'pid' => $started->pid]));

        self::assertStringContainsString('already finished', $result);
    }

    /* ── definition() ── */

    public function testDefinitionReturnsToolDefinition(): void
    {
        $definition = $this->tool->definition();
        self::assertSame('bg_status', $definition->name);
    }

    /* ── Invalid action ── */

    public function testInvalidActionThrowsException(): void
    {
        $this->expectException(\Throwable::class);
        ($this->tool)(['action' => 'invalid']);
    }

    /* ── Session scoping ── */

    public function testListScopedBySession(): void
    {
        $this->withContext('session-A', fn () => $this->manager->start('echo "A-for-test-B"', 'session-A'));
        $this->withContext('session-B', fn () => $this->manager->start('echo "B-for-test-A"', 'session-B'));

        $resultA = $this->withContext('session-A', fn (): string => ($this->tool)(['action' => 'list']));
        $resultB = $this->withContext('session-B', fn (): string => ($this->tool)(['action' => 'list']));

        $dataA = json_decode($resultA, true, 512, \JSON_THROW_ON_ERROR);
        $dataB = json_decode($resultB, true, 512, \JSON_THROW_ON_ERROR);

        $commandsA = array_column($dataA['processes'], 'command');
        $commandsB = array_column($dataB['processes'], 'command');

        self::assertContains('echo "A-for-test-B"', $commandsA);
        self::assertNotContains('echo "B-for-test-A"', $commandsA);
        self::assertContains('echo "B-for-test-A"', $commandsB);
        self::assertNotContains('echo "A-for-test-B"', $commandsB);
    }

    /* ── log cap regression ── */

    public function testLogCapCapsLargeOutput(): void
    {
        // Output capping is now handled centrally by OutputCapToolResultProcessor.
        // This test verifies BgStatusTool returns raw output without embedding
        // any cap notice in the result string.
        $lowCapCfg = new OutputCapConfig(
            storageDir: $this->tmpDir.'/output-cap-low',
            defaultCap: 200,
            docCap: 200,
        );
        $lowCap = new OutputCap($lowCapCfg);
        $lowCapTool = new BgStatusTool(
            $this->manager,
            $this->config,
            $this->contextAccessor,
        );

        $sentinel = 'CAP_SHOULD_HIDE_'.bin2hex(random_bytes(8));

        // Generate output that exceeds the cap. The sentinel must appear
        // after the cap threshold.
        $padding = str_repeat('x', 170);
        $command = 'printf \''.$padding.'\n'.$sentinel.'\n\'';

        $started = $this->withContext(self::TEST_SESSION, fn () => $this->manager->start($command, self::TEST_SESSION));
        usleep(100_000);

        $result = $this->withContext(self::TEST_SESSION, static fn (): string => $lowCapTool(['action' => 'log', 'pid' => $started->pid]));

        // Tool returns raw output; capping is centralized.
        self::assertStringNotContainsString('Output capped', $result);
        self::assertStringContainsString($sentinel, $result, 'Large log must not be silently dropped by the tool');
    }

    /* ── Error: missing action ── */

    public function testMissingActionThrowsException(): void
    {
        $this->expectException(\Throwable::class);
        ($this->tool)([]);
    }

    /* ── Helpers ── */

    /**
     * Execute a callback with a specific session context pushed onto the context stack.
     *
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withContext(string $sessionId, callable $callback): mixed
    {
        $cancellationToken = new \Ineersa\AgentCore\Contract\Hook\NullCancellationToken();
        $toolContext = new \Ineersa\AgentCore\Application\Tool\ToolContext(
            runId: $sessionId,
            turnNo: 0,
            toolCallId: 'test',
            toolName: 'bg_status_test',
            cancellationToken: $cancellationToken,
            timeoutSeconds: 30,
        );

        return $this->contextAccessor->with($toolContext, $callback);
    }
}
