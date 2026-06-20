<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\LogTailResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StartResult;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StopResult;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Log\NullLogger;

/**
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
final class BackgroundProcessManagerTest extends IsolatedKernelTestCase
{
    private const string TEST_SESSION = 'test-session-001';

    private BackgroundProcessManager $manager;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Temp dir for process output files (log, status, pid files).
        // sys_get_temp_dir() is appropriate here — this is actual OS-level
        // subprocess I/O, not ORM proxy directories.
        $this->tmpDir = sys_get_temp_dir().'/hatfield_bg_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->cleanupProcesses();
        $this->rmDir($this->tmpDir);

        parent::tearDown();
    }

    /* ── start() ── */

    public function testStartCreatesProcess(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "hello"', self::TEST_SESSION);

        self::assertInstanceOf(StartResult::class, $result);
        self::assertGreaterThan(0, $result->id);
        self::assertGreaterThan(0, $result->pid);
        if (null !== $result->pgid) {
            self::assertGreaterThan(0, $result->pgid);
            self::assertSame($result->pid, $result->pgid);
        }
        self::assertSame('running', $result->status);
        self::assertStringContainsString(self::TEST_SESSION, $result->sessionId);
        self::assertFileExists($result->logPath);

        $this->manager->shutdownCleanup();
    }

    /* ── list() ── */

    public function testListReturnsRunningAndFinished(): void
    {
        $this->createManager();
        $this->manager->start('sleep 3', self::TEST_SESSION);
        $this->manager->start('echo "done"', self::TEST_SESSION);

        usleep(200_000);

        $entities = $this->manager->list();

        self::assertCount(2, $entities);

        usort($entities, static fn (BackgroundProcess $a, BackgroundProcess $b): int => $a->pid <=> $b->pid);

        self::assertSame('running', $entities[0]->status->value);
        self::assertSame('finished', $entities[1]->status->value);
        self::assertSame(0, $entities[1]->exitCode);

        $this->manager->shutdownCleanup();
    }

    /* ── readLogTail() ── */

    public function testReadLogTailReturnsContent(): void
    {
        $this->createManager();
        $result = $this->manager->start('printf "line1\nline2\n"', self::TEST_SESSION);

        usleep(150_000);

        $logResult = $this->manager->readLogTail($result->pid);

        self::assertInstanceOf(LogTailResult::class, $logResult);
        self::assertSame($result->pid, $logResult->pid);
        self::assertFalse($logResult->truncated);
        self::assertStringContainsString('line1', $logResult->content);
        self::assertStringContainsString('line2', $logResult->content);

        $this->manager->shutdownCleanup();
    }

    public function testReadLogTailThrowsOnUnknownPid(): void
    {
        $this->createManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No background process found');

        $this->manager->readLogTail(999999);
    }

    /* ── stop() ── */

    public function testStopTerminatesWithTerm(): void
    {
        $this->createManager(stopGraceSeconds: 1);

        $sentinel = $this->tmpDir.'/term_sentinel';
        $scriptPath = $this->tmpDir.'/term_test.sh';
        file_put_contents(
            $scriptPath,
            "#!/bin/bash\ntrap 'echo term_received > ".escapeshellarg($sentinel)."; exit 0' TERM\nsleep 3\n",
        );
        chmod($scriptPath, 0755);

        $result = $this->manager->start($scriptPath, self::TEST_SESSION);
        usleep(100_000);

        $stopResult = $this->manager->stop($result->pid);

        self::assertInstanceOf(StopResult::class, $stopResult);
        self::assertTrue($stopResult->stoppedByUser);
        self::assertFalse($stopResult->alreadyFinished);
        self::assertSame('term', $stopResult->signalSent);
        self::assertFileExists($sentinel);
        self::assertStringContainsString('term_received', file_get_contents($sentinel));
    }

    public function testStopEscalatesToKill(): void
    {
        $this->createManager(stopGraceSeconds: 0);
        $result = $this->manager->start('trap "" TERM; sleep 3', self::TEST_SESSION);

        $stopResult = $this->manager->stop($result->pid);

        self::assertTrue($stopResult->stoppedByUser);
        self::assertFalse($stopResult->alreadyFinished);
        self::assertSame('term+kill', $stopResult->signalSent);
    }

    public function testStopOnAlreadyFinishedProcess(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "quick"', self::TEST_SESSION);

        usleep(200_000);

        $stopResult = $this->manager->stop($result->pid);

        self::assertTrue($stopResult->alreadyFinished);
        self::assertFalse($stopResult->stoppedByUser);
    }

    public function testStopThrowsOnUnknownPid(): void
    {
        $this->createManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No background process found');

        $this->manager->stop(999999);
    }

    /* ── cleanupStale() ── */

    public function testCleanupStaleRemovesFinishedProcesses(): void
    {
        $this->createManager(retentionSeconds: 0);
        $result = $this->manager->start('echo "stale"', self::TEST_SESSION);

        usleep(200_000);

        $count = $this->manager->cleanupStale();
        self::assertSame(1, $count);

        self::assertFileDoesNotExist($result->logPath);
        self::assertCount(0, $this->manager->list());
    }

    /* ── shutdownCleanup() ── */

    public function testShutdownCleanupStopsAllRunning(): void
    {
        $this->createManager(stopGraceSeconds: 0);
        $this->manager->start('sleep 3', self::TEST_SESSION);

        $count = $this->manager->shutdownCleanup();

        self::assertSame(1, $count);

        foreach ($this->manager->list() as $p) {
            self::assertNotNull($p->finishedAt);
        }
    }

    public function testShutdownCleanupWithNoRunningProcesses(): void
    {
        $this->createManager();
        self::assertSame(0, $this->manager->shutdownCleanup());
    }

    /* ── Session scoping ── */

    public function testListFiltersBySession(): void
    {
        $this->createManager();
        $this->manager->start('echo "session-a"', 'session-A');
        $this->manager->start('echo "session-b"', 'session-B');

        usleep(200_000);

        $sessionA = $this->manager->list('session-A');
        self::assertCount(1, $sessionA);
        self::assertSame('session-A', $sessionA[0]->sessionId);

        self::assertCount(2, $this->manager->list());

        $this->manager->shutdownCleanup();
    }

    public function testStopAndCleanupRespectSessionScope(): void
    {
        $this->createManager(stopGraceSeconds: 1);
        $resX = $this->manager->start('sleep 3', 'session-X');
        $this->manager->start('sleep 3', 'session-Y');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('for this session');
        $this->manager->stop($resX->pid, 'session-Y');
    }

    /* ── Helpers ── */

    /**
     * Create a BackgroundProcessManager using the container's Doctrine
     * EntityManager and BackgroundProcessRepository (shared, real schema),
     * but with a test-specific BackgroundProcessConfig that points storageDir
     * to a temporary directory for subprocess output files.
     */
    private function createManager(?string $storageDir = null, int $retentionSeconds = 86400, int $stopGraceSeconds = 1, int $logTailChars = 5000): void
    {
        $config = new BackgroundProcessConfig(
            storageDir: $storageDir ?? $this->tmpDir,
            retentionSeconds: $retentionSeconds,
            stopGraceSeconds: $stopGraceSeconds,
            logTailChars: $logTailChars,
        );

        // ProcessStore uses the container's EntityManager — no manual ORM setup.
        $store = static::getContainer()->get(ProcessStore::class);
        $lifecycle = new ProcessLifecycle($config, new NullLogger());
        $this->manager = new BackgroundProcessManager($store, $lifecycle, $config, new NullLogger());
    }

    private function cleanupProcesses(): void
    {
        if (isset($this->manager)) {
            try {
                $this->manager->shutdownCleanup();
            } catch (\RuntimeException) {
                // ignore cleanup errors in teardown
            }
        }
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
