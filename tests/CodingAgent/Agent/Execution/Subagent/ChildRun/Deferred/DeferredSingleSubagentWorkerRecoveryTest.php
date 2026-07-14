<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Execution\DeferredSingleSubagentLaunchStatusEnum;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSingleSubagentRunControlWorkerStartedSubscriber;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\RecoverDeferredSingleSubagentLifecycleMessage;
use Ineersa\CodingAgent\Entity\DeferredSingleSubagentLaunchRepository;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

#[Group('db')]
final class DeferredSingleSubagentWorkerRecoveryTest extends IsolatedKernelTestCase
{
    public function testFindRecoverableIncludesReservedPostDispatchRows(): void
    {
        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $parent = 'parent-reserved-'.bin2hex(random_bytes(2));
        $deadline = new \DateTimeImmutable('+300 seconds');
        $repo->reserve($parent, 1, 'tool-res', 0, 'child-res', 'agent_ffffffffffffffff', 'worker', 'reserved', null, $deadline);

        $rows = $repo->findRecoverableByParentRunId($parent);
        $this->assertCount(1, $rows);
        $this->assertSame(DeferredSingleSubagentLaunchStatusEnum::Reserved, $rows[0]->launchStatus);
    }

    public function testRunControlWorkerStartEnqueuesRecoveryForSessionScopedRows(): void
    {
        /** @var DeferredSingleSubagentLaunchRepository $repo */
        $repo = self::getContainer()->get(DeferredSingleSubagentLaunchRepository::class);
        $sessionId = 'parent-worker-'.bin2hex(random_bytes(2));
        $deadline = new \DateTimeImmutable('+300 seconds');
        $launch = $repo->reserve($sessionId, 1, 'tool-worker', 0, 'child-worker', 'agent_1111111111111111', 'worker', 'worker', null, $deadline);
        $repo->markLaunched($sessionId, 'tool-worker', new \DateTimeImmutable());

        $bus = new TestMessageBus();
        $subscriber = new DeferredSingleSubagentRunControlWorkerStartedSubscriber(
            $repo,
            self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class),
            $bus,
            $sessionId,
        );

        $worker = new Worker(['run_control' => new InMemoryTransport()], $bus);
        $worker->getMetadata()->set(['transportNames' => ['run_control']]);
        $subscriber(new WorkerStartedEvent($worker));

        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(RecoverDeferredSingleSubagentLifecycleMessage::class, $bus->messages[0]);
        $this->assertSame($launch->lifecycleId, $bus->messages[0]->lifecycleId);
    }
}
