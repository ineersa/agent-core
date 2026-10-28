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
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;

final class ProcessStoreTest extends IsolatedKernelTestCase
{
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
