<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\History\HistoryTailDiscardInterface;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
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
        iterable $handlers,
        private CommandStoreInterface $commands,
        private ?HistoryTailDiscardInterface $historyTailDiscard = null,
    ) {
        $this->handlers = [...$handlers];
    }

    public function process(string $scope, AbstractAgentBusMessage $message, ?DurableExecutionResult $durableResult = null): void
    {
        $runId = $message->runId();
        RunLogContext::enter([
            'run_id' => $runId,
            'scope' => $scope,
            'queue' => 'agent.command.bus',
            'message_type' => $message::class,
        ]);

        try {
            $this->runLockManager->synchronized($runId, function () use ($message, $runId, $durableResult): void {
                $handler = $this->resolveHandler($message);
                RunLogContext::enter([
                    'handler' => $handler::class,
                    'component' => $handler instanceof RunMessageHandlerLogComponentInterface
                        ? $handler->getLogComponent()
                        : 'runtime',
                ]);
                try {
                    $this->runCommit->assertTransitionReady($runId);
                    // A duplicate pending command cannot discard selected history.
                    // Completed IDs are absent and may be submitted again.
                    if ($message instanceof ApplyCommand && $this->commands->has($runId, $message->idempotencyKey())) {
                        return;
                    }
                    $state = $this->activeRunContext->requireLoaded($runId);
                    if ($this->runCommit->executionResultAlreadyDisposed($durableResult)) {
                        return;
                    }

                    if ($message instanceof \Ineersa\AgentCore\Domain\Message\AdvanceRun || $message instanceof \Ineersa\AgentCore\Domain\Message\CompactRun || $message instanceof \Ineersa\AgentCore\Domain\Message\ApplyShellCommand
                        || ($message instanceof ApplyCommand && \Ineersa\AgentCore\Domain\Command\CoreCommandKind::Cancel !== $message->kind)) {
                        $this->runCommit->assertNoUnknownExecution($runId);
                    }

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

                    $result = $handler->handle($message, $state);
                    $stale = null === $result->nextState || $state->turnNo !== $message->turnNo() || \Ineersa\AgentCore\Domain\Run\RunStatus::Cancelled === $state->status;
                    $executionDisposition = $this->runCommit->prepareExecutionDisposition($durableResult, $stale);
                    if (null === $result->nextState) {
                        if (null !== $executionDisposition) {
                            $this->runCommit->commit($state, $state, [], dispatchAfterTurnHooks: false, postCommitEffects: $result->postCommitEffects, postCommitActions: $result->postCommitActions, executionDisposition: $executionDisposition);

                            return;
                        }
                        if ([] !== $result->postCommitEffects || [] !== $result->postCommitActions) {
                            $this->runCommit->commit($state, $state, [], dispatchAfterTurnHooks: false, postCommitEffects: $result->postCommitEffects, postCommitActions: $result->postCommitActions);
                        }

                        return;
                    }

                    $this->runCommit->commit($state, $result->nextState, $result->events, $result->effects, postCommitEffects: $result->postCommitEffects, postCommitActions: $result->postCommitActions, executionDisposition: $executionDisposition);
                } finally {
                    RunLogContext::leave();
                }
            });
        } finally {
            RunLogContext::leave();
        }
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
