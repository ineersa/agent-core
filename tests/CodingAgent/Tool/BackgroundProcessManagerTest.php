<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
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
        // subprocess I/O, not ORM proxy directories.
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_bg_test');
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

        $this->assertInstanceOf(StartResult::class, $result);
        $this->assertGreaterThan(0, $result->id);
        $this->assertGreaterThan(0, $result->pid);
        $this->assertFileExists($result->logPath);

        $this->manager->shutdownCleanup();
    }

    /* ── list() ── */

    public function testListReturnsAcceptedRunningAndFinished(): void
    {
        $this->createManager();
        $running = $this->manager->start('sleep 3', self::TEST_SESSION);
        $finished = $this->manager->start('echo "done"', self::TEST_SESSION);
        $this->manager->markBackgroundedForRecord($running->id, self::TEST_SESSION);
        $this->manager->markBackgroundedForRecord($finished->id, self::TEST_SESSION);

        $this->waitUntilFinished($finished->pid);

        $entities = $this->manager->list(self::TEST_SESSION);

        $this->assertCount(2, $entities);

        $byPid = [];
        foreach ($entities as $entity) {
            $byPid[$entity->pid] = $entity;
        }

        $this->assertSame('running', $byPid[$running->pid]->status->value);
        $this->assertSame('finished', $byPid[$finished->pid]->status->value);
        $this->assertSame(0, $byPid[$finished->pid]->exitCode);

        $this->manager->shutdownCleanup();
    }

    public function testListExcludesPrivateForegroundRows(): void
    {
        $this->createManager();
        $private = $this->manager->start('echo "private"', self::TEST_SESSION);
        $accepted = $this->manager->start('echo "accepted"', self::TEST_SESSION);
        $foreign = $this->manager->start('echo "foreign"', 'other-session');
        $this->manager->markBackgroundedForRecord($accepted->id, self::TEST_SESSION);
        $this->manager->markBackgroundedForRecord($foreign->id, 'other-session');

        $entities = $this->manager->list(self::TEST_SESSION);

        $this->assertCount(1, $entities);
        $this->assertSame($accepted->id, $entities[0]->id);
        $this->assertNotSame($private->id, $entities[0]->id);

        $this->manager->shutdownCleanup();
    }

    public function testPidOperationsRequireAcceptedRowInOwningSession(): void
    {
        $this->createManager();
        $private = $this->manager->start('echo "private"', self::TEST_SESSION);
        $foreign = $this->manager->start('echo "foreign"', 'other-session');
        $this->manager->markBackgroundedForRecord($foreign->id, 'other-session');

        try {
            $this->manager->readLogTail(self::TEST_SESSION, $private->pid);
            $this->fail('Private foreground supervision must be invisible to PID log lookup.');
        } catch (\RuntimeException $e) {
            $this->assertSame(\sprintf('No background process found with PID %d for this session.', $private->pid), $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(\sprintf('No background process found with PID %d for this session.', $foreign->pid));
        $this->manager->stop($foreign->pid, self::TEST_SESSION);
    }

    /* ── readLogTail() ── */

    public function testReadLogTailReturnsContent(): void
    {
        $this->createManager();
        $result = $this->manager->start('printf "line1\nline2\n"', self::TEST_SESSION);

        $this->waitUntilLogContains($result->logPath, 'line2');

        $this->manager->markBackgroundedForRecord($result->id, self::TEST_SESSION);
        $logResult = $this->manager->readLogTail(self::TEST_SESSION, $result->pid);

        $this->assertInstanceOf(LogTailResult::class, $logResult);
        $this->assertFalse($logResult->truncated);
        $this->assertStringContainsString('line1', $logResult->content);
        $this->assertStringContainsString('line2', $logResult->content);

        $this->manager->shutdownCleanup();
    }

    public function testReadLogTailThrowsOnUnknownPid(): void
    {
        $this->createManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No background process found');

        $this->manager->readLogTail(self::TEST_SESSION, 999999);
    }

    /* ── stop() ── */

    public function testStopTerminatesWithTerm(): void
    {
        // Large grace proves stop returns as soon as the process dies after TERM
        // instead of blocking the full unconditional sleep (pre-fix path).
        $this->createManager(stopGraceSeconds: 5);

        $sentinel = $this->tmpDir.'/term_sentinel';
        $readySentinel = $this->tmpDir.'/term_ready';
        $scriptPath = $this->tmpDir.'/term_test.sh';
        file_put_contents(
            $scriptPath,
            "#!/bin/bash\ntrap 'echo term_received > ".escapeshellarg($sentinel)."; exit 0' TERM\necho ready > ".escapeshellarg($readySentinel)."\nsleep 3\n",
        );
        chmod($scriptPath, 0755);

        $result = $this->manager->start($scriptPath, self::TEST_SESSION);

        // Bounded readiness: fixture writes after TERM trap is installed.
        $readyDeadlineNs = hrtime(true) + 2_000_000_000;
        while (!is_file($readySentinel) && hrtime(true) < $readyDeadlineNs) {
            usleep(10_000);
        }
        $this->assertFileExists($readySentinel, 'TERM trap fixture must signal ready before stop()');

        $startedNs = hrtime(true);
        $stopResult = $this->manager->stop($result->pid);
        $elapsedSeconds = (hrtime(true) - $startedNs) / 1_000_000_000;

        $this->assertInstanceOf(StopResult::class, $stopResult);
        $this->assertFalse($stopResult->alreadyFinished);
        $this->assertSame('term', $stopResult->signalSent);
        $this->assertFileExists($sentinel);
        $this->assertStringContainsString('term_received', file_get_contents($sentinel));
        // Cooperative exit must not burn the 5s grace; 1.5s leaves headroom under load.
        $this->assertLessThan(1.5, $elapsedSeconds, 'stop() must return once TERM is honored, not after full grace');
    }

    public function testStopEscalatesToKill(): void
    {
        $this->createManager(stopGraceSeconds: 0);
        $result = $this->manager->start('trap "" TERM; sleep 3', self::TEST_SESSION);

        $stopResult = $this->manager->stop($result->pid);

        $this->assertFalse($stopResult->alreadyFinished);
        $this->assertSame('term+kill', $stopResult->signalSent);
    }

    public function testStopOnAlreadyFinishedProcess(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "quick"', self::TEST_SESSION);

        $this->waitUntilFinished($result->pid);

        $stopResult = $this->manager->stop($result->pid);

        $this->assertTrue($stopResult->alreadyFinished);
    }

    public function testStopThrowsOnUnknownPid(): void
    {
        $this->createManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No background process found');

        $this->manager->stop(999999);
    }

    /* ── shutdownCleanup() ── */

    public function testShutdownCleanupStopsAllRunning(): void
    {
        $this->createManager(stopGraceSeconds: 0);
        $result = $this->manager->start('sleep 3', self::TEST_SESSION);

        $count = $this->manager->shutdownCleanup();

        $this->assertSame(1, $count);

        $entity = $this->manager->findByRecordId($result->id);
        $this->assertNotNull($entity);
        $this->assertNotNull($entity->finishedAt);
    }

    public function testShutdownCleanupWithNoRunningProcesses(): void
    {
        $this->createManager();
        $this->assertSame(0, $this->manager->shutdownCleanup());
    }

    public function testShutdownCleanupDoesNotReapProcessesStartedByAnotherInstance(): void
    {
        $this->createManager(stopGraceSeconds: 0);
        $result = $this->manager->start('sleep 30', self::TEST_SESSION);
        $pid = $result->pid;
        // start() already persists ownership; no fixed delay before other-instance cleanup.

        $otherManager = $this->createOtherManager(stopGraceSeconds: 0);
        $this->assertSame(0, $otherManager->shutdownCleanup());

        $entity = $this->manager->findByRecordId($result->id);
        $this->assertNotNull($entity);
        $this->assertNull($entity->finishedAt);

        $this->assertSame(1, $this->manager->shutdownCleanup());
    }

    /* ── Session scoping ── */

    public function testListFiltersBySession(): void
    {
        $this->createManager();
        $a = $this->manager->start('echo "session-a"', 'session-A');
        $b = $this->manager->start('echo "session-b"', 'session-B');
        $this->manager->markBackgroundedForRecord($a->id, 'session-A');
        $this->manager->markBackgroundedForRecord($b->id, 'session-B');
        // Session scoping is on persisted rows; wait only for both rows to finish so list() is stable.
        $this->waitUntilFinished($a->pid);
        $this->waitUntilFinished($b->pid);

        $sessionA = $this->manager->list('session-A');
        $this->assertCount(1, $sessionA);
        $this->assertSame('session-A', $sessionA[0]->sessionId);

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

    /* ── Store-level record ID and existence lookups ── */

    public function testFindByRecordIdReturnsProcess(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "record-id-test"', self::TEST_SESSION);
        // start() persists the record id synchronously.

        $entity = $this->manager->findByRecordId($result->id);
        $this->assertNotNull($entity);
        $this->assertSame($result->pid, $entity->pid);
        $this->assertSame($result->id, $entity->id);

        $this->manager->shutdownCleanup();
    }

    public function testFindByRecordIdRespectsSessionScope(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "session-scope"', 'session-A');
        // start() persists session scope synchronously.

        // Same session → found
        $entity = $this->manager->findByRecordId($result->id, 'session-A');
        $this->assertNotNull($entity);
        $this->assertSame($result->id, $entity->id);

        // Different session → null
        $otherSession = $this->manager->findByRecordId($result->id, 'session-B');
        $this->assertNull($otherSession);

        // Null session → found (unscoped)
        $unscoped = $this->manager->findByRecordId($result->id);
        $this->assertNotNull($unscoped);

        $this->manager->shutdownCleanup();
    }

    public function testFindByRecordIdReturnsNullForNonexistentId(): void
    {
        $this->createManager();

        $entity = $this->manager->findByRecordId(9999999);
        $this->assertNull($entity);
    }

    public function testExistsByPidReturnsTrueForExistingProcess(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "exists-test"', self::TEST_SESSION);
        // start() persists the pid row synchronously.

        $this->assertTrue($this->manager->existsByPid($result->pid));

        $this->manager->shutdownCleanup();
    }

    public function testExistsByPidReturnsFalseForNonexistentPid(): void
    {
        $this->createManager();

        $this->assertFalse($this->manager->existsByPid(9999999));
    }

    public function testExistsByRecordIdReturnsTrueForExistingProcess(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "exists-record-id"', self::TEST_SESSION);
        // start() persists the record id synchronously.

        $this->assertTrue($this->manager->existsByRecordId($result->id));

        $this->manager->shutdownCleanup();
    }

    public function testExistsByRecordIdReturnsFalseForNonexistentId(): void
    {
        $this->createManager();

        $this->assertFalse($this->manager->existsByRecordId(9999999));
    }

    /* ── Helpers ── */

    private function waitUntilFinished(int $pid, float $timeoutSeconds = 2.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $entity = $this->manager->find($pid);
            if (null !== $entity && null !== $entity->finishedAt) {
                return;
            }
            usleep(10_000);
        }

        $this->fail(\sprintf('Timed out waiting for pid %d to finish', $pid));
    }

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

    /**
     * Create a BackgroundProcessManager using the container's Doctrine
     * EntityManager and BackgroundProcessRepository (shared, real schema),
     * but with a test-specific BackgroundProcessConfig that points storageDir
     * to a temporary directory for subprocess output files.
     */
    private function createManager(?string $storageDir = null, int $stopGraceSeconds = 1, int $logTailChars = 5000): void
    {
        $config = new BackgroundProcessConfig(
            storageDir: $storageDir ?? $this->tmpDir,
            stopGraceSeconds: $stopGraceSeconds,
            logTailChars: $logTailChars,
        );

        // ProcessStore uses the container's EntityManager — no manual ORM setup.
        $store = static::getContainer()->get(ProcessStore::class);
        $lifecycle = new ProcessLifecycle($config, new NullLogger());
        $this->manager = new BackgroundProcessManager($store, $lifecycle, $config, new NullLogger());
    }

    private function createOtherManager(int $stopGraceSeconds = 1): BackgroundProcessManager
    {
        $config = new BackgroundProcessConfig(
            storageDir: $this->tmpDir,
            stopGraceSeconds: $stopGraceSeconds,
            logTailChars: 5000,
        );

        $store = static::getContainer()->get(ProcessStore::class);
        $lifecycle = new ProcessLifecycle($config, new NullLogger());

        return new BackgroundProcessManager($store, $lifecycle, $config, new NullLogger());
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
