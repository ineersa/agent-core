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
                    $state = $this->activeRunContext->stateFor($runId);

                    // A context-mutating action may append history_tail_discarded
                    // before its normal handler transition. Persist this separate
                    // canonical mutation immediately, including no-op handlers.
                    if (null !== $this->historyTailDiscard && $this->historyTailDiscard->isContextMutatingMessage($message)) {
                        $discardResult = $this->historyTailDiscard->discardForwardTailIfNeeded($runId, $state);
                        if ($discardResult['discarded'] && $discardResult['lastSeq'] > $state->lastSeq) {
                            $state = $state->with(['lastSeq' => $discardResult['lastSeq']]);
                            $this->activeRunContext->remember($state);
                        }
                    }

                    $result = $handler->handle($message, $state);
                    if (null === $result->nextState) {
                        $this->dispatchPostCommit($result);

                        return;
                    }

                    $this->runCommit->commit($state, $result->nextState, $result->events, $result->effects);
                    $this->dispatchPostCommit($result);
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
        foreach ($result->postCommit as $callback) {
            $callback();
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
