<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BackgroundProcessProvisionalCleanupTask;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[CoversClass(BackgroundProcessProvisionalCleanupTask::class)]
#[CoversClass(BackgroundProcessManager::class)]
final class BackgroundProcessProvisionalCleanupTaskTest extends IsolatedKernelTestCase
{
    private string $tmpDir;
    private ProcessStore $store;
    private BackgroundProcessManager $manager;
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
        $this->manager = new BackgroundProcessManager(
            $this->store,
            new ProcessLifecycle($config, $this->logger),
            $config,
            $this->logger,
        );
        $this->task = new BackgroundProcessProvisionalCleanupTask($this->manager);
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
        $attributes = (new \ReflectionClass(BackgroundProcessProvisionalCleanupTask::class))
            ->getAttributes(AsPeriodicTask::class);

        $this->assertCount(1, $attributes);
        $task = $attributes[0]->newInstance();
        $this->assertSame(300, $task->frequency);
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

        $this->assertNull($this->store->fetchById($old['id']));
        $this->assertFileDoesNotExist($old['log']);
        $this->assertFileDoesNotExist($old['status']);
        $this->assertFileDoesNotExist($old['pid']);
        $this->assertNotNull($this->store->fetchById($accepted['id']));
        $this->assertFileExists($accepted['log']);
        $this->assertFileExists($neighbor);
    }

    #[Test]
    public function handlerRetainsRunningAndGracePeriodProvisionalRows(): void
    {
        $running = $this->createRunningRecord('running');
        $recent = $this->createRecord('recent', $this->now->modify('-4 minutes'));

        ($this->task)();

        $this->assertNotNull($this->store->fetchById($running['id']));
        $this->assertFileExists($running['log']);
        $this->assertNotNull($this->store->fetchById($recent['id']));
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

        $this->assertNull($this->store->fetchById($partial['id']));
        $this->assertFileDoesNotExist($partial['status']);
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
        $this->assertSame(['component', 'event_type', 'cleaned_count', 'failed_count'], array_keys($records[0]['context']));
        $this->assertSame(1, $records[0]['context']['cleaned_count']);
        $this->assertSame(0, $records[0]['context']['failed_count']);
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
        $this->store->markFinished($id, 0, $finishedAt);
        if ($accepted) {
            $this->manager->markBackgroundedForRecord($id, 'run-'.$prefix);
        }

        return ['id' => $id] + $paths;
    }

    /**
     * @return array{id: int, log: string, status: string, pid: string}
     */
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
    private function createSidecars(string $prefix, bool $includeStatus = true): array
    {
        $base = $this->tmpDir.'/'.$prefix;
        file_put_contents($base.'.log', 'output');
        if ($includeStatus) {
            file_put_contents($base.'.status', '0');
        }
        file_put_contents($base.'.pid', (string) getmypid());

        return ['log' => $base.'.log', 'status' => $base.'.status', 'pid' => $base.'.pid'];
    }
}
