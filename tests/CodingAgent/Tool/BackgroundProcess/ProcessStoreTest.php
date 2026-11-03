<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashProcessActionEnum;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\BackgroundProcessStatusEnum;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;

final class ProcessStoreTest extends IsolatedKernelTestCase
{
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
        $this->assertNotNull($store->snapshot($record->id)?->finishedAt);
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

    public function testStopCommitsCauseBeforeSignalWithoutSuppressingCompletionAndRecognizesReplayWithoutHydration(): void
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
            $this->assertNull($row['completion_notified_at'], 'Stop records cause without suppressing later completion delivery.');
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

    public function testApplyAcceptedSurvivesEarlierFinished(): void
    {
        $directory = TestDirectoryIsolation::createProjectTempDir('bash-accept-after-finish');
        try {
            $manager = self::getContainer()->get(EntityManagerInterface::class);
            $store = self::getContainer()->get(ProcessStore::class);
            $statusPath = $directory.'/launch.status';
            $process = new BackgroundProcess();
            $process->sessionId = 'accept-owner';
            $process->pid = getmypid();
            $process->statusPath = $statusPath;
            $process->toolCallId = 'call_accept';
            $manager->persist($process);
            $manager->flush();

            $running = new BackgroundProcess();
            $running->sessionId = 'accept-owner';
            $running->pid = getmypid();
            $manager->persist($running);
            $manager->flush();
            $this->assertTrue($store->acceptLocal($running->id, 'accept-owner'));
            $this->assertSame([], $store->completionSnapshots(['accept-owner']), 'Accepted running jobs are not completion candidates.');
            $finishedAt = new \DateTimeImmutable('2026-10-30 12:00:00');
            $store->applyFinishedByRecord($process->id, 'accept-owner', 0, BackgroundProcessStatusEnum::Finished, false, $finishedAt);
            $this->assertTrue($store->acceptLocal($process->id, 'accept-owner'));

            $snapshot = $store->snapshot($process->id);
            $this->assertNotNull($snapshot);
            $this->assertNotNull($snapshot->finishedAt);
            $this->assertNotNull($snapshot->backgroundedAt);
            $acceptedAt = $snapshot->backgroundedAt;
            $this->assertTrue($store->acceptLocal($process->id, 'accept-owner'));
            $this->assertEquals($acceptedAt, $store->snapshot($process->id)?->backgroundedAt);
            $this->assertCount(1, $store->completionSnapshots(['accept-owner']));
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }
}
