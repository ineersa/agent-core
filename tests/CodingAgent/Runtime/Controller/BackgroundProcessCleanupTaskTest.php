<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Controller\BackgroundProcessCleanupTask;
use Ineersa\CodingAgent\Runtime\Controller\ControllerSessionOwnerLockStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[CoversClass(BackgroundProcessCleanupTask::class)]
#[CoversClass(BackgroundProcessManager::class)]
final class BackgroundProcessCleanupTaskTest extends IsolatedKernelTestCase
{
    private string $tmpDir;
    private ProcessStore $store;
    private BackgroundProcessManager $manager;
    private ProcessLifecycle $lifecycle;
    private BackgroundProcessCleanupTask $task;
    private TestLogger $logger;
    private LockFactory $ownerLocks;
    private \DateTimeImmutable $now;
    private \Symfony\Component\Clock\ClockInterface $originalClock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('bg-provisional-cleanup');
        $this->originalClock = Clock::get();
        $this->now = new \DateTimeImmutable('2026-08-27 14:30:00');
        Clock::set(new MockClock($this->now));

        $config = new BackgroundProcessConfig(storageDir: $this->tmpDir);
        $this->store = static::getContainer()->get(ProcessStore::class);
        $this->logger = new TestLogger();
        $this->ownerLocks = new LockFactory(new ControllerSessionOwnerLockStore($this->tmpDir.'/controller-locks'));
        $this->lifecycle = new ProcessLifecycle($config, $this->logger);
        $this->manager = new BackgroundProcessManager(
            $this->store,
            $this->lifecycle,
            $config,
            $this->logger,
        );
        $this->task = new BackgroundProcessCleanupTask($this->manager, $this->store, $this->lifecycle, $this->logger, static::getContainer()->get(RunOperationalProjectionRepository::class), $this->ownerLocks, $this->tmpDir);
    }

    protected function tearDown(): void
    {
        Clock::set($this->originalClock);
        TestDirectoryIsolation::removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    #[Test]
    public function schedulerRegistrationRunsEveryFiveMinutes(): void
    {
        $attributes = (new \ReflectionClass(BackgroundProcessCleanupTask::class))
            ->getAttributes(AsPeriodicTask::class);

        $this->assertCount(1, $attributes);
        $task = $attributes[0]->newInstance();
        $this->assertSame(BackgroundProcessCleanupTask::INTERVAL_SECONDS, $task->frequency);
        $this->assertSame('default', $task->schedule);
    }

    #[Test]
    public function handlerCleansOnlyOldFinishedProvisionalExactSidecars(): void
    {
        $old = $this->createRecord('old', $this->now->modify('-6 minutes'));
        $accepted = $this->createRecord('accepted', $this->now->modify('-6 minutes'), accepted: true);
        $neighbor = $this->tmpDir.'/neighbor.log';
        file_put_contents($neighbor, 'unrelated');

        ($this->task)();

        $this->assertFalse($this->store->existsByRecordId($old['id']));
        $this->assertFileDoesNotExist($old['log']);
        $this->assertFileDoesNotExist($old['status']);
        $this->assertFileDoesNotExist($old['pid']);
        $this->assertTrue($this->store->existsByRecordId($accepted['id']));
        $this->assertFileExists($accepted['log']);
        $this->assertFileExists($neighbor);
    }

    #[Test]
    public function handlerCleansSymlinkedStorageDirectoryAndLeavesOutsideFileUntouched(): void
    {
        $targetDir = $this->tmpDir.'/storage-target';
        mkdir($targetDir);
        $symlinkedStorageDir = $this->tmpDir.'/storage-link';
        symlink($targetDir, $symlinkedStorageDir);
        $this->rebuildTask($symlinkedStorageDir);

        $paths = $this->createSidecars('symlinked', $targetDir);
        $old = $this->createFinishedRecord($paths['log'], $paths['status'], $this->now->modify('-6 minutes'));
        $outside = $this->tmpDir.'/outside.log';
        file_put_contents($outside, 'unrelated');

        try {
            ($this->task)();

            $this->assertFalse($this->store->existsByRecordId($old));
            $this->assertFileDoesNotExist($paths['log']);
            $this->assertFileDoesNotExist($paths['status']);
            $this->assertFileDoesNotExist($paths['pid']);
            $this->assertFileExists($outside);
        } finally {
            unlink($symlinkedStorageDir);
        }
    }

    #[Test]
    public function handlerRetainsUnprovenFlatRowWhenConfiguredStorageDirectoryIsAlreadyMissing(): void
    {
        $missingStorageDir = $this->tmpDir.'/missing-storage';
        $this->rebuildTask($missingStorageDir);
        $logPath = $missingStorageDir.'/vanished.log';
        $old = $this->createFinishedRecord($logPath, $missingStorageDir.'/vanished.status', $this->now->modify('-6 minutes'));
        $outside = $this->tmpDir.'/outside.log';
        file_put_contents($outside, 'unrelated');

        ($this->task)();

        $this->assertTrue($this->store->existsByRecordId($old), 'Missing storage does not establish last-writer ownership for a flat row.');
        $this->assertFileExists($outside);
    }

    #[Test]
    public function handlerRetainsRunningAndGracePeriodProvisionalRows(): void
    {
        $running = $this->createRunningRecord('running');
        $recent = $this->createRecord('recent', $this->now->modify('-4 minutes'));

        ($this->task)();

        $this->assertTrue($this->store->existsByRecordId($running['id']));
        $this->assertFileExists($running['log']);
        $this->assertTrue($this->store->existsByRecordId($recent['id']));
        $this->assertFileExists($recent['log']);
    }

    #[Test]
    public function handlerIsIdempotentForMissingAndPartialExactSidecars(): void
    {
        $partial = $this->createRecord('partial', $this->now->modify('-6 minutes'));
        unlink($partial['log']);
        unlink($partial['pid']);

        ($this->task)();
        ($this->task)();

        $this->assertFalse($this->store->existsByRecordId($partial['id']));
        $this->assertFileDoesNotExist($partial['status']);
    }

    #[Test]
    public function aggregateCleanupLogDoesNotExposeRecordData(): void
    {
        $this->createRecord('private', $this->now->modify('-6 minutes'), command: 'secret command /not/a/path 987654');

        ($this->task)();

        $records = array_values(array_filter(
            $this->logger->records,
            static fn (array $record): bool => 'background_process.cleanup_completed' === $record['message'],
        ));
        $this->assertCount(1, $records);
        $this->assertSame(['run_id', 'session_id', 'component', 'event_type', 'cleaned_count', 'failed_count'], array_keys($records[0]['context']));
        $this->assertSame(1, $records[0]['context']['cleaned_count']);
        $this->assertSame(0, $records[0]['context']['failed_count']);
    }

    #[Test]
    public function managedLaunchWithoutPidIsProtectedUntilProducerStops(): void
    {
        $launch = $this->lifecycle->allocateManagedLaunch('launching');
        $directory = \dirname($launch->logPath);
        $this->assertFileExists($directory.'/owner.json');
        $this->assertFileDoesNotExist($launch->pidPath);
        ($this->task)();
        $this->assertDirectoryExists($directory, 'No PID or database row is not proof of abandonment.');
        unset($launch);
        ($this->task)();
        ($this->task)();
        $this->assertDirectoryDoesNotExist($directory);
    }

    #[Test]
    public function managedAcceptedChildrenUseActualParentOwnerAndUnknownOwnersRemain(): void
    {
        $projection = static::getContainer()->get(RunOperationalProjectionRepository::class);
        $projection->replace(new RunState('child-one', RunStatus::Running, parentRunId: 'parent'));
        $projection->replace(new RunState('child-two', RunStatus::Running, parentRunId: 'parent'));
        $first = $this->createManagedRecord('child-one', accepted: true);
        $second = $this->createManagedRecord('child-two', accepted: true);
        $unknown = $this->createManagedRecord('unknown', accepted: true);
        $owner = $this->ownerLocks->createLock(ControllerSessionOwnerLockStore::resourceForSession($this->tmpDir, 'parent'), ttl: null);
        $this->assertTrue($owner->acquire(false));
        try {
            ($this->task)();
            foreach ([$first, $second, $unknown] as $record) {
                $this->assertTrue($this->store->existsByRecordId($record['id']));
                $this->assertFileExists($record['log']);
            }
        } finally {
            $owner->release();
        }
        ($this->task)();
        ($this->task)();
        foreach ([$first, $second] as $record) {
            $this->assertFalse($this->store->existsByRecordId($record['id']));
            $this->assertDirectoryDoesNotExist(\dirname($record['log']));
        }
        $this->assertTrue($this->store->existsByRecordId($unknown['id']));
        $this->assertFileExists($unknown['log']);
    }

    #[Test]
    public function managedRecoveryPreservesGracePeriodAndInvalidOrLegacyScopes(): void
    {
        $old = $this->createManagedRecord('old-managed');
        $recent = $this->createManagedRecord('recent-managed', minutes: 4);
        $invalid = $this->lifecycle->allocateManagedLaunch('invalid');
        $invalidDirectory = \dirname($invalid->logPath);
        file_put_contents($invalidDirectory.'/owner.json', '{"nonce":"invalid"}');
        unset($invalid);
        $linked = $this->lifecycle->allocateManagedLaunch('linked');
        $linkedDirectory = \dirname($linked->logPath);
        $outside = $this->tmpDir.'/outside';
        file_put_contents($outside, 'unrelated');
        symlink($outside, $linked->statusPath);
        unset($linked);
        $legacy = $this->tmpDir.'/0123456789abcdef.status';
        file_put_contents($legacy, '0');
        $legacyLog = $this->tmpDir.'/0123456789abcdef.log';
        file_put_contents($legacyLog, 'legacy');
        $legacyId = $this->createFinishedRecord($legacyLog, $legacy, $this->now->modify('-6 minutes'));
        ($this->task)();
        $this->assertFalse($this->store->existsByRecordId($old['id']));
        $this->assertDirectoryDoesNotExist(\dirname($old['log']));
        $this->assertTrue($this->store->existsByRecordId($recent['id']));
        $this->assertFileExists($recent['log']);
        $this->assertDirectoryExists($invalidDirectory);
        $this->assertDirectoryExists($linkedDirectory);
        $this->assertSame('unrelated', file_get_contents($outside));
        $this->assertFileExists($legacy);
        $this->assertTrue($this->store->existsByRecordId($legacyId), 'A flat row has no last-writer lease proof.');
    }

    /** @return array{id: int, log: string, status: string} */
    private function createManagedRecord(string $sessionId, bool $accepted = false, int $minutes = 6): array
    {
        $launch = $this->lifecycle->allocateManagedLaunch($sessionId);
        file_put_contents($launch->logPath, 'fixture');
        file_put_contents($launch->statusPath, '0');
        $finishedAt = $this->now->modify('-'.$minutes.' minutes');
        $id = $this->store->insertRecord([
            'pid' => 999999,
            'session_id' => $sessionId,
            'command' => 'fixture',
            'log_path' => $launch->logPath,
            'status_path' => $launch->statusPath,
            'started_at' => $finishedAt,
        ]);
        $entity = $this->store->fetchByRecordId($id);
        $this->assertNotNull($entity);
        $entity->finish(0, $finishedAt);
        $this->store->flush();
        if ($accepted) {
            $this->manager->markBackgroundedForRecord($id, $sessionId);
        }

        return ['id' => $id, 'log' => $launch->logPath, 'status' => $launch->statusPath];
    }

    /**
     * @return array{id: int, log: string, status: string, pid: string}
     */
    private function createRecord(string $prefix, \DateTimeImmutable $finishedAt, bool $accepted = false, string $command = 'fixture'): array
    {
        $paths = $this->createSidecars($prefix);
        $id = $this->store->insertRecord([
            'pid' => 100000 + hexdec(substr(hash('crc32b', $prefix), 0, 4)),
            'pgid' => null,
            'session_id' => 'run-'.$prefix,
            'command' => $command,
            'log_path' => $paths['log'],
            'status_path' => $paths['status'],
            'started_at' => $finishedAt->modify('-1 minute'),
        ]);
        $entity = $this->store->fetchByRecordId($id);
        $this->assertNotNull($entity);
        $entity->finish(0, $finishedAt);
        $this->store->flush();
        if ($accepted) {
            $this->manager->markBackgroundedForRecord($id, 'run-'.$prefix);
        }

        return ['id' => $id] + $paths;
    }

    private function rebuildTask(string $storageDir): void
    {
        $config = new BackgroundProcessConfig(storageDir: $storageDir);
        $this->lifecycle = new ProcessLifecycle($config, $this->logger);
        $this->manager = new BackgroundProcessManager(
            $this->store,
            $this->lifecycle,
            $config,
            $this->logger,
        );
        $this->task = new BackgroundProcessCleanupTask($this->manager, $this->store, $this->lifecycle, $this->logger, static::getContainer()->get(RunOperationalProjectionRepository::class), $this->ownerLocks, $this->tmpDir);
    }

    private function createFinishedRecord(string $logPath, string $statusPath, \DateTimeImmutable $finishedAt): int
    {
        $id = $this->store->insertRecord([
            'pid' => 100000 + hexdec(substr(hash('crc32b', $logPath), 0, 4)),
            'pgid' => null,
            'session_id' => 'run-fixture',
            'command' => 'fixture',
            'log_path' => $logPath,
            'status_path' => $statusPath,
            'started_at' => $finishedAt->modify('-1 minute'),
        ]);
        $entity = $this->store->fetchByRecordId($id);
        $this->assertNotNull($entity);
        $entity->finish(0, $finishedAt);
        $this->store->flush();

        return $id;
    }

    private function createRunningRecord(string $prefix): array
    {
        $paths = $this->createSidecars($prefix, includeStatus: false);
        $id = $this->store->insertRecord([
            'pid' => (int) getmypid(),
            'pgid' => null,
            'session_id' => 'run-'.$prefix,
            'command' => 'fixture',
            'log_path' => $paths['log'],
            'status_path' => $paths['status'],
            'started_at' => $this->now,
        ]);

        return ['id' => $id] + $paths;
    }

    /**
     * @return array{log: string, status: string, pid: string}
     */
    private function createSidecars(string $prefix, ?string $directory = null, bool $includeStatus = true): array
    {
        $lifecycle = new ProcessLifecycle(new BackgroundProcessConfig(storageDir: $directory ?? $this->tmpDir), $this->logger);
        $launch = $lifecycle->allocateManagedLaunch('run-'.$prefix);
        file_put_contents($launch->logPath, 'output');
        if ($includeStatus) {
            file_put_contents($launch->statusPath, '0');
        }
        file_put_contents($launch->pidPath, (string) getmypid());

        return ['log' => $launch->logPath, 'status' => $launch->statusPath, 'pid' => $launch->pidPath];
    }
}
