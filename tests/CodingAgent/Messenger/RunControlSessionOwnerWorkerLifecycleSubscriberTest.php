<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Domain\Run\HumanInputContinuationKindEnum;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Entity\RunOperationalHumanInput;
use Ineersa\CodingAgent\Entity\RunOperationalState;
use Ineersa\CodingAgent\Entity\RunOperationalToolCall;
use Ineersa\CodingAgent\Messenger\RunControlSessionOwnerWorkerLifecycleSubscriber;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Event\WorkerStoppedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/** Proves the dedicated run_control process fences session-local projection ownership before polling. */
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
        $this->repository->replace($this->projection('parent-a', 'session-a'));
        $child = $this->projection('child-a', 'session-a');
        $child->addToolCall(new RunOperationalToolCall($child, 'batch-a', 'tool-a', 0, RunOperationalToolCallStatusEnum::Pending, 1));
        $child->addHumanInput(new RunOperationalHumanInput($child, 'question-a', 0, HumanInputContinuationKindEnum::ToolCall, 'tool-a'));
        $this->repository->replace($child);
        $this->repository->replace($this->projection('parent-b', 'session-b'));

        $subscriber = $this->subscriber('session-a', new LockFactory(new InMemoryStore()));
        $subscriber->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));

        $this->assertNull($this->repository->findOperationalStatus('parent-a'));
        $this->assertNull($this->repository->findOperationalStatus('child-a'));
        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('parent-b')?->status);
        $connection = self::getContainer()->get(Connection::class);
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM run_operational_tool_call WHERE run_id = ?', ['child-a']));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM run_operational_human_input WHERE run_id = ?', ['child-a']));
    }

    public function testOnlyDedicatedRunControlConsumerCanAcquireOwnerFence(): void
    {
        $this->repository->replace($this->projection('parent-a', 'session-a'));
        $subscriber = $this->subscriber('session-a', new LockFactory(new InMemoryStore()));

        $subscriber->onWorkerStarted(new WorkerStartedEvent($this->worker('tool')));
        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('parent-a')?->status);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('dedicated Messenger consumer');
        $subscriber->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control', 'tool')));
    }

    public function testConflictingOwnerFailsBeforeCleanup(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $first = $this->subscriber('session-a', $factory);
        $first->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));
        $this->repository->replace($this->projection('recreated-a', 'session-a'));

        $second = $this->subscriber('session-a', $factory);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already owned');
        try {
            $second->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));
        } finally {
            $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('recreated-a')?->status);
            $first->onWorkerStopped(new WorkerStoppedEvent($this->worker('run_control')));
        }
    }

    public function testOrderlyStopReleasesOwnerFenceForReplacementCleanup(): void
    {
        $factory = new LockFactory(new InMemoryStore());
        $first = $this->subscriber('session-a', $factory);
        $first->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));
        $first->onWorkerStopped(new WorkerStoppedEvent($this->worker('run_control')));
        $this->repository->replace($this->projection('recreated-a', 'session-a'));

        $replacement = $this->subscriber('session-a', $factory);
        $replacement->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));

        $this->assertNull($this->repository->findOperationalStatus('recreated-a'));
    }

    public function testRunControlWithoutStableSessionFailsBeforeCleanup(): void
    {
        $this->repository->replace($this->projection('parent-a', 'session-a'));
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

    private function projection(string $runId, string $ownerSessionId): RunOperationalState
    {
        return new RunOperationalState($runId, $ownerSessionId, RunStatus::Running, 0, null, null, null, null, false, 0, 0, 0);
    }
}
