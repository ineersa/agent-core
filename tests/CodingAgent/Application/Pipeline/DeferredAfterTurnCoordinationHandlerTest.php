<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\CoordinationActionValidator;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\SourceAcceptance;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Application\Message\DeferredAfterTurnCoordinationDTO;
use Ineersa\CodingAgent\Application\Pipeline\DeferredAfterTurnCoordinationHandler;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeferredAfterTurnCoordinationHandlerTest extends IsolatedKernelTestCase
{
    public function testBindingUsesAllocatedSequencesAfterAnAbandonedPreparation(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('allocation hole');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $event = new RunEvent($run, 0, 1, 'agent_end', ['status' => 'completed']);
        $summary = new AfterTurnCommitEventSummary(0, 'agent_end', ['status' => 'completed']);
        $source = new AdvanceRun($run, 1, 'source', 1, 'source-key');
        $work = ['run_id' => $run, 'predecessor_seq' => 0, 'source' => SourceAcceptance::identity($source)];
        $mismatch = new ObserveDeferredSubagentBatchChildTurnMessage('batch', 1, $run, RunStatus::Completed, 1, [$summary, $summary]);
        try {
            $store->appendTransition([$event], $work + ['after_turn_actions' => [new DeferredAfterTurnCoordinationDTO($run, 0, $mismatch)]]);
            $this->fail('A mismatched observation cannot publish an intent.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Child observation differs from the staged event batch.', $exception->getMessage());
        }
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertNull($store->latestSequenceFor($run));
        $message = new ObserveDeferredSubagentBatchChildTurnMessage('batch', 1, $run, RunStatus::Completed, 1, [$summary]);
        $store->appendTransition([$event], $work + ['after_turn_actions' => [new DeferredAfterTurnCoordinationDTO($run, 0, $message)]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $this->assertSame([2], $pending->eventSequences);
        $this->assertSame(2, $pending->work['after_turn_actions'][0]->message->committedEvents[0]->seq);
        $this->assertSame(0, $pending->work['after_turn_actions'][0]->message->predecessorSequence);
        $container->get(PendingTransitionRecovery::class)->recover($run);
        $this->assertSame(2, $store->latestSequenceFor($run));
        $this->assertTrue($container->get(SourceAcceptance::class)->alreadyAccepted($source));
    }

    public static function failureBoundaries(): iterable
    {
        foreach (['child observation', 'parent cancellation', 'automatic compaction', 'context reminder'] as $kind) {
            yield $kind.' before delivery' => [false, $kind];
            yield $kind.' lost acknowledgement' => [true, $kind];
        }
    }

    #[DataProvider('failureBoundaries')]
    public function testRecoveryRepeatsCapturedAfterTurnActionsWithoutPrematureAcceptance(bool $accepted, string $kind): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('hook recovery');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $source = new AdvanceRun($run, 1, 'source', 1, 'source-key');
        $acceptance = $container->get(SourceAcceptance::class);
        $message = new ObserveDeferredSubagentBatchChildTurnMessage('batch', 1, $run, RunStatus::Completed, 1, [new AfterTurnCommitEventSummary(0, 'agent_end', ['status' => 'completed'])]);
        $action = match ($kind) {
            'parent cancellation' => new DeferredAfterTurnCoordinationDTO($run, 0, new \Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\InterruptDeferredSubagentBatchMessage('batch', \Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum::ParentCancelled)),
            'automatic compaction' => new \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO(new \Ineersa\AgentCore\Domain\Message\CompactRun($run, 1, 'compact-prepared', 1, 'prepared-key', trigger: 'auto'), 'delivery failed'),
            'context reminder' => new \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO(new \Ineersa\AgentCore\Domain\Message\ApplyCommand($run, 0, \Symfony\Component\Uid\Uuid::v7()->toRfc4122(), 1, 'prepared-reminder-key', \Ineersa\AgentCore\Domain\Command\CoreCommandKind::AppendMessage, ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'prepared reminder']], 'metadata' => ['system_reminder' => true]]]), 'delivery failed'),
            default => new DeferredAfterTurnCoordinationDTO($run, 0, $message),
        };
        $sender = new class($accepted) implements MessageBusInterface {
            public bool $fail = true;
            public array $deliveries = [];

            public function __construct(private bool $accepted)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if (!$this->fail || $this->accepted) {
                    $this->deliveries[] = $message;
                }
                if ($this->fail) {
                    throw new \RuntimeException('delivery acknowledgement unavailable');
                }

                return new Envelope($message, $stamps);
            }
        };
        $handler = new DeferredAfterTurnCoordinationHandler($store, $sender);
        $coreHandler = new \Ineersa\AgentCore\Application\Handler\CoordinationActionHandler($sender);
        $bus = new class($handler, $coreHandler, $sender) implements MessageBusInterface {
            public function __construct(
                private DeferredAfterTurnCoordinationHandler $handler,
                private \Ineersa\AgentCore\Application\Handler\CoordinationActionHandler $coreHandler,
                private MessageBusInterface $sender,
            ) {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                if ($message instanceof \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO) {
                    $this->coreHandler->dispatchMessage($message);
                } elseif ($message instanceof DeferredAfterTurnCoordinationDTO) {
                    ($this->handler)($message);
                } else {
                    // DurablePendingPublication stores and re-dispatches the inner control payload.
                    $this->sender->dispatch($message, $stamps);
                }

                return new Envelope($message, $stamps);
            }
        };
        $provider = new class($action) implements \Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface {
            public int $preparations = 0;

            public function __construct(private object $action)
            {
            }

            public function prepareAfterTurnCommit(\Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext $context, int $predecessorSequence): array
            {
                ++$this->preparations;

                return [$this->action];
            }
        };
        $observer = new class implements \Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface {
            public int $observations = 0;

            public function handleAfterTurnCommit(\Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext $context): \Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext
            {
                ++$this->observations;

                return $context;
            }
        };
        $registry = $container->get(ActiveRunContextInterface::class);
        $previous = \Ineersa\AgentCore\Domain\Run\RunState::queued($run);
        $registry->loadRecovered($previous);
        $dispatcher = new StepDispatcher($bus, $container->get('agent.execution.bus'));
        $controlOutbox = $container->get(\Ineersa\AgentCore\Contract\ControlMessageOutboxInterface::class);
        $finalizer = TestTransitionFinalizerFactory::create(
            $store,
            $dispatcher,
            operations: $container->get(ExecutionOperationStoreInterface::class),
            batches: $container->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
            commands: $container->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class),
            commandBus: $bus,
            executionBus: $container->get('agent.execution.bus'),
            outbox: $controlOutbox,
        );
        $commit = new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
            activeRunContext: $registry,
            eventStore: $store,
            logger: new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            executionOperations: $container->get(ExecutionOperationStoreInterface::class),
            sourceAcceptance: $acceptance,
            finalizer: $finalizer,
            hookDispatcher: new \Ineersa\AgentCore\Application\Handler\HookDispatcher([$provider, $observer]),
            actionValidator: $container->get(CoordinationActionValidator::class),
        );
        $isControlOutboxAction = $action instanceof \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
        if ($isControlOutboxAction) {
            // Control obligations leave the owner lock through DurablePendingPublication.
            // A broker failure retains the durable outbox row instead of throwing from commit.
            $commit->commit($previous, $previous->with(['status' => RunStatus::Completed, 'turnNo' => 1]), [new RunEvent($run, 0, 1, 'agent_end', ['status' => 'completed'])], sourceIdentity: SourceAcceptance::identity($source));
            $this->assertNull($store->verifiedPendingTransition($run));
            $this->assertTrue($acceptance->alreadyAccepted($source));
            $this->assertSame(1, $store->latestSequenceFor($run));
            $this->assertSame(1, $provider->preparations);
            $this->assertSame(1, $observer->observations, 'Cut publication finishes before after-lock broker send.');
            $this->assertCount($accepted ? 1 : 0, $sender->deliveries, 'Lost acknowledgement records the attempt before retention.');
            $pendingControl = $controlOutbox->pendingForRun($run);
            $this->assertCount(1, $pendingControl);
            $captured = $action;
            $this->assertSame(serialize($captured->message), serialize($pendingControl[0]->payload instanceof \Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO ? $pendingControl[0]->payload->message : $pendingControl[0]->payload));
            $sender->fail = false;
            $publication = new \Ineersa\AgentCore\Application\Pipeline\DurablePendingPublication(
                $store,
                $container->get(ExecutionOperationStoreInterface::class),
                $controlOutbox,
                $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
                $bus,
                $container->get('agent.execution.bus'),
                new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            );
            $publication->publishControlOutbox($run);
            $this->assertCount($accepted ? 2 : 1, $sender->deliveries);
            foreach ($sender->deliveries as $delivery) {
                $this->assertSame(serialize($captured->message), serialize($delivery));
            }
            $this->assertSame([], $controlOutbox->pendingForRun($run));

            return;
        }

        try {
            $commit->commit($previous, $previous->with(['status' => RunStatus::Completed, 'turnNo' => 1]), [new RunEvent($run, 0, 1, 'agent_end', ['status' => 'completed'])], sourceIdentity: SourceAcceptance::identity($source));
            $this->fail('An uncertain delivery must retain the transition.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('delivery acknowledgement unavailable', $exception->getMessage());
        }
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $captured = $pending->work['after_turn_actions'][0];
        if ($captured->message instanceof ObserveDeferredSubagentBatchChildTurnMessage) {
            $this->assertSame($pending->eventSequences, array_map(static fn ($event): int => $event->seq, $captured->message->committedEvents));
        }
        $this->assertFalse($acceptance->alreadyAccepted($source));
        $this->assertNull($store->latestSequenceFor($run));
        $this->assertSame($pending->identity, $store->verifiedPendingTransition($run)->identity);
        $this->assertSame(1, $provider->preparations);
        $this->assertSame(0, $observer->observations, 'Cleanup and observers cannot precede required delivery.');
        $sender->fail = false;
        $recovery = new PendingTransitionRecovery(
            $store,
            $container->get(ActiveRunContextInterface::class),
            $acceptance,
            TestTransitionFinalizerFactory::create(
                $store,
                new StepDispatcher($bus, $container->get('agent.execution.bus')),
                operations: $container->get(ExecutionOperationStoreInterface::class),
                batches: $container->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class),
                commands: $container->get(\Ineersa\AgentCore\Contract\CommandStoreInterface::class),
                commandBus: $bus,
                executionBus: $container->get('agent.execution.bus'),
                outbox: $controlOutbox,
            ),
            $container->get(CoordinationActionValidator::class),
        );
        $recovery->recover($run);
        $this->assertSame(1, $provider->preparations, 'Recovery must not repeat hook decisions.');
        $this->assertTrue($acceptance->alreadyAccepted($source));
        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertCount($accepted ? 2 : 1, $sender->deliveries);
        foreach ($sender->deliveries as $delivery) {
            $this->assertSame(serialize($captured->message), serialize($delivery));
            if ($delivery instanceof ObserveDeferredSubagentBatchChildTurnMessage) {
                $this->assertSame(0, $delivery->predecessorSequence);
            }
        }
        $this->assertSame(1, $store->latestSequenceFor($run));
    }
}
