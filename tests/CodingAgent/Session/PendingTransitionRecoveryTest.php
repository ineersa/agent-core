<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

final class PendingTransitionRecoveryTest extends IsolatedKernelTestCase
{
    public static function executionKinds(): iterable
    {
        yield 'LLM' => ['llm'];
        yield 'compaction' => ['compaction'];
        yield 'shell' => ['shell'];
    }

    #[DataProvider('executionKinds')]
    public function testRecoveryArmsOriginalSerializedEffectAndRunningClaimCannotBeRepeated(string $kind): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('pending execution');
        $request = $this->request($kind, $run);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = $container->get(ExecutionOperationStoreInterface::class);
        // Leave finalization unfinished after arming. Recovery must reuse
        // this generation, not replace it with a newly authorized execution.
        $original = $operations->arm($request, $pending);
        $dispatched = null;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $message, array $stamps) use (&$dispatched): Envelope {
            return $dispatched = new Envelope($message, $stamps);
        });
        $this->recovery($bus)->recover($run);
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertInstanceOf(Envelope::class, $dispatched);
        $this->assertInstanceOf(ExecutionRequest::class, $dispatched->getMessage());
        $this->assertEquals($original, $dispatched->last(ExecutionAuthorizationStamp::class));
        $serializer = $container->get('messenger.transport.native_php_serializer');
        $this->assertInstanceOf(SerializerInterface::class, $serializer);
        $restored = $serializer->decode($serializer->encode($dispatched));
        $stamp = $restored->last(ExecutionAuthorizationStamp::class);
        $this->assertInstanceOf(ExecutionAuthorizationStamp::class, $stamp);
        $delivery = $restored->getMessage();
        $this->assertInstanceOf(ExecutionRequest::class, $delivery);
        $claim = $operations->claim($delivery, $stamp);
        $this->assertIsString($claim);
        $this->assertEquals($request, $operations->resolveRequest($delivery, $stamp, $claim));
        $this->assertNull($operations->claim($delivery, $original));
        $this->recovery($bus)->recover($run);
    }

    public static function dispositions(): iterable
    {
        foreach (['llm', 'compaction', 'shell'] as $kind) {
            foreach (['Consumed', 'Stale'] as $disposition) {
                yield $kind.' '.$disposition => [$kind, $disposition];
            }
        }
    }

    #[DataProvider('dispositions')]
    public function testZeroEventDispositionRecoveryPersistsOriginalResultDecision(string $kind, string $decision): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('pending result decision');
        $request = $this->request($kind, $run);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations = $container->get(ExecutionOperationStoreInterface::class);
        $authorization = $operations->arm($request, $pending);
        $store->finalizeVerifiedTransition($run, $pending->identity);
        $delivery = $operations->requestReference($request, $authorization);
        $claim = $operations->claim($delivery, $authorization);
        $this->assertIsString($claim);
        $result = $this->executionResult($kind, $request);
        $reference = $operations->saveResult($request, $authorization, $claim, $result);
        $this->assertEquals($reference, $operations->claim($delivery, $authorization), 'Redelivery reuses the durable original result.');
        $descriptor = new ExecutionResultDispositionDTO($reference, $decision);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'execution_disposition' => $descriptor]);
        $this->assertFalse($operations->isDisposed($reference));
        $bus = new TestMessageBus();
        $this->recovery($bus)->recover($run);
        $this->assertTrue($operations->isDisposed($reference));
        $this->assertEquals($result, $operations->resolveResult($reference));
        $this->assertEquals($reference, $operations->resultForClaim($delivery, $authorization, $claim), 'Fast owner consumption must not invalidate acknowledgement of an already durable result.');
        $this->assertNull($operations->claim($delivery, $authorization));
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertNull($store->latestSequenceFor($run), 'A stale decision must not invent canonical events.');
        $this->recovery($bus)->recover($run);
        $this->assertSame([], $bus->messages);
    }

    public static function mailboxCrashBoundaries(): iterable
    {
        yield 'after append' => [false];
        yield 'after mailbox finalization' => [true];
    }

    #[DataProvider('mailboxCrashBoundaries')]
    public function testMailboxPreparationAndRecoveryPreserveOnePendingCommand(bool $interruptAfterFinalization): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('mailbox recovery');
        $commands = $container->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class);
        $handler = $container->get(\Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler::class);
        $message = new \Ineersa\AgentCore\Domain\Message\ApplyCommand($run, 1, 'input', 1, 'original-mailbox-command', 'follow_up', ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'original input']]]]);
        $state = new \Ineersa\AgentCore\Domain\Run\RunState(runId: $run, status: \Ineersa\AgentCore\Domain\Run\RunStatus::Running, turnNo: 1);
        $result = $handler->handle($message, $state);
        $this->assertFalse($commands->has($run, $message->idempotencyKey()), 'Failure before intent must not reserve or consume the source command.');
        $this->assertSame([], $commands->pending($run));
        $retry = $handler->handle($message, $state);
        $this->assertEquals($result->postCommitActions, $retry->postCommitActions);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition($result->events, ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => $result->postCommitActions]);
        $this->assertFalse($commands->has($run, $message->idempotencyKey()));
        $bus = $container->get('agent.command.bus');
        if ($interruptAfterFinalization) {
            $failure = $this->createMock(MessageBusInterface::class);
            $failure->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $action, array $stamps = []) use ($bus): Envelope {
                $bus->dispatch($action, $stamps);
                throw new \RuntimeException('injected mailbox finalization interruption');
            });
            try {
                $this->recovery($failure)->recover($run);
                $this->fail('The injected finalization failure must leave pending work.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('injected mailbox finalization interruption', $exception->getMessage());
            }
            $this->assertCount(1, $commands->pending($run));
            $this->assertNotNull($store->verifiedPendingTransition($run));
            $this->assertNull($store->latestSequenceFor($run), 'The pending suffix remains hidden until finalization.');
        }
        $this->recovery($bus)->recover($run);
        $this->assertNull($store->verifiedPendingTransition($run));
        $pending = $commands->pending($run);
        $this->assertCount(1, $pending);
        $this->assertSame($message->idempotencyKey(), $pending[0]->idempotencyKey);
        $this->assertSame($message->payload, $pending[0]->payload);
        $this->assertSame(1, $store->latestSequenceFor($run));
        $this->recovery($bus)->recover($run);
        $this->assertEquals($pending, $commands->pending($run));
    }

    public function testUnsupportedActionIsRejectedBeforeAnyMailboxFinalization(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('unsupported coordination');
        $commands = $container->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [new \Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO($run, 'must-not-apply'), new \stdClass()]]);
        try {
            $this->recovery($container->get('agent.command.bus'))->recover($run);
            $this->fail('Unsupported work must not finalize.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Owner transition requires coordination recovery for unsupported action.', $exception->getMessage());
        }
        $this->assertFalse($commands->has($run, 'must-not-apply'));
        $this->assertNotNull($store->verifiedPendingTransition($run));
    }

    public function testWarmRegistryIsReleasedWhenGatedDeliveryFailsAfterFinalization(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('warm registry invalidation');
        $request = $this->request('llm', $run);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $registry = $container->get(ActiveRunContextInterface::class);
        $registry->loadRecovered(new \Ineersa\AgentCore\Domain\Run\RunState(runId: $run, status: \Ineersa\AgentCore\Domain\Run\RunStatus::Running, turnNo: 1));
        $this->assertSame($run, $registry->requireLoaded($run)->runId);

        $failure = $this->createMock(MessageBusInterface::class);
        $failure->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('injected gated delivery failure'));
        try {
            $this->recovery($failure)->recover($run);
            $this->fail('Gated delivery failure must surface after finalization.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected gated delivery failure', $exception->getMessage());
        }

        $this->assertNull($store->verifiedPendingTransition($run));
        $this->expectException(\Ineersa\AgentCore\Contract\RunContextNotLoadedException::class);
        $registry->requireLoaded($run);
    }

    public static function mailboxDecisions(): iterable
    {
        yield 'applied' => [false];
        yield 'rejected' => [true];
    }

    #[DataProvider('mailboxDecisions')]
    public function testInterruptedMailboxDrainRetainsFifoCutoffAndFinishesDecisions(bool $rejected): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('mailbox drain recovery');
        $commands = $container->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class);
        $payload = $rejected ? [] : ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'queued input']]]];
        $commands->enqueue(new \Ineersa\AgentCore\Domain\Command\PendingCommand($run, 'steer', 'first', $payload));
        $commands->enqueue(new \Ineersa\AgentCore\Domain\Command\PendingCommand($run, 'steer', 'second', $payload));
        $state = new \Ineersa\AgentCore\Domain\Run\RunState(runId: $run, status: \Ineersa\AgentCore\Domain\Run\RunStatus::Running, turnNo: 1);
        $prepared = $container->get(\Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy::class)->applyPendingTurnStartCommands($state);
        $this->assertSame(['first', 'second'], array_column($commands->pending($run), 'idempotencyKey'));
        $events = $container->get(\Ineersa\AgentCore\Domain\Event\EventFactory::class)->eventsFromSpecs($run, 1, 1, $prepared->eventSpecs);
        $decision = \Ineersa\AgentCore\Application\Handler\CommandMailboxCoordinationFactory::finalize(new \Ineersa\AgentCore\Application\Pipeline\HandlerResult(events: $events));
        $this->assertSame(['first', 'second'], array_map(static fn ($event): string => $event->payload['idempotency_key'], $events));
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition($events, ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => $decision->postCommitActions]);
        $this->assertCount(2, $commands->pending($run));
        // This later enqueue is outside the captured cutoff and must survive recovery.
        $commands->enqueue(new \Ineersa\AgentCore\Domain\Command\PendingCommand($run, 'steer', 'later', $payload));
        $bus = $container->get('agent.command.bus');
        $failure = $this->createMock(MessageBusInterface::class);
        $failure->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $action, array $stamps = []) use ($bus): Envelope {
            $bus->dispatch($action, $stamps);
            throw new \RuntimeException('injected drain interruption');
        });
        try {
            $this->recovery($failure)->recover($run);
            $this->fail('The injected drain failure must leave the captured plan unfinished.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('injected drain interruption', $exception->getMessage());
        }
        $this->assertSame(['second', 'later'], array_column($commands->pending($run), 'idempotencyKey'));
        $this->assertNotNull($store->verifiedPendingTransition($run));
        $this->recovery($bus)->recover($run);
        $this->assertSame(['later'], array_column($commands->pending($run), 'idempotencyKey'));
        $this->assertTrue($commands->has($run, 'first'));
        $this->assertTrue($commands->has($run, 'second'));
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertSame(2, $store->latestSequenceFor($run));
        $this->recovery($bus)->recover($run);
        $this->assertSame(['later'], array_column($commands->pending($run), 'idempotencyKey'));
    }

    public static function registeredClaimStates(): iterable
    {
        yield 'Running' => [false];
        yield 'ResultReady' => [true];
    }

    #[DataProvider('registeredClaimStates')]
    public function testRegisterBatchRecoveryPreservesClaimAndOriginalResult(bool $resultReady): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('batch coordination recovery');
        $call = new \Ineersa\AgentCore\Domain\Message\ExecuteToolCall($run, 1, 'tools', 1, 'original-tool', 'call', 'read', ['path' => 'fixture'], 0);
        $action = new \Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO($run, 1, 'tools', [$call]);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$action]]);
        $bus = $container->get('agent.command.bus');
        $bus->dispatch($action);
        $authorization = $container->get(\Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization::class);
        $claim = $authorization->claim($call);
        $this->assertIsString($claim);
        $result = new ToolCallResult($run, 1, 'tools', 1, 'original-tool', 'call', 0, ['content' => [['type' => 'text', 'text' => 'original saved result']]]);
        if ($resultReady) {
            $authorization->saveResult($call, $claim, $result);
        }
        $batches = $container->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class);
        $before = $batches->load($run, 1, 'tools');
        $this->recovery($bus)->recover($run);
        $after = $batches->load($run, 1, 'tools');
        $this->assertEquals($before->executionAuthorizations, $after->executionAuthorizations);
        $this->assertEquals($before->executionResults, $after->executionResults);
        $this->assertEquals($resultReady ? $result : null, $authorization->claim($call));
        $this->assertNull($store->verifiedPendingTransition($run));
    }

    private function recovery(MessageBusInterface $bus): PendingTransitionRecovery
    {
        $container = self::getContainer();

        return new PendingTransitionRecovery($container->get(PreparedTransitionEventStoreInterface::class), $container->get(ActiveRunContextInterface::class), new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()), new \Ineersa\AgentCore\Application\Pipeline\TransitionFinalizer($container->get(PreparedTransitionEventStoreInterface::class), $container->get(ToolExecutionAuthorizationInterface::class), $container->get(ExecutionOperationStoreInterface::class), new StepDispatcher($bus, $bus), new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore())), $container->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class));
    }

    private function request(string $kind, string $run): AbstractAgentBusMessage
    {
        return match ($kind) {
            'llm' => new ExecuteLlmStep($run, 1, 'execution', 1, 'original-request', 'tools'),
            'compaction' => new ExecuteCompactionStep($run, 1, 'execution', 1, 'original-request', 'test/model', [], [], [], 0, 0, 0, 0, 'manual'),
            'shell' => new ExecuteShellToolCall($run, 1, 'execution', 1, 'shell-call', 'printf safe', true),
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
