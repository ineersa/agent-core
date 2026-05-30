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
 */
final class BackgroundProcessManagerTest extends TestCase
{
    private Connection $connection;
    private BackgroundProcessManager $manager;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);

        $this->tmpDir = sys_get_temp_dir().'/hatfield_bg_test_'.\bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);
    }

    protected function tearDown(): void
    {
        // Clean up any running processes
        $this->cleanupProcesses();

        $this->rmDir($this->tmpDir);
    }

    private function createManager(string $storageDir = null, int $retentionSeconds = 86400, int $stopGraceSeconds = 1, int $logTailChars = 5000): void
    {
        $config = new BackgroundProcessConfig(
            storageDir: $storageDir ?? $this->tmpDir,
            retentionSeconds: $retentionSeconds,
            stopGraceSeconds: $stopGraceSeconds,
            logTailChars: $logTailChars,
        );
        $this->manager = new BackgroundProcessManager($this->connection, $config);
    }

    /* ── start() tests ── */

    public function testStartCreatesRecordAndLogs(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "hello from bg"');

        self::assertArrayHasKey('id', $result);
        self::assertArrayHasKey('pid', $result);
        self::assertGreaterThan(0, $result['pid']);
        self::assertArrayHasKey('pgid', $result);
        self::assertArrayHasKey('log_path', $result);
        self::assertArrayHasKey('started_at', $result);
        self::assertSame('running', $result['status']);

        // PID should be a real process (or recently exited)
        self::assertFileExists($result['log_path']);
    }

    public function testStartCommandWithProcessGroup(): void
    {
        $this->createManager();
        // Use a longer-running command so the process is still alive
        // when we query PGID via ps.
        $result = $this->manager->start('sleep 5');

        // PGID should be set when setsid is available
        self::assertNotNull($result['pgid']);
        self::assertGreaterThan(0, $result['pgid']);

        // Process group leader should be the PID itself
        self::assertSame($result['pid'], $result['pgid']);

        $this->manager->shutdownCleanup();
    }

    public function testStartWithCustomStorageDir(): void
    {
        $customDir = $this->tmpDir.'/custom_bg';
        $this->createManager(storageDir: $customDir);

        $result = $this->manager->start('echo "custom dir"');

        self::assertStringContainsString('custom_bg', $result['log_path']);
        self::assertFileExists($result['log_path']);

        // Clean up
        $this->manager->shutdownCleanup();
    }

    public function testStartCreatesLogFromCommand(): void
    {
        $this->createManager();
        $result = $this->manager->start('printf "log line 1\nlog line 2\n"');

        // Wait for short process to finish
        \usleep(500000);

        $this->assertLogEventuallyContains($result['log_path'], 'log line 1');
        $this->assertLogEventuallyContains($result['log_path'], 'log line 2');

        $this->manager->shutdownCleanup();
    }

    /* ── list() tests ── */

    public function testListReturnsEmptyWhenNoProcesses(): void
    {
        $this->createManager();
        $processes = $this->manager->list();

        self::assertIsArray($processes);
        self::assertCount(0, $processes);
    }

    public function testListReturnsStartedProcess(): void
    {
        $this->createManager();
        // Use a command that runs long enough for list to catch it running
        $this->manager->start('sleep 5');

        $processes = $this->manager->list();

        self::assertCount(1, $processes);
        self::assertStringContainsString('sleep', $processes[0]['command']);
        self::assertStringContainsString('running', $processes[0]['status']);

        $this->manager->shutdownCleanup();
    }

    public function testListReturnsMultipleProcesses(): void
    {
        $this->createManager();
        $this->manager->start('echo "proc 1"');
        $this->manager->start('echo "proc 2"');

        $processes = $this->manager->list();

        self::assertCount(2, $processes);
    }

    public function testListShowsFinishedProcessStatus(): void
    {
        $this->createManager();
        $this->manager->start('echo "finish test"');

        // Wait for the short process to finish
        \usleep(500000);

        $processes = $this->manager->list();

        self::assertCount(1, $processes);
        // Status should transition from running to finished
        self::assertStringContainsString('finish', $processes[0]['status']);
        // Exit code should be 0 for echo
        self::assertSame(0, $processes[0]['exit_code']);
    }

    /* ── readLogTail() tests ── */

    public function testReadLogTailReturnsContent(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "tail test content"');

        // Wait for process to write
        \usleep(500000);

        $logResult = $this->manager->readLogTail($result['pid']);

        self::assertSame($result['pid'], $logResult['pid']);
        self::assertFalse($logResult['truncated']);
        self::assertStringContainsString('tail test content', $logResult['content']);

        $this->manager->shutdownCleanup();
    }

    public function testReadLogTailTruncatesLargeOutput(): void
    {
        $this->createManager(logTailChars: 50);
        // Generate large output with repeated text
        $result = $this->manager->start('for i in $(seq 1 100); do echo "line $i with some padding text to make it longer"; done');

        // Wait for process to finish and write
        \usleep(500000);

        $logResult = $this->manager->readLogTail($result['pid'], 50); // match logTailChars: 50

        // With 50 char cap and large output, truncation should happen
        if ($logResult['total_bytes'] > 50) {
            self::assertTrue($logResult['truncated'],
                'Expected truncated=true for ' . $logResult['total_bytes'] . ' byte log with 50 char cap');
        }

        $this->manager->shutdownCleanup();
    }

    public function testReadLogTailThrowsOnUnknownProcess(): void
    {
        $this->createManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No background process found');

        $this->manager->readLogTail(999999);
    }

    /* ── stop() tests ── */

    public function testStopTerminatesRunningProcess(): void
    {
        $this->createManager(stopGraceSeconds: 1);
        $result = $this->manager->start('sleep 30');

        $stopResult = $this->manager->stop($result['pid']);

        self::assertTrue($stopResult['stopped_by_user']);
        self::assertFalse($stopResult['already_finished']);
        self::assertSame('term+kill', $stopResult['signal_sent']);

        // Process should be dead
        \usleep(200000);
        self::assertFalse($this->isProcessAlive($result['pid']));
    }

    public function testStopOnAlreadyFinishedProcess(): void
    {
        $this->createManager();
        // A quick echo finishes nearly instantly
        $result = $this->manager->start('echo "quick"');

        // Wait for it to finish
        \usleep(500000);

        $stopResult = $this->manager->stop($result['pid']);

        // The stop method should detect it already finished via refreshStatus
        self::assertTrue($stopResult['already_finished'],
            'Expected already_finished=true but got signal_sent=' . ($stopResult['signal_sent'] ?? '?'));
        self::assertFalse($stopResult['stopped_by_user']);
    }

    public function testStopThrowsOnUnknownProcess(): void
    {
        $this->createManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No background process found');

        $this->manager->stop(999999);
    }

    public function testStopMarksStoppedByUserInDb(): void
    {
        $this->createManager(stopGraceSeconds: 1);
        $result = $this->manager->start('sleep 30');

        $this->manager->stop($result['pid']);

        // Verify via list
        $processes = $this->manager->list();
        $found = false;
        foreach ($processes as $p) {
            if ($p['pid'] === $result['pid']) {
                $found = true;
                self::assertTrue($p['stopped_by_user']);
                self::assertStringContainsString('stopped', $p['status']);
                break;
            }
        }
        self::assertTrue($found);
    }

    public function testStopTermOnlyWhenProcessFinishesDuringGrace(): void
    {
        // With the procCache removed, after TERM is sent and the grace window
        // passes, isAlive() performs a fresh /proc/<pid> check. If a short-lived
        // process (sleep 3) finishes naturally within the grace window (5s),
        // no KILL is sent — only TERM.
        $this->createManager(stopGraceSeconds: 5);
        $result = $this->manager->start('sleep 3');
        $stopResult = $this->manager->stop($result['pid']);

        self::assertTrue($stopResult['stopped_by_user']);
        self::assertFalse($stopResult['already_finished']);
        self::assertSame('term', $stopResult['signal_sent'],
            'Expected term-only signal: sleep 3 should finish within 5s grace window');
    }

    /* ── cleanupStale() tests ── */

    public function testCleanupStaleRemovesOldFinishedProcesses(): void
    {
        $this->createManager(retentionSeconds: 0); // zero retention = everything is stale
        $result = $this->manager->start('echo "stale test"');

        // Wait for finish
        \usleep(500000);

        $count = $this->manager->cleanupStale();
        self::assertSame(1, $count, 'Expected 1 stale process cleaned, got ' . $count);

        // DB record should be gone
        $processes = $this->manager->list();
        self::assertCount(0, $processes);
    }

    public function testCleanupStalePreservesRunningProcesses(): void
    {
        $this->createManager(retentionSeconds: 0);
        $result = $this->manager->start('sleep 30');

        $count = $this->manager->cleanupStale();

        // Running processes should not be cleaned
        self::assertSame(0, $count);

        $this->manager->shutdownCleanup();
    }

    public function testCleanupStaleRemovesLogFiles(): void
    {
        $this->createManager(retentionSeconds: 0);
        $result = $this->manager->start('echo "log cleanup test"');

        \usleep(500000);

        $logPath = $result['log_path'];
        self::assertFileExists($logPath);

        $this->manager->cleanupStale();

        self::assertFileDoesNotExist($logPath);
    }

    /* ── shutdownCleanup() tests ── */

    public function testShutdownCleanupTerminatesRunningProcesses(): void
    {
        $this->createManager(stopGraceSeconds: 1);
        $this->manager->start('sleep 30');
        $this->manager->start('sleep 30');

        $count = $this->manager->shutdownCleanup();

        self::assertSame(2, $count);

        // All processes should be stopped
        $processes = $this->manager->list();
        foreach ($processes as $p) {
            self::assertNotNull($p['finished_at']);
        }
    }

    public function testShutdownCleanupWithNoRunningProcesses(): void
    {
        $this->createManager();
        $count = $this->manager->shutdownCleanup();
        self::assertSame(0, $count);
    }

    /* ── Table creation tests ── */

    public function testTableIsCreatedLazily(): void
    {
        $this->createManager();

        // Table should not exist before first operation
        $schemaManager = $this->connection->createSchemaManager();
        self::assertFalse($schemaManager->tablesExist(['background_process']));

        // First operation creates the table
        $this->manager->list();
        self::assertTrue($schemaManager->tablesExist(['background_process']));
    }

    /* ── DB persistence tests ── */

    public function testDataPersistsAcrossManagerInstances(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "persist test"');

        // Create a new manager with the same connection and config
        $config = new BackgroundProcessConfig(storageDir: $this->tmpDir);
        $manager2 = new BackgroundProcessManager($this->connection, $config);

        $processes = $manager2->list();
        self::assertGreaterThanOrEqual(1, \count($processes));

        $this->manager->shutdownCleanup();
    }

    public function testStartWithLongRunningCommandProducesRunningStatus(): void
    {
        $this->createManager();
        $result = $this->manager->start('sleep 5');

        // Immediately check status — should be running
        $processes = $this->manager->list();
        $found = false;
        foreach ($processes as $p) {
            if ($p['pid'] === $result['pid']) {
                $found = true;
                self::assertStringContainsString('running', $p['status']);
                break;
            }
        }
        self::assertTrue($found);

        $this->manager->shutdownCleanup();
    }

    /* ── Helpers ── */

    private function assertLogEventuallyContains(string $logPath, string $expected, int $maxWaitMicro = 2_000_000): void
    {
        $waited = 0;
        $step = 100_000; // 100ms in microseconds
        while ($waited < $maxWaitMicro) {
            if (is_file($logPath)) {
                $content = @file_get_contents($logPath);
                if (false !== $content && str_contains($content, $expected)) {
                    self::assertTrue(true);

                    return;
                }
            }
            \usleep($step);
            $waited += $step;
        }

        self::fail(\sprintf('Log file "%s" did not contain expected content "%s" within %d \u{b5}s.', $logPath, $expected, $maxWaitMicro));
    }

    private function isProcessAlive(int $pid): bool
    {
        return is_dir('/proc/'.$pid);
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
                if ($file->getExtension() === 'pid') {
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
