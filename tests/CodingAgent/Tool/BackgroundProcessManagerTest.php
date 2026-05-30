<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcessManager
 * @covers \Ineersa\CodingAgent\Config\BackgroundProcessConfig
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 *
 * Sleep budget: bg subprocesses use sleep 3 max, usleep 100-200ms.
 * Only 1 test blocks on the 1s grace window (testStopTerminatesWithTerm).
 * Teardown uses direct SIGKILL (.pid files) to avoid manager grace sleeps.
 */
final class BackgroundProcessManagerTest extends TestCase
{
    private const string TEST_SESSION = 'test-session-001';

    private Connection $connection;
    private BackgroundProcessManager $manager;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->tmpDir = sys_get_temp_dir().'/hatfield_bg_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->cleanupProcesses();
        $this->rmDir($this->tmpDir);
    }

    /* ── start() ── */

    public function testStartCreatesProcess(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "hello"', self::TEST_SESSION);

        $this->assertArrayHasKey('id', $result);
        $this->assertGreaterThan(0, $result['pid']);
        // PGID may be null for instant commands that exit before ps resolves it
        if (null !== $result['pgid']) {
            $this->assertGreaterThan(0, $result['pgid']);
            $this->assertSame($result['pid'], $result['pgid']);
        }
        $this->assertSame('running', $result['status']);
        $this->assertStringContainsString(self::TEST_SESSION, $result['session_id']);
        $this->assertFileExists($result['log_path']);

        $this->manager->shutdownCleanup();
    }

    /* ── list() ── */

    public function testListReturnsRunningAndFinished(): void
    {
        $this->createManager();
        $this->manager->start('sleep 3', self::TEST_SESSION); // long-running
        $this->manager->start('echo "done"', self::TEST_SESSION); // quick

        // Wait for the quick echo to finish
        usleep(200_000);

        $processes = $this->manager->list();

        $this->assertCount(2, $processes);

        // Sort by PID so order is predictable (sleep 3 started first = lower PID)
        usort($processes, static fn (array $a, array $b): int => $a['pid'] <=> $b['pid']);

        // First process (lower PID): sleep 3 — still running
        $this->assertStringContainsString('running', $processes[0]['status']);

        // Second process (higher PID): echo done — finished with exit 0
        $this->assertStringContainsString('finish', $processes[1]['status']);
        $this->assertSame(0, $processes[1]['exit_code']);

        $this->manager->shutdownCleanup();
    }

    /* ── readLogTail() ── */

    public function testReadLogTailReturnsContent(): void
    {
        $this->createManager();
        $result = $this->manager->start('printf "line1\nline2\n"', self::TEST_SESSION);

        usleep(150_000);

        $logResult = $this->manager->readLogTail($result['pid']);

        $this->assertSame($result['pid'], $logResult['pid']);
        $this->assertFalse($logResult['truncated']);
        $this->assertStringContainsString('line1', $logResult['content']);
        $this->assertStringContainsString('line2', $logResult['content']);

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

        $stopResult = $this->manager->stop($result['pid']);

        $this->assertTrue($stopResult['stopped_by_user']);
        $this->assertFalse($stopResult['already_finished']);
        $this->assertSame('term', $stopResult['signal_sent']);
        $this->assertFileExists($sentinel);
        $this->assertStringContainsString('term_received', file_get_contents($sentinel));
    }

    public function testStopEscalatesToKill(): void
    {
        // grace=0: TERM is ignored, so the process is definitely alive
        // immediately after TERM => KILL is sent. No blocking wait.
        $this->createManager(stopGraceSeconds: 0);
        $result = $this->manager->start('trap "" TERM; sleep 3', self::TEST_SESSION);

        $stopResult = $this->manager->stop($result['pid']);

        $this->assertTrue($stopResult['stopped_by_user']);
        $this->assertFalse($stopResult['already_finished']);
        $this->assertSame('term+kill', $stopResult['signal_sent']);
    }

    public function testStopOnAlreadyFinishedProcess(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "quick"', self::TEST_SESSION);

        usleep(200_000);

        $stopResult = $this->manager->stop($result['pid']);

        $this->assertTrue($stopResult['already_finished']);
        $this->assertFalse($stopResult['stopped_by_user']);
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
        $this->assertSame(1, $count);

        // Log file should be gone
        $this->assertFileDoesNotExist($result['log_path']);

        // DB record should be gone
        $this->assertCount(0, $this->manager->list());
    }

    /* ── shutdownCleanup() ── */

    public function testShutdownCleanupStopsAllRunning(): void
    {
        $this->createManager(stopGraceSeconds: 0);
        $this->manager->start('sleep 3', self::TEST_SESSION);

        $count = $this->manager->shutdownCleanup();

        $this->assertSame(1, $count);

        foreach ($this->manager->list() as $p) {
            $this->assertNotNull($p['finished_at']);
        }
    }

    public function testShutdownCleanupWithNoRunningProcesses(): void
    {
        $this->createManager();
        $this->assertSame(0, $this->manager->shutdownCleanup());
    }

    /* ── Session scoping ── */

    public function testListFiltersBySession(): void
    {
        $this->createManager();
        $this->manager->start('echo "session-a"', 'session-A');
        $this->manager->start('echo "session-b"', 'session-B');

        $sessionA = $this->manager->list('session-A');
        $this->assertCount(1, $sessionA);
        $this->assertSame('session-A', $sessionA[0]['session_id']);

        $this->assertCount(2, $this->manager->list());

        $this->manager->shutdownCleanup();
    }

    public function testStopAndCleanupRespectSessionScope(): void
    {
        $this->createManager(stopGraceSeconds: 1);
        $resX = $this->manager->start('sleep 3', 'session-X');
        $this->manager->start('sleep 3', 'session-Y');

        // Stop with wrong session should throw
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('for this session');
        $this->manager->stop($resX['pid'], 'session-Y');
    }

    /* ── Helpers ── */

    private function createManager(?string $storageDir = null, int $retentionSeconds = 86400, int $stopGraceSeconds = 1, int $logTailChars = 5000): void
    {
        $config = new BackgroundProcessConfig(
            storageDir: $storageDir ?? $this->tmpDir,
            retentionSeconds: $retentionSeconds,
            stopGraceSeconds: $stopGraceSeconds,
            logTailChars: $logTailChars,
        );
        $this->manager = new BackgroundProcessManager($this->connection, $config);
    }

    private function cleanupProcesses(): void
    {
        if (isset($this->manager)) {
            try {
                $this->manager->shutdownCleanup();
            } catch (\RuntimeException) {
                // Best-effort cleanup
            }
        }

        // Extra safety: kill any orphaned test processes
        $pattern = sys_get_temp_dir().'/hatfield_bg_test_*';
        foreach (glob($pattern) as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS) as $file) {
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
