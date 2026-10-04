<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Runtime\Messenger\ToolPendingDeliverySubscriber;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionToolBatchStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class ToolPendingDeliverySubscriberTest extends PerMethodIsolatedKernelTestCase
{
    public function testStartupAndIdleRecoverLostArmedDeliveryAndOriginalResult(): void
    {
        $call = $this->prepare();
        $command = new TestMessageBus();
        $execution = new TestMessageBus();
        $subscriber = $this->subscriber($call->runId(), $command, $execution);
        $worker = new Worker(['run_control' => new InMemoryTransport()], new TestMessageBus());
        $subscriber->onStarted(new WorkerStartedEvent($worker));
        $this->assertCount(1, $execution->messages);
        $this->assertEquals($call, $execution->messages[0]);
        $gate = self::getContainer()->get(ToolExecutionAuthorization::class);
        $claim = $gate->claim($call);
        $this->assertIsString($claim);
        $result = new ToolCallResult($call->runId(), 1, 'tools', 1, 'invocation', 'read-1', 0, 'original durable result');
        $gate->saveResult($call, $claim, $result);
        // One tick finishes this run, the next starts a new bounded sweep.
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $this->assertCount(1, $command->messages);
        $this->assertEquals($result, $command->messages[0]);
        $this->assertCount(1, $execution->messages);
    }

    public function testOverwrittenInvocationIsNotReauthorized(): void
    {
        $call = $this->prepare();
        $store = self::getContainer()->get(ToolBatchStoreInterface::class);
        $batch = $store->load($call->runId(), 1, 'tools');
        $this->assertNotNull($batch);
        $batch->calls['read-1'] = new ExecuteToolCall($call->runId(), 1, 'tools', 2, 'new invocation', 'read-1', 'read', ['path' => 'different'], 0);
        $store->save($call->runId(), 1, 'tools', $batch);
        $command = new TestMessageBus();
        $execution = new TestMessageBus();
        $this->subscriber($call->runId(), $command, $execution)->onStarted(new WorkerStartedEvent(new Worker(['run_control' => new InMemoryTransport()], new TestMessageBus())));
        $this->assertSame([], $execution->messages);
        $this->assertSame([], $command->messages);
    }

    public function testUnfinishedTransitionPreventsPublication(): void
    {
        $call = $this->prepare();
        self::getContainer()->get(PreparedTransitionEventStoreInterface::class)->appendTransition([], ['run_id' => $call->runId(), 'predecessor_seq' => 0, 'effects' => [$call]]);
        $command = new TestMessageBus();
        $execution = new TestMessageBus();
        $this->subscriber($call->runId(), $command, $execution)->onStarted(new WorkerStartedEvent(new Worker(['run_control' => new InMemoryTransport()], new TestMessageBus())));
        $this->assertSame([], $execution->messages);
        $this->assertSame([], $command->messages);
    }

    private function prepare(): ExecuteToolCall
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('tool rediscovery');
        $call = new ExecuteToolCall($run, 1, 'tools', 1, 'invocation', 'read-1', 'read', ['path' => 'fixture'], 0);
        (new ToolBatchCollector(store: $container->get(ToolBatchStoreInterface::class)))->registerExpectedBatch($run, 1, 'tools', [$call]);
        $container->get(ToolExecutionAuthorization::class)->arm($call);

        return $call;
    }

    private function subscriber(string $run, TestMessageBus $command, TestMessageBus $execution): ToolPendingDeliverySubscriber
    {
        $container = self::getContainer();

        return new ToolPendingDeliverySubscriber($container->get(Connection::class), $container->get(SessionToolBatchStore::class), $container->get(ToolExecutionAuthorization::class), $container->get(PreparedTransitionEventStoreInterface::class), $command, $execution, $run, new TestLogger());
    }
}
