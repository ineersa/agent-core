<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BgStatusTool;
use PHPUnit\Framework\TestCase;
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
 * Sleep budget: no tests use grace-blocking stop().
 * Teardown uses direct SIGKILL via .pid files.
 */
final class BgStatusToolTest extends TestCase
{
    private const string TEST_SESSION = 'test-session-001';

    private EntityManager $entityManager;
    private BackgroundProcessManager $manager;
    private BackgroundProcessConfig $config;
    private StackToolExecutionContextAccessor $contextAccessor;
    private BgStatusTool $tool;
    private string $tmpDir;

    protected function setUp(): void
    {
        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $ormConfig = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__.'/../../../src/CodingAgent/Entity'],
            isDevMode: true,
            proxyDir: sys_get_temp_dir(),
        );

        // Enable PHP 8.4+ native lazy objects to avoid symfony/var-exporter dependency
        $ormConfig->enableNativeLazyObjects(true);

        $this->entityManager = new EntityManager($connection, $ormConfig);

        // Create schema from entity metadata
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());

        $this->tmpDir = sys_get_temp_dir().'/hatfield_bgtool_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);

        $this->config = new BackgroundProcessConfig(
            storageDir: $this->tmpDir,
            stopGraceSeconds: 1,
            logTailChars: 5000,
        );
        $repository = new \Ineersa\CodingAgent\Entity\BackgroundProcessRepository($this->entityManager);
        $store = new ProcessStore($this->entityManager, $repository, new NullLogger());
        $lifecycle = new ProcessLifecycle($this->config, new NullLogger());
        $this->manager = new BackgroundProcessManager($store, $lifecycle, $this->config, new NullLogger());
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->tool = new BgStatusTool($this->manager, $this->config, $this->contextAccessor);
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

        $this->rmDir($this->tmpDir);
    }

    /* ── list action ── */

    public function testListReturnsProcesses(): void
    {
        $this->withContext(self::TEST_SESSION, function (): void {
            $this->manager->start('echo "bg process"', self::TEST_SESSION);
        });

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'list']));

        $this->assertStringContainsString('bg process', $result);
        $this->assertStringContainsString('Total: 1', $result);
    }

    public function testListEmpty(): void
    {
        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'list']));

        $this->assertStringContainsString('No background processes', $result);
    }

    /* ── log action ── */

    public function testLogReturnsContent(): void
    {
        $started = $this->withContext(self::TEST_SESSION, fn () => $this->manager->start('echo "hello from bg"', self::TEST_SESSION));

        usleep(150_000);

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'log', 'pid' => $started->pid]));

        $this->assertStringContainsString('hello from bg', $result);
        $this->assertStringContainsString('BEGIN LOG', $result);
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

        $this->assertStringContainsString('PID '.$started->pid, $result);
        $this->assertStringContainsString('stopped', $result);
    }

    public function testStopAlreadyFinished(): void
    {
        $started = $this->withContext(self::TEST_SESSION, fn () => $this->manager->start('echo "quick"', self::TEST_SESSION));
        usleep(200_000);

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'stop', 'pid' => $started->pid]));

        $this->assertStringContainsString('already finished', $result);
    }

    /* ── definition() ── */

    public function testDefinitionReturnsToolDefinition(): void
    {
        $definition = $this->tool->definition();
        $this->assertSame('bg_status', $definition->name);
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

        // Session-A should see its own process command but not session-B's
        $this->assertStringContainsString('A-for-test-B', $resultA);
        $this->assertStringNotContainsString('B-for-test-A', $resultA);

        // Session-B should see its own process command but not session-A's
        $this->assertStringContainsString('B-for-test-A', $resultB);
        $this->assertStringNotContainsString('A-for-test-B', $resultB);
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

    private function rmDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir((string) $file);
            } else {
                unlink((string) $file);
            }
        }
        rmdir($dir);
    }
}
