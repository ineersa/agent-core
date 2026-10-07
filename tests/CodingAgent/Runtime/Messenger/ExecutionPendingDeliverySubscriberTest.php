<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Messenger\ExecutionPendingDeliverySubscriber;
use Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class ExecutionPendingDeliverySubscriberTest extends IsolatedKernelTestCase
{
    public static function kinds(): iterable
    {
        yield 'LLM' => ['llm'];
        yield 'compaction' => ['compaction'];
        yield 'shell' => ['shell'];
    }

    #[DataProvider('kinds')]
    public function testStartupRepublishesOriginalArmedIdentityThenOriginalDurableResult(string $kind): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('lost notification');
        $request = $this->request($kind, $run, 'original');
        $this->arm([$request]);
        $operations = self::getContainer()->get(DoctrineExecutionOperationStore::class);
        $execution = new TestMessageBus();
        $command = new TestMessageBus();
        $worker = $this->worker();
        $this->subscriber($run, $command, $execution)->onStarted(new WorkerStartedEvent($worker));
        $this->assertCount(1, $execution->messages);
        $envelope = $execution->messages[0];
        $this->assertInstanceOf(Envelope::class, $envelope);
        $serializer = self::getContainer()->get('messenger.transport.native_php_serializer');
        $restored = $serializer->decode($serializer->encode($envelope));
        $delivery = $restored->getMessage();
        $authorization = $restored->last(ExecutionAuthorizationStamp::class);
        $this->assertInstanceOf(ExecutionRequest::class, $delivery);
        $this->assertInstanceOf(ExecutionAuthorizationStamp::class, $authorization);
        $claim = $operations->claim($delivery, $authorization);
        $this->assertIsString($claim);
        $this->assertEquals($request, $operations->resolveRequest($delivery, $authorization, $claim));
        $this->assertNull($operations->claim($delivery, $authorization));
        $execution->messages = [];
        $this->subscriber($run, $command, $execution)->onStarted(new WorkerStartedEvent($worker));
        $this->assertSame([], $execution->messages, 'Running work is never republished for execution.');
        $result = $this->executionResult($kind, $request);
        $reference = $operations->saveResult($request, $authorization, $claim, $result);
        // No worker notification is sent. A new owner must rediscover it after
        // the transition manifest has already disappeared.
        $this->subscriber($run, $command, $execution)->onStarted(new WorkerStartedEvent($worker));
        $this->assertSame([], $execution->messages);
        $this->assertCount(1, $command->messages);
        $notification = $command->messages[0];
        $this->assertInstanceOf(Envelope::class, $notification);
        $this->assertEquals($reference, $notification->getMessage());
        $this->assertEquals($result, $operations->resolveResult($reference));
        $this->assertEquals($reference, $operations->claim($delivery, $authorization));
    }

    public function testPagesAreBoundedAndDoNotPublishOtherOwnersOrBusyWorkerTicks(): void
    {
        $sessions = self::getContainer()->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('paged pending work');
        $other = $sessions->createSession('unrelated pending work');
        $requests = [];
        for ($i = 0; $i < 33; ++$i) {
            $requests[] = $this->request('llm', $run, 'page-'.$i);
        }
        $this->arm($requests);
        $this->arm([$this->request('llm', $other, 'other')]);
        $command = new TestMessageBus();
        $execution = new TestMessageBus();
        $subscriber = $this->subscriber($run, $command, $execution);
        $worker = $this->worker();
        $subscriber->onStarted(new WorkerStartedEvent($worker));
        $this->assertCount(32, $execution->messages);
        $subscriber->onRunning(new WorkerRunningEvent($worker, false));
        $this->assertCount(32, $execution->messages);
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $this->assertCount(33, $execution->messages);
        $ids = [];
        foreach ($execution->messages as $envelope) {
            $this->assertInstanceOf(Envelope::class, $envelope);
            $message = $envelope->getMessage();
            $this->assertInstanceOf(ExecutionRequest::class, $message);
            $this->assertSame($run, $message->runId());
            $ids[] = $message->effectId;
        }
        $this->assertCount(33, array_unique($ids));
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $this->assertCount(33, $execution->messages, 'An exhausted cursor wraps without loading another page in the same tick.');
        $subscriber->onRunning(new WorkerRunningEvent($worker, true));
        $this->assertCount(65, $execution->messages, 'Still Armed identities remain discoverable.');
    }

    public function testNestedReservedChildrenAreScopedWithoutOperationalProjectionRows(): void
    {
        $container = self::getContainer();
        $root = $container->get(HatfieldSessionStore::class)->createSession('nested execution sweep');
        $child = '123e4567-e89b-12d3-a456-426614174000';
        $grandchild = '123e4567-e89b-12d3-a456-426614174001';
        foreach ([[$root, $child, 'agent_child'], [$child, $grandchild, 'agent_grandchild']] as [$parent, $run, $artifact]) {
            $container->get(AgentChildRunDirectory::class)->register(new AgentArtifactEntryDTO(
                artifactId: $artifact, parentRunId: $parent, agentRunId: $run, agentName: 'scout',
                kind: AgentArtifactKindEnum::Subagent, status: AgentArtifactStatusEnum::Pending,
                paths: AgentArtifactPathsDTO::forArtifactId($artifact), createdAt: new \DateTimeImmutable(),
            ));
            $container->get(DeferredSubagentBatchRepository::class)->reserveBatch(
                $run, $parent, 1, 'launch-'.$run, 0, ChildRunBatchExecutionModeEnum::Parallel, 1, new \DateTimeImmutable(),
                [['batchIndex' => 0, 'childRunId' => $run, 'artifactId' => $artifact, 'agentName' => 'scout', 'task' => 'safe task', 'launchModel' => 'test/model', 'launchReasoning' => 'off']],
            );
            $this->arm([$this->request('llm', $run, 'reserved')]);
        }
        $deliveries = $container->get(DoctrineExecutionOperationStore::class)->pendingDeliveries($root, '');
        $runs = array_map(static fn (Envelope $envelope): string => $envelope->getMessage()->runId(), array_values($deliveries));
        $this->assertEqualsCanonicalizing([$child, $grandchild], $runs);
        $deliveries = $container->get(DoctrineExecutionOperationStore::class)->pendingDeliveries($child, '');
        $runs = array_map(static fn (Envelope $envelope): string => $envelope->getMessage()->runId(), array_values($deliveries));
        $this->assertEqualsCanonicalizing([$child, $grandchild], $runs);
    }

    public function testUnfinalizedCoordinationAndTransportFailureRetainDurableWork(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('pending coordination');
        $request = $this->request('llm', $run, 'pending');
        $store = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        self::getContainer()->get(DoctrineExecutionOperationStore::class)->arm($request, $pending);
        $execution = new TestMessageBus();
        $command = new TestMessageBus();
        $worker = $this->worker();
        $this->subscriber($run, $command, $execution)->onStarted(new WorkerStartedEvent($worker));
        $this->assertNull($store->verifiedPendingTransition($run), 'Idle restart must finish the pending intent before publication.');
        $this->assertNotEmpty($execution->messages);
        $failing = $this->createMock(MessageBusInterface::class);
        $failing->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('transport unavailable'));
        $execution->messages = [];
        $this->subscriber($run, $command, $failing)->onStarted(new WorkerStartedEvent($worker));
        $this->subscriber($run, $command, $execution)->onStarted(new WorkerStartedEvent($worker));
        $this->assertCount(1, $execution->messages, 'Delivery failure cannot remove or disposition the Armed row.');
    }

    public function testIdleRestartRecoversPendingIntentWithoutArmedOperation(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('idle pending intent');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $request = $this->request('llm', $run, 'idle');
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $this->assertNotNull($store->verifiedPendingTransition($run));
        $this->assertSame([], $container->get(DoctrineExecutionOperationStore::class)->pendingDeliveries($run, ''));

        $execution = new TestMessageBus();
        $command = new TestMessageBus();
        $this->subscriber($run, $command, $execution)->onStarted(new WorkerStartedEvent($this->worker()));

        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertNotEmpty($execution->messages);
        $ids = [];
        foreach ($execution->messages as $envelope) {
            $this->assertInstanceOf(Envelope::class, $envelope);
            $message = $envelope->getMessage();
            $this->assertInstanceOf(ExecutionRequest::class, $message);
            $this->assertInstanceOf(ExecutionAuthorizationStamp::class, $envelope->last(ExecutionAuthorizationStamp::class));
            $ids[] = $message->effectId;
        }
        $this->assertCount(1, array_unique($ids), 'Recovery and same-tick rediscovery must reuse one Armed identity.');
    }

    /** @param list<AbstractAgentBusMessage> $requests */
    private function arm(array $requests): void
    {
        $store = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
        $run = $requests[0]->runId();
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => $requests]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = self::getContainer()->get(DoctrineExecutionOperationStore::class);
        foreach ($requests as $request) {
            $operations->arm($request, $pending);
        }
        $store->finalizeVerifiedTransition($run, $pending->identity);
    }

    private function subscriber(string $run, MessageBusInterface $command, MessageBusInterface $execution): ExecutionPendingDeliverySubscriber
    {
        $container = self::getContainer();

        return new ExecutionPendingDeliverySubscriber(
            $container->get(DoctrineExecutionOperationStore::class),
            $container->get(PreparedTransitionEventStoreInterface::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class),
            $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
            $container->get(\Doctrine\DBAL\Connection::class),
            $command,
            $execution,
            $run,
            new TestLogger(),
        );
    }

    private function worker(): Worker
    {
        return new Worker(['run_control' => new InMemoryTransport()], new TestMessageBus());
    }

    private function request(string $kind, string $run, string $step): AbstractAgentBusMessage
    {
        return match ($kind) {
            'llm' => new ExecuteLlmStep($run, 1, $step, 1, 'original-request', 'tools'),
            'compaction' => new ExecuteCompactionStep($run, 1, $step, 1, 'original-request', 'test/model', [], [], [], 0, 0, 0, 0, 'manual'),
            'shell' => new ExecuteShellToolCall($run, 1, $step, 1, 'shell-call', 'printf safe', true),
        };
    }

    private function executionResult(string $kind, AbstractAgentBusMessage $request): AbstractAgentBusMessage
    {
        $identity = [$request->runId(), $request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey()];

        return match ($kind) {
            'llm' => new LlmStepResult(...$identity),
            'compaction' => new CompactionStepResult(...[...$identity, 'original summary', null, [], 0, 0, 0, 0, 'manual']),
            'shell' => new ToolCallResult(...[...$identity, 'shell-call', 0, ['content' => [['type' => 'text', 'text' => 'original output']]]]),
        };
    }
}
