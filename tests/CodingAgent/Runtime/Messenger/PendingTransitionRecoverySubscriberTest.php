<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Runtime\Messenger\PendingTransitionRecoverySubscriber;
use Ineersa\CodingAgent\Tests\Support\SqlLoggingKernel;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/** Real ownership traversal/SQL; actual worker recovery is covered by PendingTransitionIdleOwnerRestartTest. */
#[CoversClass(PendingTransitionRecoverySubscriber::class)]
final class PendingTransitionRecoverySubscriberTest extends IsolatedKernelTestCase
{
    public function testSnapshotIsReusedUntilSweepBoundaryThenDiscoversNewChild(): void
    {
        $this->registerChild('b-child');
        $visited = [];
        $transitions = $this->createStub(PreparedTransitionEventStoreInterface::class);
        $transitions->method('verifiedPendingTransition')->willReturnCallback(static function (string $runId) use (&$visited): null {
            $visited[] = $runId;

            return null;
        });
        $subscriber = $this->createSubscriber($transitions, new TestLogger());
        $worker = new Worker(['run_control' => new InMemoryTransport()], new TestMessageBus());
        $this->resetSqlLog();

        $subscriber->onStarted(new WorkerStartedEvent($worker));
        $this->registerChild('c-new-child');
        $subscriber->onRunning(new WorkerRunningEvent($worker, false));
        $subscriber->onStarted(new WorkerStartedEvent(new Worker(['llm' => new InMemoryTransport()], new TestMessageBus())));
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $this->assertSame(['a-owner', 'b-child'], $visited);
        $this->assertSame(2, $this->ownershipSelectCount(), 'One ownership traversal for the two-run snapshot.');

        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $this->assertSame(['a-owner', 'b-child'], $visited, 'The existing empty boundary opportunity is preserved.');
        $this->assertSame(2, $this->ownershipSelectCount());
        for ($index = 0; $index < 3; ++$index) {
            $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        }
        $this->assertSame(['a-owner', 'b-child', 'a-owner', 'b-child', 'c-new-child'], $visited);
        $this->assertSame(5, $this->ownershipSelectCount(), 'The next sweep traverses the expanded tree exactly once.');
    }

    public function testWorkerStartDiscardsPartiallyConsumedOwnershipSnapshot(): void
    {
        $this->registerChild('c-child');
        $visited = [];
        $transitions = $this->createStub(PreparedTransitionEventStoreInterface::class);
        $transitions->method('verifiedPendingTransition')->willReturnCallback(static function (string $runId) use (&$visited): null {
            $visited[] = $runId;

            return null;
        });
        $subscriber = $this->createSubscriber($transitions, new TestLogger());
        $worker = new Worker(['run_control' => new InMemoryTransport()], new TestMessageBus());
        $this->resetSqlLog();

        $subscriber->onStarted(new WorkerStartedEvent($worker));
        $this->registerChild('b-new-child');
        $subscriber->onStarted(new WorkerStartedEvent($worker));
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));

        $this->assertSame(['a-owner', 'a-owner', 'b-new-child', 'c-child'], $visited);
        $this->assertSame(5, $this->ownershipSelectCount());
    }

    public function testFailedCandidateAdvancesAndDoesNotRebuildTheSweep(): void
    {
        $this->registerChild('b-failed');
        $this->registerChild('c-healthy');
        $visited = [];
        $transitions = $this->createStub(PreparedTransitionEventStoreInterface::class);
        $transitions->method('verifiedPendingTransition')->willReturnCallback(static function (string $runId) use (&$visited): null {
            $visited[] = $runId;
            if ('b-failed' === $runId) {
                throw new \RuntimeException('Isolated corrupt pending transition');
            }

            return null;
        });
        $logger = new TestLogger();
        $subscriber = $this->createSubscriber($transitions, $logger);
        $worker = new Worker(['run_control' => new InMemoryTransport()], new TestMessageBus());
        $this->resetSqlLog();

        $subscriber->onStarted(new WorkerStartedEvent($worker));
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $this->assertSame(['a-owner', 'b-failed', 'c-healthy'], $visited);
        $this->assertSame(3, $this->ownershipSelectCount());
        $this->assertCount(1, $logger->records);
        $this->assertSame('runtime.pending_transition_recovery_failed', $logger->records[0]['message']);
        $this->assertSame('b-failed', $logger->records[0]['context']['run_id']);
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $this->assertSame(['a-owner', 'b-failed', 'c-healthy', 'a-owner'], $visited);
        $this->assertSame(6, $this->ownershipSelectCount());
    }

    protected static function createKernel(array $options = []): Kernel
    {
        return new SqlLoggingKernel('test', false);
    }

    private function registerChild(string $runId): void
    {
        self::getContainer()->get(AgentArtifactRegistry::class)->create('a-owner', 'artifact-'.$runId, $runId, 'test', AgentArtifactKindEnum::Fork);
    }

    private function createSubscriber(PreparedTransitionEventStoreInterface $transitions, TestLogger $logger): PendingTransitionRecoverySubscriber
    {
        return new PendingTransitionRecoverySubscriber(
            $transitions,
            self::getContainer()->get(PendingTransitionRecovery::class),
            self::getContainer()->get(RunLockManager::class),
            self::getContainer()->get(OwnedRunIdsProvider::class),
            'a-owner',
            $logger,
        );
    }

    private function resetSqlLog(): void
    {
        $logger = self::getContainer()->get('test.sql_logger');
        $this->assertInstanceOf(TestLogger::class, $logger);
        $logger->records = [];
    }

    private function ownershipSelectCount(): int
    {
        $logger = self::getContainer()->get('test.sql_logger');
        $this->assertInstanceOf(TestLogger::class, $logger);

        return \count(array_filter($logger->records, static fn (array $record): bool => str_contains($record['context']['sql'] ?? '', 'SELECT child.child_run_id FROM deferred_subagent_child')));
    }
}
