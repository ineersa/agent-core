<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\History\HistoryTailDiscardInterface;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Infrastructure\RunLogContext;

/**
 * The run_control owner serializes transitions under RunLockManager. A run's
 * full state lives in its process-local active context; canonical events are
 * replayed only when that context has been invalidated or is first needed.
 */
final readonly class RunMessageProcessor
{
    /** @var list<RunMessageHandler> */
    private array $handlers;

    /** @param iterable<RunMessageHandler> $handlers */
    public function __construct(
        private ActiveRunContextInterface $activeRunContext,
        private RunLockManager $runLockManager,
        private RunCommit $runCommit,
        private StepDispatcher $stepDispatcher,
        iterable $handlers,
        private ?HistoryTailDiscardInterface $historyTailDiscard = null,
    ) {
        $this->handlers = [...$handlers];
    }

    public function process(string $scope, AbstractAgentBusMessage $message): void
    {
        $runId = $message->runId();
        RunLogContext::enter([
            'run_id' => $runId,
            'scope' => $scope,
            'queue' => 'agent.command.bus',
            'message_type' => $message::class,
        ]);

        try {
            $this->runLockManager->synchronized($runId, function () use ($message, $runId): void {
                $handler = $this->resolveHandler($message);
                RunLogContext::enter([
                    'handler' => $handler::class,
                    'component' => $handler instanceof RunMessageHandlerLogComponentInterface
                        ? $handler->getLogComponent()
                        : 'runtime',
                ]);
                try {
                    $this->runCommit->assertTransitionReady($runId);
                    $state = $this->activeRunContext->requireLoaded($runId);

                    // A context-mutating action may append history_tail_discarded
                    // before its normal handler transition. Persist this separate
                    // canonical mutation immediately, including no-op handlers.
                    if (null !== $this->historyTailDiscard && $this->historyTailDiscard->isContextMutatingMessage($message)) {
                        $discardEvent = $this->historyTailDiscard->prepareForwardTailDiscard($runId, $state);
                        if (null !== $discardEvent) {
                            $this->runCommit->commit($state, $state, [$discardEvent], dispatchAfterTurnHooks: false);
                            $state = $this->activeRunContext->requireLoaded($runId);
                            $this->historyTailDiscard->afterDiscardCommitted($runId);
                        }
                    }

                    if ($this->runCommit->executionResultAlreadyDisposed() || ($message instanceof \Ineersa\AgentCore\Domain\Message\ToolCallResult && $this->runCommit->toolResultAlreadyDisposed($message))) {
                        return;
                    }
                    $result = $handler->handle($message, $state);
                    $executionDisposition = $this->runCommit->prepareExecutionDisposition(null === $result->nextState || $state->turnNo !== $message->turnNo() || \Ineersa\AgentCore\Domain\Run\RunStatus::Cancelled === $state->status);
                    $disposition = $message instanceof \Ineersa\AgentCore\Domain\Message\ToolCallResult
                        ? $this->runCommit->prepareToolDisposition($message, null === $result->nextState || $state->turnNo !== $message->turnNo() || \Ineersa\AgentCore\Domain\Run\RunStatus::Cancelled === $state->status)
                        : null;
                    if (null === $result->nextState) {
                        if (null !== $executionDisposition) {
                            $this->runCommit->commit($state, $state, [], dispatchAfterTurnHooks: false, postCommitEffects: $result->postCommitEffects, postCommitActions: $result->postCommitActions, sourceIdentity: ['type' => $message::class, 'run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId(), 'attempt' => $message->attempt(), 'idempotency_key' => $message->idempotencyKey()], executionDisposition: $executionDisposition);

                            return;
                        }
                        if (null !== $disposition) {
                            $this->runCommit->finishEventFreeDisposition($disposition, $result);

                            return;
                        }
                        $this->dispatchPostCommit($result);

                        return;
                    }

                    $this->runCommit->commit($state, $result->nextState, $result->events, $result->effects, postCommitEffects: $result->postCommitEffects, postCommitActions: $result->postCommitActions, sourceIdentity: ['type' => $message::class, 'run_id' => $message->runId(), 'turn_no' => $message->turnNo(), 'step_id' => $message->stepId(), 'attempt' => $message->attempt(), 'idempotency_key' => $message->idempotencyKey()], resultDisposition: $disposition, executionDisposition: $executionDisposition);
                } finally {
                    RunLogContext::leave();
                }
            });
        } finally {
            RunLogContext::leave();
        }
    }

    private function dispatchPostCommit(HandlerResult $result): void
    {
        if ([] !== $result->postCommitEffects) {
            $this->stepDispatcher->dispatchEffects($result->postCommitEffects);
        }
        $this->stepDispatcher->dispatchCoordinationActions($result->postCommitActions);
    }

    private function resolveHandler(object $message): RunMessageHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($message)) {
                return $handler;
            }
        }
        throw new \LogicException(\sprintf('No run message handler supports message of type "%s".', $message::class));
    }
}
