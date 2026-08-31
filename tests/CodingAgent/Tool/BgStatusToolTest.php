<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use HelgeSverre\Toon\Toon;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tool\Arguments\BgStatusArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BgStatusTool;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\RawAwareToolCallArgumentResolver;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\EventListener\ValidateToolCallArgumentsListener;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

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
            $started = $this->manager->start('echo "bg process"', self::TEST_SESSION);
            $this->manager->markBackgroundedForRecord($started->id, self::TEST_SESSION);
        });

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(new BgStatusArgumentsDTO(action: 'list')));

        $data = Toon::decode($result);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('processes', $data);
        $this->assertCount(1, $data['processes']);
        $this->assertStringContainsString('bg process', $data['processes'][0]['command']);
        $this->assertArrayHasKey('pid', $data['processes'][0]);
        $this->assertArrayHasKey('log_path', $data['processes'][0]);
        $this->assertArrayHasKey('hint', $data);
    }

    public function testListExcludesPrivateForegroundSupervision(): void
    {
        $private = $this->manager->start('echo "private"', self::TEST_SESSION);
        $accepted = $this->manager->start('echo "accepted"', self::TEST_SESSION);
        $this->manager->markBackgroundedForRecord($accepted->id, self::TEST_SESSION);

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(new BgStatusArgumentsDTO(action: 'list')));
        $data = Toon::decode($result);

        $this->assertCount(1, $data['processes']);
        $this->assertSame($accepted->pid, $data['processes'][0]['pid']);
        $this->assertNotSame($private->pid, $data['processes'][0]['pid']);
    }

    public function testListEmpty(): void
    {
        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(new BgStatusArgumentsDTO(action: 'list')));

        $data = Toon::decode($result);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('processes', $data);
        $this->assertEmpty($data['processes']);
        $this->assertArrayHasKey('hint', $data);
    }

    /* ── log action ── */

    public function testLogReturnsContent(): void
    {
        $started = $this->withContext(self::TEST_SESSION, fn () => $this->manager->start('echo "hello from bg"', self::TEST_SESSION));
        $this->manager->markBackgroundedForRecord($started->id, self::TEST_SESSION);

        $this->waitUntilLogContains($started->logPath, 'hello from bg');

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(new BgStatusArgumentsDTO(action: 'log', pid: $started->pid)));

        $this->assertStringContainsString('hello from bg', $result);
        $this->assertStringContainsString('BEGIN LOG', $result);
    }

    public function testLogThrowsOnMissingPid(): void
    {
        // pid is conditionally required by BgStatusArgumentsDTO When constraints;
        // validation rejects before the handler runs.
        $result = $this->validationToolbox()->execute(new ToolCall('call-bg', 'bg_status', ['action' => 'log']));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('The "pid" argument is required and must be a positive integer for the log action.', $message);
    }

    public function testLogThrowsOnUnknownPid(): void
    {
        $this->withContext(self::TEST_SESSION, fn () => $this->manager->start('echo "test"', self::TEST_SESSION));

        // Unknown pid is an execution failure (process lookup), not an input
        // validation error — the handler must still run and fail deterministically.
        $this->expectException(\Throwable::class);
        $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(new BgStatusArgumentsDTO(action: 'log', pid: 999999)));
    }

    /* ── stop action ── */

    public function testStopAction(): void
    {
        $started = $this->withContext(self::TEST_SESSION, fn () => $this->manager->start('sleep 30', self::TEST_SESSION));
        $this->manager->markBackgroundedForRecord($started->id, self::TEST_SESSION);
        // start() persists the row and returns a live PID; stop needs no fixed delay.

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(new BgStatusArgumentsDTO(action: 'stop', pid: $started->pid)));

        $this->assertStringContainsString('PID '.$started->pid, $result);
        $this->assertStringContainsString('stopped', $result);
    }

    public function testStopAlreadyFinished(): void
    {
        $started = $this->withContext(self::TEST_SESSION, fn () => $this->manager->start('echo "quick"', self::TEST_SESSION));
        $this->manager->markBackgroundedForRecord($started->id, self::TEST_SESSION);
        $this->waitUntilFinished($started->pid);

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(new BgStatusArgumentsDTO(action: 'stop', pid: $started->pid)));

        $this->assertStringContainsString('already finished', $result);
    }

    /* ── definition() ── */

    public function testDefinitionReturnsToolDefinition(): void
    {
        $definition = $this->tool->definition();
        $this->assertSame('bg_status', $definition->name);
        // Typed DTO tool: schema is generated natively from BgStatusArgumentsDTO
        // (parametersJsonSchema === null routes through the native factory).
        $this->assertNull($definition->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($this->tool);
        $pid = $schema['properties']['pid'];
        // Assert\Range(min: 1) maps to modern minimum: 1 (provider-compatible).
        $this->assertSame(1, $pid['minimum']);
        $this->assertArrayNotHasKey('exclusiveMinimum', $pid);
    }

    public function testDefinitionUsesParallelExecutionMode(): void
    {
        $definition = $this->tool->definition();
        $this->assertSame(ToolExecutionMode::Parallel, $definition->executionMode);
    }

    /* ── Invalid action ── */

    public function testInvalidActionThrowsException(): void
    {
        // action is Choice-constrained on the DTO; validation rejects before
        // the handler runs.
        $result = $this->validationToolbox()->execute(new ToolCall('call-bg', 'bg_status', ['action' => 'invalid']));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('Invalid action ""invalid"". Use one of: list, log, stop.', $message);
    }

    /* ── Session scoping ── */

    public function testListScopedBySession(): void
    {
        $a = $this->withContext('session-A', fn () => $this->manager->start('echo "A-for-test-B"', 'session-A'));
        $b = $this->withContext('session-B', fn () => $this->manager->start('echo "B-for-test-A"', 'session-B'));
        $this->manager->markBackgroundedForRecord($a->id, 'session-A');
        $this->manager->markBackgroundedForRecord($b->id, 'session-B');

        $resultA = $this->withContext('session-A', fn (): string => ($this->tool)(new BgStatusArgumentsDTO(action: 'list')));
        $resultB = $this->withContext('session-B', fn (): string => ($this->tool)(new BgStatusArgumentsDTO(action: 'list')));

        $dataA = Toon::decode($resultA);
        $dataB = Toon::decode($resultB);

        $commandsA = array_column($dataA['processes'], 'command');
        $commandsB = array_column($dataB['processes'], 'command');

        $this->assertContains('echo "A-for-test-B"', $commandsA);
        $this->assertNotContains('echo "B-for-test-A"', $commandsA);
        $this->assertContains('echo "B-for-test-A"', $commandsB);
        $this->assertNotContains('echo "A-for-test-B"', $commandsB);
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
        $lowCap = $this->outputCap($lowCapCfg);
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
        $this->manager->markBackgroundedForRecord($started->id, self::TEST_SESSION);
        $this->waitUntilLogContains($started->logPath, $sentinel);

        $result = $this->withContext(self::TEST_SESSION, static fn (): string => $lowCapTool(new BgStatusArgumentsDTO(action: 'log', pid: $started->pid)));

        // Tool returns raw output; capping is centralized.
        $this->assertStringNotContainsString('Output capped', $result);
        $this->assertStringContainsString($sentinel, $result, 'Large log must not be silently dropped by the tool');
    }

    /* ── Error: missing action ── */

    public function testMissingActionThrowsException(): void
    {
        $result = $this->validationToolbox()->execute(new ToolCall('call-bg', 'bg_status', []));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('The "action" argument is required and must be a non-empty string.', $message);
    }

    /**
     * Production-shaped execution path for invalid-argument tests (registry →
     * native resolver → ValidateToolCallArgumentsListener → FaultTolerantToolbox).
     * BgStatusArgumentsDTO uses only built-in constraints, so the default
     * listener validator applies.
     */
    private function validationToolbox(): FaultTolerantToolbox
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallArgumentsResolved::class, new ValidateToolCallArgumentsListener());

        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'bg_status',
            description: 'bg_status',
            handler: $this->tool,
            promptLine: 'bg_status',
        );

        return new FaultTolerantToolbox(new RegistryBackedToolbox(
            registry: $registry,
            argumentResolver: new RawAwareToolCallArgumentResolver(new ToolCallArgumentResolver()),
            schemaFactory: NativeToolSchemaProbe::schemaFactory(),
            eventDispatcher: $dispatcher,
        ));
    }

    /* ── Helpers ── */

    private function waitUntilLogContains(string $logPath, string $needle, float $timeoutSeconds = 2.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if (is_file($logPath)) {
                $content = (string) @file_get_contents($logPath);
                if (str_contains($content, $needle)) {
                    return;
                }
            }
            usleep(10_000);
        }

        $this->fail(\sprintf('Timed out waiting for log %s to contain %s', $logPath, $needle));
    }

    private function waitUntilFinished(int $pid, float $timeoutSeconds = 2.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            foreach ($this->manager->list(self::TEST_SESSION) as $entity) {
                if ($entity->pid === $pid && null !== $entity->finishedAt) {
                    return;
                }
            }
            usleep(10_000);
        }

        $this->fail(\sprintf('Timed out waiting for pid %d to finish', $pid));
    }

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

    private function outputCap(OutputCapConfig $config): OutputCap
    {
        return new OutputCap($config, new LockFactory(new FlockStore($this->tmpDir)), new NullLogger());
    }
}
