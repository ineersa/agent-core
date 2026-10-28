<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\BackgroundProcessFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;

final class ProcessStoreTest extends IsolatedKernelTestCase
{
    public function testCleanupRechecksFinishedScopeClassificationAndRetention(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $store = self::getContainer()->get(ProcessStore::class);
        $lifecycle = self::getContainer()->get(ProcessLifecycle::class);
        $record = new BackgroundProcess();
        $record->sessionId = 'cleanup-owner';
        $record->pid = getmypid();
        $manager->persist($record);
        $manager->flush();
        $before = $manager->getUnitOfWork()->size();
        $this->assertNull($store->decide($record->id, 'cleanup-owner', BashProcessActionEnum::DeleteFinished, $lifecycle));
        BackgroundProcessFixture::finish($record, 0, new \DateTimeImmutable());
        $manager->flush();
        $this->assertNull($store->decide($record->id, 'foreign-owner', BashProcessActionEnum::DeleteFinished, $lifecycle));
        $this->assertNull($store->decide($record->id, 'cleanup-owner', BashProcessActionEnum::DeleteProvisional, $lifecycle));
        $manager->getConnection()->executeStatement('UPDATE background_process SET finished_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $record->id]);
        $this->assertCount(1, $store->fetchFinishedProvisionalBefore(new \DateTimeImmutable(), ['cleanup-owner']));
        $this->assertTrue($store->acceptLocal($record->id, 'cleanup-owner'));
        $this->assertNull($store->decide($record->id, 'cleanup-owner', BashProcessActionEnum::DeleteProvisional, $lifecycle), 'An old foreground candidate is not authority after acceptance.');
        $this->assertCount(0, $store->fetchFinishedProvisionalBefore(new \DateTimeImmutable(), ['cleanup-owner']));
        $this->assertNotNull($store->decide($record->id, 'cleanup-owner', BashProcessActionEnum::DeleteFinished, $lifecycle));
        $this->assertNull($store->decide($record->id, 'cleanup-owner', BashProcessActionEnum::DeleteFinished, $lifecycle), 'Metadata redelivery cannot delete another row.');
        $this->assertSame($before, $manager->getUnitOfWork()->size());
    }

    public function testCleanupWriteRefusalCanRetryWithoutClosingTheManager(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $store = self::getContainer()->get(ProcessStore::class);
        $lifecycle = self::getContainer()->get(ProcessLifecycle::class);
        $record = new BackgroundProcess();
        $record->sessionId = 'cleanup-retry';
        BackgroundProcessFixture::finish($record, 0, new \DateTimeImmutable('2000-01-01'));
        $manager->persist($record);
        $manager->flush();
        $connection = $manager->getConnection();
        $connection->executeStatement("CREATE TEMP TRIGGER refuse_cleanup BEFORE DELETE ON background_process BEGIN SELECT RAISE(ABORT, 'cleanup refused'); END");
        try {
            try {
                $store->decide($record->id, 'cleanup-retry', BashProcessActionEnum::DeleteProvisional, $lifecycle);
                $this->fail('Expected the database to refuse cleanup.');
            } catch (\Doctrine\DBAL\Exception $exception) {
                $this->assertStringContainsString('cleanup refused', $exception->getMessage());
                $this->assertNotNull($store->snapshot($record->id));
                $this->assertTrue($manager->isOpen());
            }
        } finally {
            $connection->executeStatement('DROP TRIGGER refuse_cleanup');
        }
        $this->assertNotNull($store->decide($record->id, 'cleanup-retry', BashProcessActionEnum::DeleteProvisional, $lifecycle));
        $this->assertNull($store->snapshot($record->id));
    }

    public function testMaintenanceSnapshotsObserveCommittedChangesWithoutGrowingTheIdentityMap(): void
    {
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $store = self::getContainer()->get(ProcessStore::class);
        $record = new BackgroundProcess();
        $record->sessionId = 'snapshot-maintenance';
        $record->pid = getmypid();
        $manager->persist($record);
        $manager->flush();
        $first = $store->snapshot($record->id);
        $connection = $manager->getConnection();
        $connection->executeStatement('UPDATE background_process SET command = ? WHERE id = ?', ['external change', $record->id]);
        $before = $manager->getUnitOfWork()->size();
        for ($i = 0; $i < 32; ++$i) {
            $this->assertSame('external change', $store->unfinishedSnapshots(['snapshot-maintenance'])[0]->command);
            $this->assertSame('external change', $store->snapshot($record->id)->command);
        }
        $this->assertNotSame('external change', $first->command);
        $connection->executeStatement('UPDATE background_process SET finished_at = ? WHERE id = ?', ['2000-01-01 00:00:00', $record->id]);
        $this->assertCount(0, $store->unfinishedSnapshots(['snapshot-maintenance']));
        $this->assertCount(1, $store->fetchFinishedProvisionalBefore(new \DateTimeImmutable(), ['snapshot-maintenance']));
        $this->assertSame($before, $manager->getUnitOfWork()->size());
    }

    public function testTimeoutDecisionPreservesCauseWithoutHydrationOrExplicitStopSuppression(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('bash-timeout-owner');
        try {
            $manager = self::getContainer()->get(EntityManagerInterface::class);
            $process = new BackgroundProcess();
            $process->sessionId = 'timeout-owner';
            // Owner decisions never signal this live test process.
            $process->pid = getmypid();
            $process->statusPath = $directory.'/status';
            $process->deadlineNanoseconds = hrtime(true) - 1;
            $manager->persist($process);
            $manager->flush();
            $store = self::getContainer()->get(ProcessStore::class);
            $lifecycle = self::getContainer()->get(ProcessLifecycle::class);
            $before = $manager->getUnitOfWork()->size();
            $this->assertFalse($store->decide($process->id, 'timeout-owner', BashProcessActionEnum::Timeout, $lifecycle)->signalRequired, 'Private foreground deadlines remain owned by their invocation.');
            $this->assertFalse($store->acceptLocal($process->id, 'foreign'));
            $this->assertTrue($store->acceptLocal($process->id, 'timeout-owner'));
            $acceptedAt = $store->snapshot($process->id)->backgroundedAt;
            for ($i = 0; $i < 20; ++$i) {
                $this->assertTrue($store->acceptLocal($process->id, 'timeout-owner'));
                $this->assertTrue($store->decide($process->id, 'timeout-owner', BashProcessActionEnum::Timeout, $lifecycle)->signalRequired);
                $this->assertCount(1, $store->unfinishedSnapshots(['timeout-owner']));
            }
            $record = $store->snapshot($process->id);
            $this->assertEquals($acceptedAt, $record->backgroundedAt);
            $this->assertSame(BackgroundProcessStatusEnum::Stopped, $record->status);
            $this->assertFalse($record->stoppedByUser);
            $this->assertNull($record->completionNotifiedAt);
            file_put_contents($process->statusPath, '0');
            $this->assertTrue($store->decide($process->id, 'timeout-owner', BashProcessActionEnum::Observe, $lifecycle)->finished);
            $this->assertSame(BackgroundProcessStatusEnum::Stopped, $store->snapshot($process->id)->status);
            $this->assertSame($before, $manager->getUnitOfWork()->size());
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testStopCommitsSuppressionBeforeSignalAndRecognizesReplayWithoutHydration(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('bash-stop-decision');
        try {
            $manager = self::getContainer()->get(EntityManagerInterface::class);
            $process = new BackgroundProcess();
            $process->sessionId = 'stop-owner';
            // This decision-only proof never signals. The test's live PID keeps
            // terminal classification independent of scheduler timing.
            $process->pid = getmypid();
            $process->statusPath = $directory.'/status';
            $manager->persist($process);
            $manager->flush();
            $store = self::getContainer()->get(ProcessStore::class);
            $lifecycle = self::getContainer()->get(ProcessLifecycle::class);
            $before = $manager->getUnitOfWork()->size();
            $first = $store->decide($process->id, 'stop-owner', BashProcessActionEnum::Stop, $lifecycle);
            $this->assertTrue($first->signalRequired);
            $this->assertFalse($first->finished);
            $this->assertSame(BackgroundProcessStatusEnum::Stopped, $first->status);
            $row = $manager->getConnection()->fetchAssociative('SELECT * FROM background_process WHERE id = ?', [$process->id]);
            $this->assertSame(1, (int) $row['stopped_by_user']);
            $this->assertNotNull($row['completion_notified_at']);
            for ($i = 0; $i < 32; ++$i) {
                $this->assertTrue($store->decide($process->id, 'stop-owner', BashProcessActionEnum::Stop, $lifecycle)->signalRequired, 'Reply loss must reconstruct the committed stop obligation, not assume TERM occurred.');
            }
            file_put_contents($process->statusPath, '0');
            $settled = $store->decide($process->id, 'stop-owner', BashProcessActionEnum::Observe, $lifecycle);
            $this->assertTrue($settled->finished);
            $this->assertSame(BackgroundProcessStatusEnum::Stopped, $settled->status, 'Cooperative exit cannot erase committed stop cause.');
            $this->assertTrue($store->decide($process->id, 'stop-owner', BashProcessActionEnum::SettleStop, $lifecycle)->finished);
            $this->assertSame($before, $manager->getUnitOfWork()->size());
            $this->assertNull($store->decide($process->id, 'foreign', BashProcessActionEnum::Stop, $lifecycle));
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testNaturalExitBeforeStopKeepsCompletionEligible(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('bash-natural-exit');
        try {
            $manager = self::getContainer()->get(EntityManagerInterface::class);
            $process = new BackgroundProcess();
            $process->sessionId = 'natural-owner';
            $process->pid = getmypid();
            $process->statusPath = $directory.'/status';
            $manager->persist($process);
            $manager->flush();
            file_put_contents($process->statusPath, '7');
            $result = self::getContainer()->get(ProcessStore::class)->decide($process->id, 'natural-owner', BashProcessActionEnum::Stop, self::getContainer()->get(ProcessLifecycle::class));
            $this->assertTrue($result->finished);
            $this->assertFalse($result->signalRequired);
            $this->assertSame(BackgroundProcessStatusEnum::Finished, $result->status);
            $row = $manager->getConnection()->fetchAssociative('SELECT * FROM background_process WHERE id = ?', [$process->id]);
            $this->assertSame(7, (int) $row['exit_code']);
            $this->assertNull($row['completion_notified_at']);
            $this->assertSame(0, (int) $row['stopped_by_user']);
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testOwnerSnapshotUsesRetainedSessionAndParentProjectionWithoutHydratingEntities(): void
    {
        $owner = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $projection = self::getContainer()->get('test.run_operational_projection_repository');
        $projection->replace(new RunState('child', RunStatus::Running, parentRunId: $owner));
        $projection->replace(new RunState('cycle-a', RunStatus::Running, parentRunId: 'cycle-b'));
        $projection->replace(new RunState('cycle-b', RunStatus::Running, parentRunId: 'cycle-a'));
        $store = self::getContainer()->get(ProcessStore::class);
        $manager = self::getContainer()->get(EntityManagerInterface::class);
        $before = $manager->getUnitOfWork()->size();
        for ($i = 0; $i < 32; ++$i) {
            $this->assertSame($owner, $store->ownerSessionForRun($owner));
            $this->assertSame($owner, $store->ownerSessionForRun('child'));
        }
        $this->assertNull($store->ownerSessionForRun('unknown'));
        $this->assertNull($store->ownerSessionForRun('cycle-a'));
        $this->assertSame($before, $manager->getUnitOfWork()->size());
    }
}
