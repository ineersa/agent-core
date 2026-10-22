<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BackgroundProcessProvisionalCleanupTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;
use Symfony\Component\Uid\Uuid;

#[CoversClass(BackgroundProcessProvisionalCleanupTask::class)]
#[CoversClass(BackgroundProcessManager::class)]
final class BackgroundProcessProvisionalCleanupTaskTest extends IsolatedKernelTestCase
{
    private const string OWNER = 'maintenance-owner';

    private string $tmpDir;
    private ProcessStore $store;
    private BackgroundProcessManager $manager;
    private ProcessLifecycle $lifecycle;
    private BackgroundProcessProvisionalCleanupTask $task;
    private TestLogger $logger;
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
        $this->lifecycle = new ProcessLifecycle($config, $this->logger);
        $this->manager = new BackgroundProcessManager(
            $this->store,
            $this->lifecycle,
            $config,
            $this->logger,
        );
        $this->task = new BackgroundProcessProvisionalCleanupTask(
            $this->manager, $this->store, $this->lifecycle, $this->logger,
            static::getContainer()->get(OwnedRunIdsProvider::class), self::OWNER,
        );
    }

    protected function tearDown(): void
    {
        Clock::set($this->originalClock);
        TestDirectoryIsolation::removeDirectory($this->tmpDir);

        parent::tearDown();
    }

    #[Test]
    public function scheduledRefreshDoesNotSignalAcceptedTimeoutCleanup(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertIsResource($server);
        $address = stream_socket_get_name($server, false);
        $this->assertIsString($address);
        $script = $this->tmpDir.'/barrier.php';
        file_put_contents($script, <<<'PHP'
<?php
$socket = stream_socket_client('tcp://'.$argv[1]);
stream_set_timeout($socket, 3);
if ('result' === $argv[2]) {
    fwrite($socket, 'exit:'.$argv[3]."\n");
    exit(0);
}
fwrite($socket, $argv[2].':'.getmypid()."\n");
if ('release' !== trim((string) fgets($socket))) {
    exit(1);
}
fwrite($socket, "cleaned\n");
PHP);
        $config = new BackgroundProcessConfig(storageDir: $this->tmpDir, stopGraceSeconds: 5);
        $owner = new BackgroundProcessManager($this->store, $this->lifecycle, $config, $this->logger);
        $otherKernel = new Kernel('test', true);
        $running = null;
        $cleanup = null;
        $result = null;
        try {
            $otherKernel->boot();
            $container = $otherKernel->getContainer()->get('test.service_container');
            $this->assertNotSame(static::getContainer()->get(EntityManagerInterface::class), $container->get(EntityManagerInterface::class));
            $otherStore = $container->get(ProcessStore::class);
            $maintenance = new BackgroundProcessManager($otherStore, $this->lifecycle, $config, $this->logger);
            $task = new BackgroundProcessProvisionalCleanupTask(
                $maintenance, $otherStore, $this->lifecycle, $this->logger,
                $container->get(OwnedRunIdsProvider::class), 'accepted-cleanup',
            );
            $invoke = escapeshellarg(\PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($address);
            // The cleanup child is spawned only after the first TERM. Unlike
            // its shell parent, it has default TERM handling, so a second group
            // signal kills it while this test holds its release at the socket.
            $command = 'trap '.escapeshellarg($invoke.' cleanup; result=$?; '.$invoke.' result "$result"; exit "$result"').' TERM; '.$invoke.' running';
            $process = $owner->start($command, 'accepted-cleanup', 'timeout-call', hrtime(true) - 1);
            $owner->markBackgroundedForRecord($process->id, 'accepted-cleanup');
            $running = stream_socket_accept($server, 3);
            $this->assertIsResource($running, 'The owned command must reach its initial IPC barrier.');
            stream_set_timeout($running, 3);
            $this->assertStringStartsWith('running:', (string) fgets($running));
            $owner->refreshEntities($this->store->fetchAllUnfinished(['accepted-cleanup']));
            $cleanup = stream_socket_accept($server, 3);
            $this->assertIsResource($cleanup, 'TERM must start the cleanup subprocess.');
            stream_set_timeout($cleanup, 3);
            $ready = (string) fgets($cleanup);
            $this->assertStringStartsWith('cleanup:', $ready);
            $record = $this->store->fetchByRecordId($process->id);
            $this->assertNotNull($record);
            $this->assertSame($record->pgid, posix_getpgid((int) substr($ready, 8)));
            $this->assertSame(BackgroundProcessStatusEnum::Stopped, $record->status);
            $this->assertNull($record->finishedAt);

            $task();

            // No elapsed-time window: cleanup cannot finish until this release.
            @fwrite($cleanup, "release\n");
            $this->assertSame("cleaned\n", fgets($cleanup), 'Scheduled maintenance must not kill the accepted job\'s TERM cleanup.');
            $result = stream_socket_accept($server, 3);
            $this->assertIsResource($result, 'The command must collect its cleanup subprocess exit.');
            stream_set_timeout($result, 3);
            $this->assertSame("exit:0\n", fgets($result));
            $this->assertTrue($otherStore->existsByRecordId($process->id));
        } finally {
            foreach ([$running, $cleanup, $result, $server] as $socket) {
                if (\is_resource($socket)) {
                    fclose($socket);
                }
            }
            $owner->shutdownCleanup();
            $otherKernel->shutdown();
        }
    }

    #[Test]
    public function schedulerRegistrationRunsEveryFiveMinutes(): void
    {
        $attributes = (new \ReflectionClass(BackgroundProcessProvisionalCleanupTask::class))
            ->getAttributes(AsPeriodicTask::class);

        $this->assertCount(1, $attributes);
        $task = $attributes[0]->newInstance();
        $this->assertSame(BackgroundProcessProvisionalCleanupTask::INTERVAL_SECONDS, $task->frequency);
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
    public function handlerDeletesOldProvisionalRowWhenConfiguredStorageDirectoryIsAlreadyMissing(): void
    {
        $missingStorageDir = $this->tmpDir.'/missing-storage';
        $this->rebuildTask($missingStorageDir);
        $logPath = $missingStorageDir.'/vanished.log';
        $old = $this->createFinishedRecord($logPath, $missingStorageDir.'/vanished.status', $this->now->modify('-6 minutes'));
        $outside = $this->tmpDir.'/outside.log';
        file_put_contents($outside, 'unrelated');

        ($this->task)();

        $this->assertFalse($this->store->existsByRecordId($old));
        $this->assertFileExists($outside);
    }

    #[Test]
    public function handlerRefreshesPrivateExitBeforeItsRetentionPeriodExpires(): void
    {
        $private = $this->createRunningRecord('private-exit');
        file_put_contents($private['status'], '0');

        ($this->task)();

        $record = $this->store->fetchByRecordId($private['id']);
        $this->assertNotNull($record);
        $this->assertSame(BackgroundProcessStatusEnum::Finished, $record->status);
        $this->assertEquals($this->now, $record->finishedAt);
        $this->assertFileExists($private['log']);
        Clock::set(new MockClock($this->now->modify('+6 minutes')));

        ($this->task)();

        $this->assertFalse($this->store->existsByRecordId($private['id']));
        $this->assertFileDoesNotExist($private['log']);
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
    public function handlerRefreshesOwnedChildrenWithoutResolvingOrCleaningForeignProcesses(): void
    {
        $childRunId = Uuid::v7()->toRfc4122();
        static::getContainer()->get(AgentArtifactRegistry::class)->create(
            self::OWNER, 'agent_owned', $childRunId, 'scout', AgentArtifactKindEnum::Subagent,
        );
        $child = $this->createRunningRecord('owned-child', $childRunId);
        $foreign = $this->createRunningRecord('foreign', 'other-controller');
        $foreignFinished = $this->createRecord('foreign-finished', $this->now->modify('-6 minutes'), sessionId: 'other-controller');
        // Local PID absence must not finish a process owned by another controller.
        foreach ([$child, $foreign] as $paths) {
            $record = $this->store->fetchByRecordId($paths['id']);
            $this->assertNotNull($record);
            $record->pid = 2147483647;
        }
        $this->store->flush();

        ($this->task)();

        $this->assertSame(BackgroundProcessStatusEnum::FinishedUnclean, $this->store->fetchByRecordId($child['id'])?->status);
        $record = $this->store->fetchByRecordId($foreign['id']);
        $this->assertNotNull($record);
        $this->assertSame(BackgroundProcessStatusEnum::Running, $record->status);
        $this->assertNull($record->finishedAt);
        $this->assertTrue($this->store->existsByRecordId($foreignFinished['id']));
        foreach (['log', 'status', 'pid'] as $sidecar) {
            $this->assertFileExists($foreignFinished[$sidecar]);
        }
    }

    #[Test]
    #[TestWith([''])]
    #[TestWith(['unknown'])]
    public function missingOwnerNeverEnablesAGlobalSweep(string $sessionId): void
    {
        $old = $this->createRecord('unscoped', $this->now->modify('-6 minutes'));
        $private = $this->createRunningRecord('unscoped-running');
        $record = $this->store->fetchByRecordId($private['id']);
        $this->assertNotNull($record);
        $record->pid = 2147483647;
        $this->store->flush();
        $task = new BackgroundProcessProvisionalCleanupTask(
            $this->manager, $this->store, $this->lifecycle, $this->logger,
            static::getContainer()->get(OwnedRunIdsProvider::class), $sessionId,
        );

        $task();

        $this->assertTrue($this->store->existsByRecordId($old['id']));
        $this->assertFileExists($old['log']);
        $this->assertSame(BackgroundProcessStatusEnum::Running, $this->store->fetchByRecordId($private['id'])?->status);
    }

    #[Test]
    public function aggregateCleanupLogDoesNotExposeRecordData(): void
    {
        $this->createRecord('private', $this->now->modify('-6 minutes'), command: 'secret command /not/a/path 987654');

        ($this->task)();

        $records = array_values(array_filter(
            $this->logger->records,
            static fn (array $record): bool => 'background_process.provisional_cleanup_completed' === $record['message'],
        ));
        $this->assertCount(1, $records);
        $this->assertSame(['component', 'event_type', 'run_id', 'session_id', 'cleaned_count', 'failed_count'], array_keys($records[0]['context']));
        $this->assertSame(1, $records[0]['context']['cleaned_count']);
        $this->assertSame(0, $records[0]['context']['failed_count']);
    }

    /**
     * @return array{id: int, log: string, status: string, pid: string}
     */
    private function createRecord(string $prefix, \DateTimeImmutable $finishedAt, bool $accepted = false, string $command = 'fixture', string $sessionId = self::OWNER): array
    {
        $paths = $this->createSidecars($prefix);
        $id = $this->store->insertRecord([
            'pid' => 100000 + hexdec(substr(hash('crc32b', $prefix), 0, 4)),
            'pgid' => null,
            'session_id' => $sessionId,
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
            $this->manager->markBackgroundedForRecord($id, $sessionId);
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
        $this->task = new BackgroundProcessProvisionalCleanupTask(
            $this->manager, $this->store, $this->lifecycle, $this->logger,
            static::getContainer()->get(OwnedRunIdsProvider::class), self::OWNER,
        );
    }

    private function createFinishedRecord(string $logPath, string $statusPath, \DateTimeImmutable $finishedAt): int
    {
        $id = $this->store->insertRecord([
            'pid' => 100000 + hexdec(substr(hash('crc32b', $logPath), 0, 4)),
            'pgid' => null,
            'session_id' => self::OWNER,
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

    private function createRunningRecord(string $prefix, string $sessionId = self::OWNER): array
    {
        $paths = $this->createSidecars($prefix, includeStatus: false);
        $id = $this->store->insertRecord([
            'pid' => (int) getmypid(),
            'pgid' => null,
            'session_id' => $sessionId,
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
        $base = ($directory ?? $this->tmpDir).'/'.$prefix;
        file_put_contents($base.'.log', 'output');
        if ($includeStatus) {
            file_put_contents($base.'.status', '0');
        }
        file_put_contents($base.'.pid', (string) getmypid());

        return ['log' => $base.'.log', 'status' => $base.'.status', 'pid' => $base.'.pid'];
    }
}
