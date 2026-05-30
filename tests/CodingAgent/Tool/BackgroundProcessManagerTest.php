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

        $this->tmpDir = sys_get_temp_dir().'/hatfield_bg_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);
    }

    protected function tearDown(): void
    {
        // Clean up any running processes
        $this->cleanupProcesses();

        $this->rmDir($this->tmpDir);
    }

    /* ── start() tests ── */

    public function testStartCreatesRecordAndLogs(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "hello from bg"');

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('pid', $result);
        $this->assertGreaterThan(0, $result['pid']);
        $this->assertArrayHasKey('pgid', $result);
        $this->assertArrayHasKey('log_path', $result);
        $this->assertArrayHasKey('started_at', $result);
        $this->assertSame('running', $result['status']);

        // PID should be a real process (or recently exited)
        $this->assertFileExists($result['log_path']);
    }

    public function testStartDetectsSetsidAvailable(): void
    {
        // Sanity: on this Linux system, command -v setsid returns 0
        // (the preflight inside start() depends on this).
        exec('command -v setsid', $_, $rc);
        $this->assertSame(0, $rc, 'setsid must be available for start() to function');

        // Calling start() should succeed since setsid is present
        $this->createManager();
        $result = $this->manager->start('echo "setsid test"');
        $this->assertGreaterThan(0, $result['pid']);
    }

    public function testStartCommandWithProcessGroup(): void
    {
        $this->createManager();
        // Use a longer-running command so the process is still alive
        // when we query PGID via ps.
        $result = $this->manager->start('sleep 5');

        // PGID should be set when setsid is available
        $this->assertNotNull($result['pgid']);
        $this->assertGreaterThan(0, $result['pgid']);

        // Process group leader should be the PID itself
        $this->assertSame($result['pid'], $result['pgid']);

        $this->manager->shutdownCleanup();
    }

    public function testStartWithCustomStorageDir(): void
    {
        $customDir = $this->tmpDir.'/custom_bg';
        $this->createManager(storageDir: $customDir);

        $result = $this->manager->start('echo "custom dir"');

        $this->assertStringContainsString('custom_bg', $result['log_path']);
        $this->assertFileExists($result['log_path']);

        // Clean up
        $this->manager->shutdownCleanup();
    }

    public function testStartCreatesLogFromCommand(): void
    {
        $this->createManager();
        $result = $this->manager->start('printf "log line 1\nlog line 2\n"');

        // Wait for short process to finish
        usleep(500000);

        $this->assertLogEventuallyContains($result['log_path'], 'log line 1');
        $this->assertLogEventuallyContains($result['log_path'], 'log line 2');

        $this->manager->shutdownCleanup();
    }

    /* ── list() tests ── */

    public function testListReturnsEmptyWhenNoProcesses(): void
    {
        $this->createManager();
        $processes = $this->manager->list();

        $this->assertIsArray($processes);
        $this->assertCount(0, $processes);
    }

    public function testListReturnsStartedProcess(): void
    {
        $this->createManager();
        // Use a command that runs long enough for list to catch it running
        $this->manager->start('sleep 5');

        $processes = $this->manager->list();

        $this->assertCount(1, $processes);
        $this->assertStringContainsString('sleep', $processes[0]['command']);
        $this->assertStringContainsString('running', $processes[0]['status']);

        $this->manager->shutdownCleanup();
    }

    public function testListReturnsMultipleProcesses(): void
    {
        $this->createManager();
        $this->manager->start('echo "proc 1"');
        $this->manager->start('echo "proc 2"');

        $processes = $this->manager->list();

        $this->assertCount(2, $processes);
    }

    public function testListShowsFinishedProcessStatus(): void
    {
        $this->createManager();
        $this->manager->start('echo "finish test"');

        // Wait for the short process to finish
        usleep(500000);

        $processes = $this->manager->list();

        $this->assertCount(1, $processes);
        // Status should transition from running to finished
        $this->assertStringContainsString('finish', $processes[0]['status']);
        // Exit code should be 0 for echo
        $this->assertSame(0, $processes[0]['exit_code']);
    }

    /* ── readLogTail() tests ── */

    public function testReadLogTailReturnsContent(): void
    {
        $this->createManager();
        $result = $this->manager->start('echo "tail test content"');

        // Wait for process to write
        usleep(500000);

        $logResult = $this->manager->readLogTail($result['pid']);

        $this->assertSame($result['pid'], $logResult['pid']);
        $this->assertFalse($logResult['truncated']);
        $this->assertStringContainsString('tail test content', $logResult['content']);

        $this->manager->shutdownCleanup();
    }

    public function testReadLogTailTruncatesLargeOutput(): void
    {
        $this->createManager(logTailChars: 50);
        // Generate large output with repeated text
        $result = $this->manager->start('for i in $(seq 1 100); do echo "line $i with some padding text to make it longer"; done');

        // Wait for process to finish and write
        usleep(500000);

        $logResult = $this->manager->readLogTail($result['pid'], 50); // match logTailChars: 50

        // With 50 char cap and large output, truncation should happen
        if ($logResult['total_bytes'] > 50) {
            $this->assertTrue($logResult['truncated'],
                'Expected truncated=true for '.$logResult['total_bytes'].' byte log with 50 char cap');
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

    public function testStopTerminatesRunningProcessWithKill(): void
    {
        $this->createManager(stopGraceSeconds: 1);
        // Use a TERM-ignoring command to force the KILL-after-grace path
        $result = $this->manager->start('trap "" TERM; sleep 30');

        $stopResult = $this->manager->stop($result['pid']);

        $this->assertTrue($stopResult['stopped_by_user']);
        $this->assertFalse($stopResult['already_finished']);
        $this->assertSame('term+kill', $stopResult['signal_sent']);

        // Process should be dead after KILL
        usleep(200000);
        $this->assertFalse($this->isProcessAlive($result['pid']));
    }

    public function testStopOnAlreadyFinishedProcess(): void
    {
        $this->createManager();
        // A quick echo finishes nearly instantly
        $result = $this->manager->start('echo "quick"');

        // Wait for it to finish
        usleep(500000);

        $stopResult = $this->manager->stop($result['pid']);

        // The stop method should detect it already finished via refreshStatus
        $this->assertTrue($stopResult['already_finished'],
            'Expected already_finished=true but got signal_sent='.($stopResult['signal_sent'] ?? '?'));
        $this->assertFalse($stopResult['stopped_by_user']);
    }

    public function testStopThrowsOnUnknownProcess(): void
    {
        $this->createManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No background process found');

        $this->manager->stop(999999);
    }

    public function testStopThrowsOnInvalidPid(): void
    {
        $this->createManager();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid PID');

        $this->manager->stop(0);
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
                $this->assertTrue($p['stopped_by_user']);
                $this->assertStringContainsString('stopped', $p['status']);
                break;
            }
        }
        $this->assertTrue($found);
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

        $this->assertTrue($stopResult['stopped_by_user']);
        $this->assertFalse($stopResult['already_finished']);
        $this->assertSame('term', $stopResult['signal_sent'],
            'Expected term-only signal: sleep 3 should finish within 5s grace window');
    }

    public function testStopTerminatesProcessWithSigterm(): void
    {
        // Prove that SIGTERM actually reaches the workload process by using a
        // command that traps TERM and writes a sentinel file. If the sentinel
        // exists after stop(), TERM delivered correctly to the child.
        $this->createManager(stopGraceSeconds: 3);

        $sentinel = $this->tmpDir.'/term_sentinel';

        // Create a script that traps TERM, writes sentinel, then exits
        $scriptPath = $this->tmpDir.'/term_test.sh';
        file_put_contents(
            $scriptPath,
            "#!/bin/bash\ntrap 'echo term_received > ".escapeshellarg($sentinel)."; exit 0' TERM\nwhile true; do sleep 1; done\n",
        );
        chmod($scriptPath, 0755);

        $result = $this->manager->start($scriptPath);

        // Give the process a moment to start
        usleep(200000);

        $stopResult = $this->manager->stop($result['pid']);

        // With the TERM-forwarding wrapper, TERM should reach the workload and
        // the script's trap handler should write the sentinel before exiting.
        $this->assertTrue($stopResult['stopped_by_user']);
        $this->assertFalse($stopResult['already_finished']);
        $this->assertSame('term', $stopResult['signal_sent'],
            'Expected term-only signal: TERM should have reached the workload');
        $this->assertFileExists($sentinel,
            'Sentinel file should exist — SIGTERM should have reached the process');

        $content = file_get_contents($sentinel);
        $this->assertStringContainsString('term_received', $content);
    }

    public function testStopPreservesWrapperStatusFileOnGracefulTerm(): void
    {
        // When TERM reaches the workload and the wrapper writes the real
        // exit code to the status file, stop() must NOT overwrite it with
        // -1 (which would corrupt forensic exit-code evidence).
        $this->createManager(stopGraceSeconds: 3);

        $sentinel = $this->tmpDir.'/term_sentinel';
        $scriptPath = $this->tmpDir.'/term_test2.sh';
        file_put_contents(
            $scriptPath,
            "#!/bin/bash\ntrap 'echo term_received > ".escapeshellarg($sentinel)."; exit 42' TERM\nwhile true; do sleep 1; done\n",
        );
        chmod($scriptPath, 0755);

        $result = $this->manager->start($scriptPath);
        usleep(200000);

        $this->manager->stop($result['pid']);

        // The wrapper should have written the real exit code (42)
        // to the status file, not -1.
        $statusPath = null;
        $processes = $this->manager->list();
        foreach ($processes as $p) {
            if ($p['pid'] === $result['pid']) {
                // Process is stopped — status file should contain the real exit code
                // We check via the log_path pattern to find the status file
                break;
            }
        }

        // Verify sentinel was written (TERM reached workload)
        $this->assertFileExists($sentinel);
        $sentinelContent = file_get_contents($sentinel);
        $this->assertStringContainsString('term_received', $sentinelContent);

        // Find the status file on disk — it should exist and NOT contain -1
        // (The wrapper wrote the real exit code before stop() had a chance to
        // overwrite; and our fix now skips overwriting when the file exists.)
        $foundStatusFile = false;
        foreach (new \FilesystemIterator($this->tmpDir, \FilesystemIterator::SKIP_DOTS) as $file) {
            if ('status' === $file->getExtension()) {
                $foundStatusFile = true;
                $statusContent = file_get_contents((string) $file);
                $this->assertNotSame('-1', trim($statusContent),
                    'Status file should NOT contain -1; wrapper wrote real exit code');
                break;
            }
        }
        $this->assertTrue($foundStatusFile, 'No .status file found in tmpDir');
    }

    /* ── cleanupStale() tests ── */

    public function testCleanupStaleRemovesOldFinishedProcesses(): void
    {
        $this->createManager(retentionSeconds: 0); // zero retention = everything is stale
        $result = $this->manager->start('echo "stale test"');

        // Wait for finish
        usleep(500000);

        $count = $this->manager->cleanupStale();
        $this->assertSame(1, $count, 'Expected 1 stale process cleaned, got '.$count);

        // DB record should be gone
        $processes = $this->manager->list();
        $this->assertCount(0, $processes);
    }

    public function testCleanupStalePreservesRunningProcesses(): void
    {
        $this->createManager(retentionSeconds: 0);
        $result = $this->manager->start('sleep 30');

        $count = $this->manager->cleanupStale();

        // Running processes should not be cleaned
        $this->assertSame(0, $count);

        $this->manager->shutdownCleanup();
    }

    public function testCleanupStaleRemovesLogFiles(): void
    {
        $this->createManager(retentionSeconds: 0);
        $result = $this->manager->start('echo "log cleanup test"');

        usleep(500000);

        $logPath = $result['log_path'];
        $this->assertFileExists($logPath);

        $this->manager->cleanupStale();

        $this->assertFileDoesNotExist($logPath);
    }

    /* ── shutdownCleanup() tests ── */

    public function testShutdownCleanupTerminatesRunningProcesses(): void
    {
        $this->createManager(stopGraceSeconds: 1);
        $this->manager->start('sleep 30');
        $this->manager->start('sleep 30');

        $count = $this->manager->shutdownCleanup();

        $this->assertSame(2, $count);

        // All processes should be stopped
        $processes = $this->manager->list();
        foreach ($processes as $p) {
            $this->assertNotNull($p['finished_at']);
        }
    }

    public function testShutdownCleanupWithNoRunningProcesses(): void
    {
        $this->createManager();
        $count = $this->manager->shutdownCleanup();
        $this->assertSame(0, $count);
    }

    /* ── Table creation tests ── */

    public function testTableIsCreatedLazily(): void
    {
        $this->createManager();

        // Table should not exist before first operation
        $schemaManager = $this->connection->createSchemaManager();
        $this->assertFalse($schemaManager->tablesExist(['background_process']));

        // First operation creates the table
        $this->manager->list();
        $this->assertTrue($schemaManager->tablesExist(['background_process']));
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
        $this->assertGreaterThanOrEqual(1, \count($processes));

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
                $this->assertStringContainsString('running', $p['status']);
                break;
            }
        }
        $this->assertTrue($found);

        $this->manager->shutdownCleanup();
    }

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

    /* ── Helpers ── */

    private function assertLogEventuallyContains(string $logPath, string $expected, int $maxWaitMicro = 2_000_000): void
    {
        $waited = 0;
        $step = 100_000; // 100ms in microseconds
        while ($waited < $maxWaitMicro) {
            if (is_file($logPath)) {
                $content = @file_get_contents($logPath);
                if (false !== $content && str_contains($content, $expected)) {
                    $this->assertTrue(true);

                    return;
                }
            }
            usleep($step);
            $waited += $step;
        }

        $this->fail(\sprintf('Log file "%s" did not contain expected content "%s" within %d \u{b5}s.', $logPath, $expected, $maxWaitMicro));
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
