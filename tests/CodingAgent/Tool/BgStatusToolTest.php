<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BgStatusTool;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\BgStatusTool
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcessManager
 * @covers \Ineersa\CodingAgent\Config\BackgroundProcessConfig
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

    private Connection $connection;
    private BackgroundProcessManager $manager;
    private BackgroundProcessConfig $config;
    private StackToolExecutionContextAccessor $contextAccessor;
    private BgStatusTool $tool;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->tmpDir = sys_get_temp_dir().'/hatfield_bgtool_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);

        $this->config = new BackgroundProcessConfig(
            storageDir: $this->tmpDir,
            stopGraceSeconds: 1,
            logTailChars: 5000,
        );
        $this->manager = new BackgroundProcessManager($this->connection, $this->config);
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
        $started = $this->withContext(self::TEST_SESSION, fn (): array => $this->manager->start('echo "hello from bg"', self::TEST_SESSION));

        usleep(150_000);

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'log', 'pid' => $started['pid']]));

        $this->assertStringContainsString('hello from bg', $result);
        $this->assertStringContainsString('BEGIN LOG', $result);
    }

    public function testLogThrowsOnUnknownPid(): void
    {
        $this->expectException(ToolCallException::class);

        $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'log', 'pid' => 999999]));
    }

    /* ── stop action ── */

    public function testStopAlreadyFinishedProcess(): void
    {
        $started = $this->withContext(self::TEST_SESSION, fn (): array => $this->manager->start('echo "quick"', self::TEST_SESSION));

        usleep(150_000);

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'stop', 'pid' => $started['pid']]));

        $this->assertStringContainsString('had already finished', $result);
    }

    public function testStopThrowsOnUnknownPid(): void
    {
        $this->expectException(ToolCallException::class);

        $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'stop', 'pid' => 999999]));
    }

    /* ── Argument validation ── */

    public function testArgumentValidationThrows(): void
    {
        // Missing action (empty args)
        try {
            $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)([]));
            $this->fail('Expected ToolCallException for missing action');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('action', $e->getMessage());
        }

        // Invalid action value
        try {
            $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'invalid']));
            $this->fail('Expected ToolCallException for invalid action');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('Invalid action', $e->getMessage());
        }
    }

    /* ── Session scoping ── */

    public function testListOnlyShowsCurrentSessionProcesses(): void
    {
        $this->withContext('other-session', function (): void {
            $this->manager->start('echo "other"', 'other-session');
        });

        $result = $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'list']));

        $this->assertStringContainsString('No background processes', $result);
    }

    public function testStopWithProcessFromOtherSessionThrows(): void
    {
        $started = $this->withContext('other-session', fn (): array => $this->manager->start('echo "other"', 'other-session'));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No background process found');

        $this->withContext(self::TEST_SESSION, fn (): string => ($this->tool)(['action' => 'stop', 'pid' => $started['pid']]));
    }

    /* ── Registry exposure ── */

    public function testRegistryExposesTool(): void
    {
        $registry = new ToolRegistry([$this->tool]);

        $definitions = $registry->activeToolDefinitions();

        $bgDef = null;
        foreach ($definitions as $def) {
            if ('bg_status' === $def->name) {
                $bgDef = $def;
                break;
            }
        }

        $this->assertNotNull($bgDef, 'bg_status should be registered');
        $this->assertSame($this->tool, $bgDef->handler);
    }

    /* ── Helper ── */

    private function withContext(string $sessionId, callable $callback): mixed
    {
        $cancellationToken = $this->createStub(CancellationTokenInterface::class);
        $cancellationToken->method('isCancellationRequested')->willReturn(false);

        $context = new ToolContext(
            runId: $sessionId,
            turnNo: 1,
            toolCallId: 'tc_bg_status',
            toolName: 'bg_status',
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
