<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionConsumptionPolicy;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionRunHandler;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionProvenanceDTO;
use Ineersa\CodingAgent\Application\Message\DTO\BackgroundCodeModeInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\Pipeline\BackgroundCodeModeInvocationHandler;
use Ineersa\CodingAgent\Application\Pipeline\CodeModeBackgroundCoordinationHandler;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Runtime\Messenger\ToolPayloadMiddleware;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\HistoryTailDiscardService;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeCancellationToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

final class BackgroundCodeModeInvocationHandlerTest extends IsolatedKernelTestCase
{
    #[DataProvider('controllerLossCases')]
    public function testControllerLossParksOnlyOutstandingWork(string $phase): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('controller loss');
        $root = $run;
        if ('owned-child' === $phase) {
            $run = Uuid::v7()->toRfc4122();
            $entry = $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry::class)->create($root, 'owned-child', $run, 'scout', \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum::Subagent);
            $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory::class)->register($entry);
        }
        $call = new DeferredToolCompletionCorrelation(Uuid::v7()->toRfc4122(), $run, 1, 'step', 1, 'key', 'call', 'code_mode', [], 0);
        TestToolBatchRegistration::registerDeferredInvocation($container->get(ToolBatchStoreInterface::class), $call);
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $repository->registerPending($call);
        $repository->markReady($call->deferredId);
        $state = new RunState($run, RunStatus::Running, turnNo: 1, pendingToolCalls: ['call' => false], activeStepId: 'step');
        if (\in_array($phase, ['ack', 'receipt'], true)) {
            $ack = $container->get(BackgroundCodeModeInvocationHandler::class)->handle(new BackgroundCodeModeInvocationDTO($run, $call->deferredId, 'call'), $state);
            $this->admit($run, $ack->postCommitActions);
            $state = $ack->nextState;
        }
        if ('receipt' === $phase) {
            $metadata = $repository->findMetadataByDeferredId($call->deferredId);
            $delivery = new DeliverBackgroundCompletionDTO($run, $call->deferredId, 'retained', $run, $call->deferredId, $metadata->reference);
            $this->admit($run, $container->get(BackgroundCompletionRunHandler::class)->handle($delivery, $state)->postCommitActions);
        } elseif (\in_array($phase, ['cancelled', 'history_discarded'], true)) {
            $repository->interrupt($call->deferredId, $phase);
        } elseif (\in_array($phase, ['historical', 'successor'], true)) {
            $repository->markCompleted($call->deferredId);
            $container->get(\Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository::class)->replace($state->with(['turnNo' => 'historical' === $phase ? 2 : 1, 'pendingToolCalls' => []]));
        }
        self::ensureKernelShutdown();
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = self::getContainer();
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $coordinator = $container->get(CodeModeBackgroundCoordinationHandler::class);
        $coordinator->controllerStarting(new \Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent($root));
        $this->assertSame([], $container->get('messenger.transport.run_control')->getSent(), 'Passive cold startup sends no completion or successor.');
        $metadata = $repository->findMetadataByDeferredId($call->deferredId);
        $outstanding = !\in_array($phase, ['cancelled', 'history_discarded', 'historical'], true);
        $this->assertSame($outstanding, $metadata->continuationRequired);
        $this->assertSame($outstanding, $repository->runRequiresContinuation($run));
        $this->assertSame(\in_array($phase, ['cancelled', 'history_discarded'], true) ? $phase : ($outstanding ? 'controller_lost' : null), $metadata->interruptionReason);
        $this->assertSame(\in_array($phase, ['cancelled', 'history_discarded', 'historical', 'receipt', 'successor'], true) ? 'completed' : 'pending', $metadata->status);
        if (!$outstanding) {
            return;
        }
        $advance = $container->get(\Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler::class)->handle(new AdvanceRun($run, 1, 'step', 1, 'old-successor'), $state->with(['pendingToolCalls' => []]));
        $this->assertSame([], $advance->events, 'An old successor cannot wake even with an empty mailbox.');
        $this->assertSame([], $advance->postCommitEffects);
        // Authorization participates in the same metadata transaction; rollback
        // must retain both the wake fence and the sticky physical-host fence.
        $em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
        $connection = $em->getConnection();
        $connection->beginTransaction();
        $this->admit($run, [new DispatchLocalMetadataMessageDTO(new \Ineersa\CodingAgent\Application\Message\DTO\MarkCodeModeBackgroundedDTO($call->deferredId, new \DateTimeImmutable(), continuationRequired: false))]);
        $this->assertFalse($repository->runRequiresContinuation($run));
        $connection->rollBack();
        $em->clear();
        $this->assertTrue($repository->runRequiresContinuation($run));
        $this->assertSame('controller_lost', $repository->findMetadataByDeferredId($call->deferredId)->interruptionReason);
    }

    public static function controllerLossCases(): iterable
    {
        foreach (['waiting', 'ack', 'receipt', 'cancelled', 'history_discarded', 'historical', 'successor', 'owned-child'] as $phase) {
            yield $phase => [$phase];
        }
    }

    #[DataProvider('interruptions')]
    public function testCapturedInterruptionRemainsInvocationStickyAfterParentProgress(string $reason): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('sticky interruption');
        $first = new DeferredToolCompletionCorrelation(Uuid::v7()->toRfc4122(), $run, 1, 'step', 1, 'key', 'host', 'code_mode', [], 0);
        $other = new DeferredToolCompletionCorrelation(Uuid::v7()->toRfc4122(), $run, 1, 'step', 1, 'key', 'other', 'code_mode', [], 1);
        TestToolBatchRegistration::registerDeferredInvocations($container->get(ToolBatchStoreInterface::class), $first, $other);
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $handler = $container->get(BackgroundCodeModeInvocationHandler::class);
        $state = new RunState($run, RunStatus::Running, turnNo: 1, pendingToolCalls: ['host' => false, 'other' => false], activeStepId: 'step');
        foreach ([$first, $other] as $call) {
            $repository->registerPending($call);
            $repository->markReady($call->deferredId);
            $ack = $handler->handle(new BackgroundCodeModeInvocationDTO($run, $call->deferredId, $call->toolCallId), $state);
            $this->assertContains(RunEventTypeEnum::ToolCallAcknowledged->value, array_column($ack->events, 'type'));
            $this->admit($run, $ack->postCommitActions);
            $state = $ack->nextState;
        }
        $state = $state->with(['turnNo' => 2, 'activeStepId' => 'later']);
        $token = new CodeModeCancellationToken(new NullCancellationToken(), $repository, $first->deferredId);
        $otherToken = new CodeModeCancellationToken(new NullCancellationToken(), $repository, $other->deferredId);
        $this->assertFalse($token->isCancellationRequested());
        if ('stopped' === $reason) {
            $this->assertSame([], $handler->handle(new BackgroundCodeModeInvocationDTO($run, $first->deferredId, 'wrong', true), $state)->postCommitActions);
            $actions = $handler->handle(new BackgroundCodeModeInvocationDTO($run, $first->deferredId, 'host', true), $state)->postCommitActions;
        } else {
            $events = 'history_discarded' === $reason ? [new RunEvent($run, 0, 2, RunEventTypeEnum::HistoryTailDiscarded->value, [])] : [];
            $context = AfterTurnCommitHookContext::fromRunState('cancelled' === $reason ? $state->with(['status' => RunStatus::Cancelling]) : $state, $events, 0);
            $actions = $container->get(CodeModeBackgroundCoordinationHandler::class)->prepareAfterTurnCommit($context, 1);
        }
        $this->assertFalse($token->isCancellationRequested(), 'Preparation is not metadata admission.');
        $this->admit($run, $actions);
        $this->assertTrue($token->isCancellationRequested());
        $this->assertSame('stopped' !== $reason, $otherToken->isCancellationRequested());
        $this->assertSame(RunStatus::Running, $state->status);
        $this->assertSame('pending', $repository->status($first->deferredId), 'Stop is not physical completion.');
        $this->assertSame($reason, $repository->findMetadataByDeferredId($first->deferredId)->interruptionReason);
        $delivery = new DeliverBackgroundCompletionDTO($run, $first->deferredId, 'late', $run, $first->deferredId, $repository->findMetadataByDeferredId($first->deferredId)->reference);
        $this->assertFalse($container->get(HistoryTailDiscardService::class)->isContextMutatingMessage($delivery));
        $retired = $container->get(BackgroundCompletionRunHandler::class)->handle($delivery, $state);
        $this->assertSame([], $retired->events);
        $this->admit($run, $retired->postCommitActions);
        $this->assertSame('completed', $repository->status($first->deferredId));
        $this->assertTrue($token->isCancellationRequested());
    }

    public static function interruptions(): iterable
    {
        foreach (['stopped', 'cancelled', 'history_discarded'] as $reason) {
            yield $reason => [$reason];
        }
    }

    #[DataProvider('winners')]
    public function testCallerRaceAndFirstTerminalRetention(bool $ackFirst, bool $ready = true, bool $fenceReceipt = false): void
    {
        $container = self::getContainer();
        $runId = $container->get(HatfieldSessionStore::class)->createSession('code mode admission');
        $id = Uuid::v7()->toRfc4122();
        $correlation = new DeferredToolCompletionCorrelation($id, $runId, 1, 'step', 1, 'key', 'call', 'code_mode', [], 0);
        $batches = $container->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::registerDeferredInvocation($batches, $correlation);
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $repository->registerPending($correlation);
        if ($ready) {
            $repository->markReady($id);
        }
        $state = new RunState($runId, RunStatus::Running, turnNo: 1, pendingToolCalls: ['call' => false], activeStepId: 'step');
        $request = new BackgroundCodeModeInvocationDTO($runId, $id, 'call');
        $handler = $container->get(BackgroundCodeModeInvocationHandler::class);
        $this->assertSame([], $handler->handle(new BackgroundCodeModeInvocationDTO($runId, $id, 'foreign-call'), $state)->events);
        $this->assertSame([], $handler->handle($request, new RunState('foreign', RunStatus::Running, turnNo: 1, pendingToolCalls: ['call' => false], activeStepId: 'step'))->events);
        $this->assertSame([], $handler->handle($request, new RunState($runId, RunStatus::Running, turnNo: 2, pendingToolCalls: ['call' => false], activeStepId: 'step'))->events);
        if (!$ready) {
            $this->assertSame([], $handler->handle($request, $state)->events);
        }
        if ($ackFirst) {
            $ack = $handler->handle($request, $state);
            $this->assertContains(RunEventTypeEnum::ToolCallAcknowledged->value, array_column($ack->events, 'type'));
            $this->assertNotContains(RunEventTypeEnum::ToolExecutionEnd->value, array_column($ack->events, 'type'));
            $runtime = $container->get(RuntimeEventTranslator::class)->translate($ack->events[0]);
            $this->assertSame(RuntimeEventTypeEnum::ToolCallAcknowledged->value, $runtime->type);
            $this->assertTrue($runtime->payload['backgrounded']);
            $projector = new TranscriptProjector($container->get('event_dispatcher'), new TranscriptProjectionState());
            $projector->accept(new RuntimeEvent(RuntimeEventTypeEnum::ToolExecutionStarted->value, $runId, 1, ['tool_call_id' => 'call', 'tool_name' => 'code_mode']));
            $projector->accept($runtime);
            $card = array_values(array_filter($projector->blocks(), static fn ($block): bool => 'tool_result_call' === $block->id))[0];
            $this->assertTrue($card->meta['backgrounded']);
            $this->assertFalse($card->streaming);
            $replayed = $container->get(RunStateReducer::class)->replay($state, $ack->events);
            $this->assertSame($ack->nextState->pendingToolCalls, $replayed->pendingToolCalls);
            $this->admit($runId, $ack->postCommitActions);
            $this->assertSame('pending', $repository->status($id));
        }
        $result = ToolCallResultFactory::fromDeferredCorrelationAndCompletion($correlation, [['type' => 'text', 'text' => 'FIRST']], isError: !$ready);
        $bus = $container->get('agent.command.bus');
        $this->assertInstanceOf(MessageBusInterface::class, $bus);
        if (!$ackFirst) {
            $failing = new MessageBus([$container->get(ToolPayloadMiddleware::class), new HandleMessageMiddleware(new HandlersLocator([
                ToolPayloadMessage::class => [static fn () => throw new \RuntimeException('send failed after sealing')],
            ]))]);
            try {
                $failing->dispatch($result);
                $this->fail('Publication failure must escape to the caller.');
            } catch (HandlerFailedException $exception) {
                $this->assertStringContainsString('send failed after sealing', $exception->getMessage());
            }
            $this->assertNotNull($repository->findMetadataByDeferredId($id)->acceptedResultReference);
        }
        $bus->dispatch($result);
        $reference = $repository->findMetadataByDeferredId($id)->acceptedResultReference;
        $this->assertNotNull($reference);
        $this->assertSame('pending', $repository->status($id), 'Transport publication is not admission.');
        $bus->dispatch(ToolCallResultFactory::fromDeferredCorrelationAndCompletion($correlation, [['type' => 'text', 'text' => 'SECOND']]));
        $this->assertEquals($reference, $repository->findMetadataByDeferredId($id)->acceptedResultReference);
        $retained = $container->get(ToolPayloadStoreInterface::class)->read($reference);
        $this->assertSame('FIRST', $retained->result['content'][0]['text']);
        $this->assertSame([], $handler->handle($request, $state)->events, 'No ACK after terminal acceptance or an earlier ACK.');
        if ($ackFirst) {
            $transport = $container->get('messenger.transport.run_control');
            $this->assertInstanceOf(InMemoryTransport::class, $transport);
            $notices = array_values(array_filter(array_map(static fn ($envelope) => $envelope->getMessage(), $transport->getSent()), static fn (object $message): bool => $message instanceof DeliverBackgroundCompletionDTO && $message->identity === $id));
            $this->assertCount(2, $notices);
            $this->assertSame(['FIRST', 'FIRST'], array_column($notices, 'text'));
            $delivery = $container->get(BackgroundCompletionRunHandler::class)->handle($notices[0], $ack->nextState);
            $this->admit($runId, $delivery->postCommitActions);
            $this->assertSame('completed', $repository->status($id));
            if ($fenceReceipt) {
                $hook = $container->get(CodeModeBackgroundCoordinationHandler::class);
                $actions = $hook->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($ack->nextState->with(['status' => RunStatus::Cancelling]), [], 0), 1);
                $this->admit($runId, $actions);
                $decision = $container->get(BackgroundCompletionConsumptionPolicy::class)->prepare($ack->nextState,
                    new PendingCommand($runId, 'append_message', $id, ['provenance' => new BackgroundCompletionProvenanceDTO($id, $runId, $id)]));
                $this->assertNotNull($decision->retirementReason);
                $this->assertSame('completed', $repository->status($id), 'Interruption never reopens durable admission.');
                $this->assertFalse($container->get(HistoryTailDiscardService::class)->isContextMutatingMessage(new AdvanceRun($runId, 1, 'step', 1, 'advance')));
            }
        } else {
            $admission = $container->get(ToolCallResultHandler::class)->handle($retained, $state);
            $this->admit($runId, $admission->postCommitActions);
            $this->assertSame('completed', $repository->status($id));
        }
    }

    public static function winners(): iterable
    {
        yield 'ACK wins' => [true];
        yield 'admitted receipt fenced before consumption' => [true, true, true];
        yield 'terminal wins' => [false];
        yield 'startup failure before readiness' => [false, false];
    }

    /** @param list<object> $actions */
    private function admit(string $runId, array $actions): void
    {
        $local = array_values(array_filter($actions, static fn (object $action): bool => $action instanceof FinalizeToolBatchDTO || $action instanceof EnqueueCommandDTO || $action instanceof DispatchLocalMetadataMessageDTO));
        $this->assertNotEmpty($local);
        $verified = new VerifiedTransitionDTO(Uuid::v7()->toRfc4122(), ['run_id' => $runId, 'actions' => $actions]);
        self::getContainer()->get(LocalMetadataCoordinator::class)->apply(new TransitionPlan($runId, $verified, $local, [], []));
    }
}
