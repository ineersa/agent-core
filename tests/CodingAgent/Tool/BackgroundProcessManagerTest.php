<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\BackgroundProcessFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcess\StartResult;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Retained native-control proofs for owner-prepared Bash.
 *
 * Deleted obsolete coverage that expected unprepared start() to insert SQL or
 * worker shutdownCleanup() to reap durable DB rows. Those contracts are gone.
 *
 * @covers \Ineersa\CodingAgent\Tool\BackgroundProcessManager
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 */
final class BackgroundProcessManagerTest extends IsolatedKernelTestCase
{
    private BackgroundProcessManager $manager;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_bg_test');
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            try {
                $this->manager->shutdownCleanup();
            } catch (\RuntimeException) {
            }
        }
        // Accepted background jobs belong to the session, not the worker. Tests
        // that leave them running must still release owned native children.
        if (is_dir($this->tmpDir)) {
            foreach (glob($this->tmpDir.'/*.pid') ?: [] as $pidFile) {
                $pid = (int) trim((string) @file_get_contents($pidFile));
                if ($pid > 0) {
                    @posix_kill($pid, \SIGTERM);
                    @posix_kill(-$pid, \SIGTERM);
                }
            }
        }
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testReadLogTailThrowsOnUnknownPid(): void
    {
        $this->createManager();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No background process found');
        $this->manager->readLogTail($this->createSession(), 999999);
    }

    public function testStopThrowsOnUnknownPid(): void
    {
        $this->createManager();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No background process found');
        $this->manager->stop(999999, $this->createSession());
    }

    public function testShutdownCleanupWithNoRunningProcesses(): void
    {
        $this->createManager();
        $this->assertSame(0, $this->manager->shutdownCleanup());
    }

    public function testShutdownCleanupStopsOnlyUnacceptedLocalNativeLaunches(): void
    {
        $this->createManager(stopGraceSeconds: 0);
        $session = $this->createSession('native shutdown');
        $fifo = $this->tmpDir.'/native-shutdown';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $endpoint = fopen($fifo, 'r+b');
        $this->assertIsResource($endpoint);
        $foreground = $this->startPrepared('cat '.escapeshellarg($fifo), $session, 'call-foreground');
        $accepted = $this->startPrepared('cat '.escapeshellarg($fifo), $session, 'call-accepted');
        // Marks the local native handle accepted so worker shutdown leaves it alone.
        $candidate = $this->manager->resolveBackgroundAcceptance($accepted->statusPath, $session);
        $this->assertNotNull($candidate);
        $this->assertTrue($this->manager->acceptResolvedBackground($candidate));

        $cleaned = $this->manager->shutdownCleanup();
        $this->assertGreaterThanOrEqual(1, $cleaned);
        $this->assertTrue(@posix_kill($accepted->pid, 0));
        $this->assertNotNull($this->manager->findByRecordId($accepted->id, $session));
        fclose($endpoint);
    }

    public function testFinishedNativeHandleSurvivesAcceptanceObservationUntilCallerRelease(): void
    {
        $this->createManager();
        $session = $this->createSession('finish before accept observation');
        $started = $this->startPrepared('printf finished-before-accept', $session, 'call-finish-race');
        $this->waitUntilFinished($started->pid);
        $this->assertFileExists($started->statusPath);

        // Acceptance observation that sees completion must leave the launch for
        // BashTool's next observe/consume path instead of discarding it.
        $this->assertNull($this->manager->resolveBackgroundAcceptance($started->statusPath, $session));
        $observed = $this->manager->observeNativeByStatusPath($started->statusPath, $session);
        $this->assertNotNull($observed);
        $this->assertNotNull($observed->finishedAt);
        $this->assertSame(0, $observed->exitCode);
        $this->assertStringContainsString('finished-before-accept', $this->manager->readLogTailForNativePaths($started->logPath, $started->statusPath)->content);

        $this->manager->releaseNativeInvocation($started->statusPath);
        $this->assertNull($this->manager->observeNativeByStatusPath($started->statusPath, $session));
        $this->assertFileExists($started->logPath);
        $this->assertFileExists($started->statusPath);
    }

    public function testReleaseNativeInvocationDeletesOnlyUnpersistedSidecars(): void
    {
        $this->createManager();
        $session = $this->createSession('nested sidecar cleanup');
        $nested = $this->manager->start('printf nested-sidecar', $session);
        $this->assertNull($nested->id);
        $this->waitUntilFinished($nested->pid);
        $this->assertNotNull($this->manager->observeNativeByStatusPath($nested->statusPath, $session));
        $this->manager->releaseNativeInvocation($nested->statusPath);
        $this->assertFileDoesNotExist($nested->logPath);
        $this->assertFileDoesNotExist($nested->statusPath);
        $this->assertFileDoesNotExist(substr($nested->statusPath, 0, -7).'.pid');

        $persisted = $this->startPrepared('printf persisted-sidecar', $session, 'call-persisted');
        $this->waitUntilFinished($persisted->pid);
        $this->assertNotNull($this->manager->observeNativeByStatusPath($persisted->statusPath, $session));
        $this->manager->releaseNativeInvocation($persisted->statusPath);
        $this->assertFileExists($persisted->logPath);
        $this->assertFileExists($persisted->statusPath);
        $this->assertFileExists(substr($persisted->statusPath, 0, -7).'.pid');
    }

    public function testReleaseNativeInvocationLogsSidecarCleanupFailure(): void
    {
        $logger = new TestLogger();
        $this->createManager(logger: $logger);
        $session = $this->createSession('sidecar cleanup failure');
        $nested = $this->manager->start('printf nested-fail-cleanup', $session);
        $this->assertNull($nested->id);
        $this->waitUntilFinished($nested->pid);
        $this->assertNotNull($this->manager->observeNativeByStatusPath($nested->statusPath, $session));
        // Make unlink of the remaining sidecar siblings fail so delete returns false.
        $this->assertTrue(chmod($this->tmpDir, 0o555));
        try {
            $this->manager->releaseNativeInvocation($nested->statusPath);
        } finally {
            $this->assertTrue(chmod($this->tmpDir, 0o755));
        }
        $messages = array_column(array_filter($logger->records, static fn (array $record): bool => 'error' === $record['level']), 'message');
        $this->assertContains('background_process.native_sidecar_cleanup_failed', $messages);
        $this->assertNull($this->manager->observeNativeByStatusPath($nested->statusPath, $session));
    }


    public function testFindByRecordIdReturnsNullForNonexistentId(): void
    {
        $this->createManager();
        $this->assertNull($this->manager->findByRecordId(9999999, $this->createSession()));
    }

    public function testExistsByRecordIdReturnsFalseForNonexistentId(): void
    {
        $this->createManager();
        $this->assertFalse($this->manager->existsByRecordId(9999999));
    }

    public function testBackgroundRequestTargetsOnlyCurrentRunModelToolsAndDoesNotNotify(): void
    {
        $this->createManager();
        $session = $this->createSession('bg request current run');
        $foreignSession = $this->createSession('bg request foreign');
        $fifo = $this->tmpDir.'/command-release';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $endpoint = fopen($fifo, 'r+b');
        $this->assertIsResource($endpoint);
        $command = 'cat '.escapeshellarg($fifo);
        $first = $this->startPrepared($command, $session, 'call-first');
        $second = $this->startPrepared($command, $session, 'call-second');
        $shell = $this->startPrepared($command, $session, 'call-shell', prepareDeferred: false);
        $foreign = $this->startPrepared($command, $foreignSession, 'call-foreign');

        $controllerManager = $this->createOtherManager();
        $this->assertFalse($controllerManager->requestBackground($session, $foreign->id, 'call-foreign'));
        // Direct shell keeps tool_call_id but no deferred correlation, so Ctrl+B
        // candidates exclude it even though an exact requestBackground could hit the row.
        $this->assertSame([], array_values(array_filter(
            $controllerManager->foregroundTools($session),
            static fn ($candidate): bool => $candidate->id === $shell->id,
        )));
        $this->assertFalse($controllerManager->requestBackground($session, $first->id, 'wrong-call'));
        $this->assertTrue($controllerManager->requestBackground($session, $first->id, 'call-first'));
        $this->assertFalse($this->manager->findByRecordId($second->id)?->backgroundRequested);
        $this->assertTrue($controllerManager->requestBackground($session, $first->id, 'call-first'));
        $this->assertTrue($controllerManager->requestBackground($session, $second->id, 'call-second'));
        $this->assertFalse($controllerManager->requestBackground('', $first->id, 'call-first'));

        foreach ([$first, $second] as $started) {
            $record = $this->manager->findByRecordId($started->id, $session);
            $this->assertNotNull($record);
            $this->assertTrue($record->backgroundRequested);
            $this->assertNull($record->backgroundedAt);
            $this->assertNull($record->finishedAt);
            $this->assertSame($started->pid, $record->pid);
        }
        $this->assertFalse($this->manager->findByRecordId($shell->id)?->backgroundRequested);
        $this->assertFalse($this->manager->findByRecordId($foreign->id)?->backgroundRequested);
        $this->assertSame([], $this->manager->list($session));
        foreach (static::getContainer()->get(ProcessStore::class)->unfinishedSnapshots([$session]) as $candidate) {
            $this->assertNull($candidate->backgroundedAt);
            $this->assertNull($candidate->completionNotifiedAt);
        }
        fclose($endpoint);
    }

    public function testBackgroundRequestLeavesFinishedAndAlreadyBackgroundedCommandsAlone(): void
    {
        $this->createManager();
        $session = $this->createSession('bg request finished');
        $finished = $this->startPrepared('printf done', $session, 'call-finished');
        $this->waitUntilFinished($finished->pid);
        $this->manager->refreshRecords(self::getContainer()->get(ProcessStore::class)->unfinishedSnapshots([$session]));
        $this->assertNotNull($this->manager->findByRecordId($finished->id, $session)?->finishedAt);
        $fifo = $this->tmpDir.'/background-release';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $endpoint = fopen($fifo, 'r+b');
        $this->assertIsResource($endpoint);
        $backgrounded = $this->startPrepared('cat '.escapeshellarg($fifo), $session, 'call-background');
        // Model Bash acceptance is owner-applied from deferred completion, not the
        // local markBackgrounded path (which skips SQL when deferredId is present).
        self::getContainer()->get(ProcessStore::class)->applyAcceptedByRecord(
            $backgrounded->id,
            $session,
            new \DateTimeImmutable(),
        );

        $this->assertFalse($this->manager->requestBackground($session, $finished->id, 'call-finished'));
        $this->assertFalse($this->manager->requestBackground($session, $backgrounded->id, 'call-background'));
        $this->assertFalse($this->manager->findByRecordId($finished->id)?->backgroundRequested);
        $this->assertFalse($this->manager->findByRecordId($backgrounded->id)?->backgroundRequested);
        fclose($endpoint);
    }

    public function testDetachedProcessKeepsOriginalMonotonicDeadlineAndStops(): void
    {
        $this->createManager();
        $session = $this->createSession('deadline stop');
        $fifo = $this->tmpDir.'/deadline-release';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $endpoint = fopen($fifo, 'r+b');
        $this->assertIsResource($endpoint);
        // This short real deadline is the product delay under test, not a readiness sleep.
        $originalDeadline = hrtime(true) + 500_000_000;
        $process = $this->startPrepared('cat '.escapeshellarg($fifo), $session, 'deadline-call', $originalDeadline);
        self::getContainer()->get(ProcessStore::class)->applyAcceptedByRecord(
            $process->id,
            $session,
            new \DateTimeImmutable(),
        );
        $safetyDeadline = hrtime(true) + 2_000_000_000;
        do {
            $this->manager->refreshRecords(self::getContainer()->get(ProcessStore::class)->unfinishedSnapshots([$session]));
            $record = $this->manager->findByRecordId($process->id, $session);
            if (null !== $record?->finishedAt) {
                break;
            }
            usleep(10_000);
        } while (hrtime(true) < $safetyDeadline);

        $this->assertNotNull($record?->finishedAt);
        $this->assertGreaterThanOrEqual($originalDeadline, hrtime(true));
        $this->assertSame($originalDeadline, BackgroundProcessFixture::record(self::getContainer(), $record->id)?->deadlineNanoseconds);
        fclose($endpoint);
    }

    public function testExpiredProcessesReceiveTermTogetherWithoutWaitingForGrace(): void
    {
        $this->createManager(stopGraceSeconds: 30);
        $session = $this->createSession('expired term');
        $fifo = $this->tmpDir.'/expired-release';
        $this->assertTrue(posix_mkfifo($fifo, 0600));
        $endpoint = fopen($fifo, 'r+b');
        $this->assertIsResource($endpoint);
        $command = 'trap "" TERM; cat '.escapeshellarg($fifo);
        $processes = [];
        for ($index = 0; $index < 3; ++$index) {
            $process = $this->startPrepared($command, $session, 'call-'.$index, hrtime(true) - 1);
            self::getContainer()->get(ProcessStore::class)->applyAcceptedByRecord(
                $process->id,
                $session,
                new \DateTimeImmutable(),
            );
            $processes[] = $process;
        }

        $this->manager->refreshRecords(self::getContainer()->get(ProcessStore::class)->unfinishedSnapshots([$session]));
        foreach ($processes as $process) {
            $record = $this->manager->findByRecordId($process->id, $session);
            $this->assertNotNull($record);
            $this->assertNull($record->finishedAt);
            $this->assertSame('stopped', $record->status->value);
        }
        fclose($endpoint);
    }

    public function testLateObservationWithoutTermKeepsSuccessfulExit(): void
    {
        $this->createManager();
        $session = $this->createSession('late observation');
        $process = $this->startPrepared('printf done', $session, 'finished-call', hrtime(true) - 1);
        self::getContainer()->get(ProcessStore::class)->applyAcceptedByRecord(
            $process->id,
            $session,
            new \DateTimeImmutable(),
        );
        $this->waitUntilFinished($process->pid);
        $this->manager->refreshRecords(self::getContainer()->get(ProcessStore::class)->unfinishedSnapshots([$session]));
        $record = $this->manager->findByRecordId($process->id, $session);
        $this->assertNotNull($record?->finishedAt);
        $this->assertSame(0, $record->exitCode);
    }

    private function createManager(int $stopGraceSeconds = 1, ?TestLogger $logger = null): void
    {
        $config = new BackgroundProcessConfig(
            storageDir: $this->tmpDir,
            stopGraceSeconds: $stopGraceSeconds,
            logTailChars: 5000,
        );
        $store = static::getContainer()->get(ProcessStore::class);
        $lifecycle = new ProcessLifecycle($config, new NullLogger(), new LockFactory(new FlockStore()));
        $this->manager = new BackgroundProcessManager($store, $lifecycle, $config, $logger ?? new TestLogger());
    }

    private function createOtherManager(int $stopGraceSeconds = 1): BackgroundProcessManager
    {
        $config = new BackgroundProcessConfig(
            storageDir: $this->tmpDir,
            stopGraceSeconds: $stopGraceSeconds,
            logTailChars: 5000,
        );
        $store = static::getContainer()->get(ProcessStore::class);
        $lifecycle = new ProcessLifecycle($config, new NullLogger(), new LockFactory(new FlockStore()));

        return new BackgroundProcessManager($store, $lifecycle, $config, new NullLogger());
    }

    private function createSession(string $prompt = 'manager proof'): string
    {
        return self::getContainer()->get(HatfieldSessionStore::class)->createSession($prompt);
    }

    private function startPrepared(
        string $command,
        string $sessionId,
        string $toolCallId,
        ?int $deadlineNanoseconds = null,
        bool $backgroundRequested = false,
        bool $prepareDeferred = true,
    ): StartResult {
        if ($prepareDeferred) {
            $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
            if (null === $deferred->findMetadataByRunAndToolCall($sessionId, $toolCallId)) {
                $stepId = 'step-'.$toolCallId;
                $call = new ExecuteToolCall($sessionId, 1, $stepId, 1, 'key-'.$toolCallId, $toolCallId, 'bash', ['command' => $command], 0);
                $batches = self::getContainer()->get(ToolBatchStoreInterface::class);
                TestToolBatchRegistration::register(new ToolBatchCollector($batches), $batches, $sessionId, 1, $stepId, [$call]);
                $deferred->preparePending(ToolCallResultFactory::correlationFromExecuteToolCall(
                    $call,
                    'deferred-'.$toolCallId,
                ));
            }
        }
        $prepared = $this->manager->prepareNativeLaunch($sessionId, $toolCallId, $command, $backgroundRequested);
        $started = $this->manager->start(
            $command,
            $sessionId,
            $toolCallId,
            $deadlineNanoseconds,
            $backgroundRequested,
            preparedRecordId: $prepared->id,
            preparedLogPath: $prepared->logPath,
            preparedStatusPath: $prepared->statusPath,
            deferredId: $prepareDeferred ? 'deferred-'.$toolCallId : null,
        );
        self::getContainer()->get(ProcessStore::class)->applyLaunch(
            $prepared->id,
            $sessionId,
            $started->pid,
            null,
            new \DateTimeImmutable(),
            $deadlineNanoseconds,
        );

        return $started;
    }

    private function waitUntilFinished(int $pid): void
    {
        $deadline = hrtime(true) + 2_000_000_000;
        do {
            if (!@posix_kill($pid, 0)) {
                return;
            }
            usleep(10_000);
        } while (hrtime(true) < $deadline);
        $this->fail(\sprintf('Timed out waiting for pid %d to exit', $pid));
    }
}
