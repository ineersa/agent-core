<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Messenger\RunControlSessionOwnerWorkerLifecycleSubscriber;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/** Current fence behaviour; controller-start ownership cleanup replaces this subscriber in the next runtime slice. */
final class RunControlSessionOwnerWorkerLifecycleSubscriberTest extends IsolatedKernelTestCase
{
    private RunOperationalProjectionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = self::getContainer()->get('test.run_operational_projection_repository');
    }

    public function testRunControlStartCleansOnlyItsPersistedOwnerProjection(): void
    {
        $this->repository->replace(new RunState('session-a', RunStatus::Running));
        $this->repository->replace(new RunState('session-b', RunStatus::Running));

        $this->subscriber('session-a')->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));

        $this->assertNull($this->repository->findOperationalStatus('session-a'));
        $this->assertSame(RunStatus::Running, $this->repository->findOperationalStatus('session-b')?->status);
    }

    public function testRunControlWithoutStableSessionFailsBeforeCleanup(): void
    {
        $this->repository->replace(new RunState('session-a', RunStatus::Running));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('stable HATFIELD_SESSION_ID');
        $this->subscriber('unknown')->onWorkerStarted(new WorkerStartedEvent($this->worker('run_control')));
    }

    private function subscriber(string $sessionId): RunControlSessionOwnerWorkerLifecycleSubscriber
    {
        return new RunControlSessionOwnerWorkerLifecycleSubscriber($this->repository, new LockFactory(new InMemoryStore()), new TestLogger(), $sessionId);
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
}
