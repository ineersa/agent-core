<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Session\History\CacheHistoryProjectionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\Process;

/**
 * Thesis: ordinary history/policy reads wait for a healthy commit's transition
 * lock instead of mistaking temporary readiness withdrawal for interrupted
 * recovery, while genuinely interrupted projections still fail closed.
 *
 * Failure-hook masking is covered by RegistryBackedToolboxTest /
 * ExtensionToolHookEventSubscriberTest on the real toolbox path.
 */
#[CoversClass(CacheHistoryProjectionStore::class)]
final class HistoryProjectionCommitCoherenceTest extends TestCase
{
    private ?string $tmpDir = null;

    /** @var list<Process> */
    private array $ownedProcesses = [];

    protected function tearDown(): void
    {
        foreach ($this->ownedProcesses as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        $this->ownedProcesses = [];

        if (null !== $this->tmpDir) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
            $this->tmpDir = null;
        }
    }

    public function testPolicyReadWaitsForHealthyCommitInsteadOfTemporaryNotReady(): void
    {
        [$cacheDir, $lockDir, $runId, $store] = $this->prepareSharedProjection();
        $withdrawnMarker = $this->tmpDir.'/withdrawn.marker';
        $releaseMarker = $this->tmpDir.'/release.marker';
        $conflictLog = $this->tmpDir.'/conflict.log';

        $commit = $this->startOwnedProcess([
            \PHP_BINARY,
            __DIR__.'/Fixtures/history-projection-commit-coherence-worker.php',
            $cacheDir,
            $lockDir,
            $runId,
            $withdrawnMarker,
            $releaseMarker,
        ]);

        $this->waitUntil(
            static fn (): bool => is_file($withdrawnMarker) || !$commit->isRunning(),
            $commit,
            'Commit worker never wrote the withdrawn marker',
            2.0,
        );
        $this->assertTrue($commit->isRunning(), 'Commit worker must remain alive while holding the transition lock');
        $this->assertSame('W', file_get_contents($withdrawnMarker));

        $reader = $this->startOwnedProcess([
            \PHP_BINARY,
            __DIR__.'/Fixtures/history-projection-commit-coherence-reader.php',
            $cacheDir,
            $lockDir,
            $runId,
            $conflictLog,
            '1',
        ]);

        $this->waitUntil(
            static fn (): bool => self::conflictLogShowsTransitionContention($conflictLog, $runId),
            $reader,
            'Reader never contended on the writer-held production transition lock',
            2.0,
        );
        $this->assertTrue($reader->isRunning(), 'Reader must remain alive while waiting for the transition lock');
        $this->assertTrue($commit->isRunning(), 'Commit worker must still hold the transition lock when contention is observed');
        $this->assertTrue(self::conflictLogShowsTransitionContention($conflictLog, $runId));

        $this->assertNotFalse(file_put_contents($releaseMarker, 'R'));

        $this->waitUntil(
            static fn (): bool => !$reader->isRunning(),
            $reader,
            'Reader never exited after writer release',
            3.0,
        );
        $this->waitUntil(
            static fn (): bool => !$commit->isRunning(),
            $commit,
            'Commit worker never exited after release',
            3.0,
        );
        $this->assertSame(0, $commit->getExitCode(), $commit->getErrorOutput());
        $this->assertSame(0, $reader->getExitCode(), $reader->getErrorOutput());

        $payload = json_decode($reader->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(['bash'], $payload['allowed_tools']);
        $this->assertSame(2, $payload['last_seq']);
        $this->assertTrue($payload['ready']);
        $this->assertSame(2, $store->get($runId)->lastSeq);
    }

    public function testCounterfactualWithoutTransitionLockFailsDuringHealthyWithdraw(): void
    {
        [$cacheDir, $lockDir, $runId] = $this->prepareSharedProjection();
        $withdrawnMarker = $this->tmpDir.'/withdrawn.marker';
        $releaseMarker = $this->tmpDir.'/release.marker';
        $conflictLog = $this->tmpDir.'/conflict-counterfactual.log';

        $commit = $this->startOwnedProcess([
            \PHP_BINARY,
            __DIR__.'/Fixtures/history-projection-commit-coherence-worker.php',
            $cacheDir,
            $lockDir,
            $runId,
            $withdrawnMarker,
            $releaseMarker,
        ]);

        $this->waitUntil(
            static fn (): bool => is_file($withdrawnMarker) || !$commit->isRunning(),
            $commit,
            'Commit worker never wrote the withdrawn marker',
            2.0,
        );
        $this->assertTrue($commit->isRunning());

        $reader = $this->startOwnedProcess([
            \PHP_BINARY,
            __DIR__.'/Fixtures/history-projection-commit-coherence-reader.php',
            $cacheDir,
            $lockDir,
            $runId,
            $conflictLog,
            '0',
        ]);

        $this->waitUntil(
            static fn (): bool => !$reader->isRunning(),
            $reader,
            'Counterfactual reader never exited',
            2.0,
        );
        $this->assertSame(2, $reader->getExitCode(), $reader->getErrorOutput().$reader->getOutput());
        $payload = json_decode($reader->getOutput(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('is not ready; recovery required.', $payload['error']);
        $this->assertTrue($commit->isRunning(), 'Writer must still hold the withdrawn window when the counterfactual fails');

        $this->assertNotFalse(file_put_contents($releaseMarker, 'R'));
        $this->waitUntil(
            static fn (): bool => !$commit->isRunning(),
            $commit,
            'Commit worker never exited after counterfactual release',
            3.0,
        );
        $this->assertSame(0, $commit->getExitCode(), $commit->getErrorOutput());
    }

    public function testInterruptedNotReadyProjectionStillFailsClosed(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $runLock = new RunLockManager($locks);
        $store = new CacheHistoryProjectionStore(
            new ArrayAdapter(),
            $locks,
            new HistoryProjector(),
            $runLock,
        );
        $runId = 'interrupted-run';
        $store->initializeFromEvents($runId, [$this->runStarted($runId, seq: 1)]);
        $store->withdrawForCommit($runId);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('History projection for run interrupted-run is not ready; recovery required.');
        $store->get($runId);
    }

    public function testGetReleasesTransitionLockAfterException(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $runLock = new RunLockManager($locks, ttlSeconds: 30.0, acquireTimeoutSeconds: 0.2);
        $store = new CacheHistoryProjectionStore(
            new ArrayAdapter(),
            $locks,
            new HistoryProjector(),
            $runLock,
        );

        try {
            $store->get('missing-run');
            $this->fail('Expected missing projection exception');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('History projection missing for run missing-run', $exception->getMessage());
        }

        $observed = false;
        $otherOwner = new RunLockManager($locks, ttlSeconds: 30.0, acquireTimeoutSeconds: 0.2);
        $otherOwner->synchronized('missing-run', static function () use (&$observed): void {
            $observed = true;
        });
        $this->assertTrue($observed, 'Transition lock must be released after get() throws');
    }

    public function testGetReentrancyUnderHeldTransitionLockDoesNotDeadlock(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $runLock = new RunLockManager($locks);
        $store = new CacheHistoryProjectionStore(
            new ArrayAdapter(),
            $locks,
            new HistoryProjector(),
            $runLock,
        );
        $runId = 'reentrant-get';
        $store->initializeFromEvents($runId, [$this->runStarted($runId, seq: 1)]);

        $snapshot = $runLock->synchronized($runId, static fn () => $store->get($runId));
        $this->assertTrue($snapshot->ready);
        $this->assertSame(1, $snapshot->lastSeq);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: CacheHistoryProjectionStore}
     */
    private function prepareSharedProjection(): array
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('history-coherence');
        $cacheDir = $this->tmpDir.'/cache';
        $lockDir = $this->tmpDir.'/locks';
        mkdir($cacheDir, 0o700, true);
        mkdir($lockDir, 0o700, true);

        $runId = 'coherence-run';
        $locks = new LockFactory(new FlockStore($lockDir));
        $runLock = new RunLockManager($locks, ttlSeconds: 10.0, acquireTimeoutSeconds: 3.0);
        $store = new CacheHistoryProjectionStore(
            pool: new FilesystemAdapter(namespace: 'hist', defaultLifetime: 0, directory: $cacheDir),
            lockFactory: $locks,
            projector: new HistoryProjector(),
            runLockManager: $runLock,
        );
        $store->initializeFromEvents($runId, [$this->runStarted($runId, seq: 1)]);
        $this->assertTrue($store->get($runId)->ready);

        return [$cacheDir, $lockDir, $runId, $store];
    }

    /**
     * @param list<string> $command
     */
    private function startOwnedProcess(array $command): Process
    {
        $process = new Process(
            $command,
            env: ['HATFIELD_SESSION_ID' => false],
            timeout: 5,
        );
        $this->ownedProcesses[] = $process;
        $process->start();

        return $process;
    }

    private function waitUntil(callable $predicate, Process $owned, string $failureMessage, float $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($predicate()) {
                return;
            }
            if (!$owned->isRunning() && !$predicate()) {
                $this->fail($failureMessage.' (owned process exited early: '.$owned->getErrorOutput().$owned->getOutput().')');
            }
            usleep(5_000);
        }

        $this->fail($failureMessage);
    }

    private static function conflictLogShowsTransitionContention(string $conflictLog, string $runId): bool
    {
        if (!is_file($conflictLog)) {
            return false;
        }

        $needle = 'agent_loop.run.'.$runId;
        foreach (file($conflictLog, \FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if ('' === $line) {
                continue;
            }
            $row = json_decode($line, true);
            if (!\is_array($row)) {
                continue;
            }
            if (($row['resource'] ?? null) === $needle
                && str_contains((string) ($row['message'] ?? ''), 'Failed to acquire')
            ) {
                return true;
            }
        }

        return false;
    }

    private function runStarted(string $runId, int $seq): RunEvent
    {
        return new RunEvent(
            runId: $runId,
            seq: $seq,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => 'You are a scout.',
                    'messages' => [],
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => 'parent-1',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_abc123',
                            'interactive' => false,
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => [
                            'allowed_tools' => ['bash'],
                            'mcp' => [
                                'mode' => 'none',
                                'tools' => [],
                            ],
                        ],
                        'extensions' => [],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        );
    }
}
