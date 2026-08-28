<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\RunMetrics;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Entity\RunOperationalHumanInputDTO;
use Ineersa\CodingAgent\Entity\RunOperationalProjectionDTO;
use Ineersa\CodingAgent\Entity\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Entity\RunOperationalToolCallDTO;
use Ineersa\CodingAgent\Messenger\RunControlSessionOwnerWorkerLifecycleSubscriber;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * Thesis: the dedicated run_control worker acquires exclusive session ownership
 * before its owner-scoped disposable projection cleanup, and releases it only
 * when that worker stops. The real kernel repository proves database effects;
 * direct lifecycle events prove the Messenger ordering without a process race.
 */
final class RunControlSessionOwnerWorkerLifecycleSubscriberTest extends IsolatedKernelTestCase
{
    private RunOperationalProjectionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get('test.run_operational_projection_repository');
    }

    public function testRunControlStartCleansOnlyItsOwnerParentChildAndDependencies(): void
    {
        $this->repository->replaceStateToolCallsAndHumanInputs($this->projection('parent-a', 'session-a'), [], []);
        $this->repository->replaceStateToolCallsAndHumanInputs(
            $this->projection('child-a', 'session-a'),
            [new RunOperationalToolCallDTO('batch-a', 'tool-a', 0, 'pending', 1)],
            [new RunOperationalHumanInputDTO('question-a', 0, 'tool_call', 'tool-a', 'waiting')],
        );
        $this->repository->replaceStateToolCallsAndHumanInputs($this->projection('parent-b', 'session-b'), [], []);

        $subscriber = $this->subscriber('session-a', new LockFactory(new InMemoryStore()));
        $subscriber->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));

        $this->assertNull($this->repository->findOperationalStatus('parent-a'));
        $this->assertNull($this->repository->findOperationalStatus('child-a'));
        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('parent-b')?->status);
        $connection = self::getContainer()->get(Connection::class);
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM run_operational_tool_call WHERE run_id = ?', ['child-a']));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM run_operational_human_input WHERE run_id = ?', ['child-a']));
    }

    public function testNonDedicatedRunControlWorkerNeverAcquiresOrCleans(): void
    {
        $this->repository->replaceStateToolCallsAndHumanInputs($this->projection('parent-a', 'session-a'), [], []);
        $subscriber = $this->subscriber('session-a', new LockFactory(new InMemoryStore()));

        $subscriber->onWorkerStarted(new WorkerStartedEvent($this->worker('tool')));
        $subscriber->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control', 'tool')));

        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('parent-a')?->status);
    }

    public function testConflictingOwnerFailsBeforeCleanup(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $first = $this->subscriber('session-a', $factory);
        $first->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));
        $this->repository->replaceStateToolCallsAndHumanInputs($this->projection('recreated-a', 'session-a'), [], []);

        $metrics = new RunMetrics();
        $second = new RunControlSessionOwnerWorkerLifecycleSubscriber($this->repository, $factory, new TestLogger(), 'session-a', $metrics);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already owned');
        try {
            $second->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));
        } finally {
            $this->assertSame(
                RunStatus::Running,
                $this->repository->findOperationalStatus('recreated-a')?->status,
                'A competing worker must fail before it clears the current owner projection.',
            );
            $first->onWorkerStopped(new WorkerStoppedEvent($this->worker('run_control')));
            $this->assertSame(1, $metrics->snapshot()['run_control_owner']['fence_conflicts']);
        }
    }

    public function testOrderlyStopReleasesOwnerFenceForReplacementCleanup(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $first = $this->subscriber('session-a', $factory);
        $first->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));
        $first->onWorkerStopped(new WorkerStoppedEvent($this->worker('run_control')));
        $this->repository->replaceStateToolCallsAndHumanInputs($this->projection('recreated-a', 'session-a'), [], []);

        $replacement = $this->subscriber('session-a', $factory);
        $replacement->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));

        $this->assertNull($this->repository->findOperationalStatus('recreated-a'));
    }

    public function testCleanupFailureReleasesFenceAndPreventsReplacementFromBeingBlocked(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())->method('transactional')->willThrowException(new \RuntimeException('database unavailable'));
        $failing = new RunControlSessionOwnerWorkerLifecycleSubscriber(
            new RunOperationalProjectionRepository($connection),
            $factory,
            new TestLogger(),
            'session-a',
        );

        try {
            $failing->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));
            $this->fail('Cleanup failure must abort worker startup.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('database unavailable', $exception->getMessage());
        }

        $replacement = $this->subscriber('session-a', $factory);
        $replacement->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));
        $replacement->onWorkerStopped(new WorkerStoppedEvent($this->worker('run_control')));
        $this->assertTrue(true, 'A cleanup failure must release its acquired session fence.');
    }

    public function testRunControlWithoutStableSessionFailsBeforeCleanup(): void
    {
        $this->repository->replaceStateToolCallsAndHumanInputs($this->projection('parent-a', 'session-a'), [], []);
        $subscriber = $this->subscriber('unknown', new LockFactory(new InMemoryStore()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stable HATFIELD_SESSION_ID');
        try {
            $subscriber->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));
        } finally {
            $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('parent-a')?->status);
        }
    }

    private function subscriber(string $sessionId, LockFactory $lockFactory): RunControlSessionOwnerWorkerLifecycleSubscriber
    {
        return new RunControlSessionOwnerWorkerLifecycleSubscriber($this->repository, $lockFactory, new TestLogger(), $sessionId);
    }

    private function worker(string ...$transports): Worker
    {
        $receivers = [];
        foreach ($transports as $transport) {
            $receivers[$transport] = new InMemoryTransport();
        }
        $worker = new Worker($receivers, new TestMessageBus());
        $worker->getMetadata()->set(['transportNames' => $transports]);

        return $worker;
    }

    private function projection(string $runId, string $ownerSessionId): RunOperationalProjectionDTO
    {
        return new RunOperationalProjectionDTO($runId, $ownerSessionId, RunStatus::Running, 0, null, null, null, null, false, 0, 0, 0);
    }
}
