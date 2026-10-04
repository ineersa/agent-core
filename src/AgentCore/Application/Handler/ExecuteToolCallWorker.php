<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface;
use Ineersa\AgentCore\Domain\Event\DeferredToolCompletionRegisteredEvent;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ExecuteToolCallWorker
{
    public function __construct(
        private ToolExecutorInterface $toolExecutor,
        private MessageBusInterface $commandBus,
        private DeferredToolCompletionRepositoryInterface $deferredToolCompletionRepository,
        private ToolExecutionResultStore $resultStore,
        private RunOperationalStatusReaderInterface $statusReader,
        private \Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface $toolAuthorization,
        private ?RunTracer $tracer = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ToolLaunchInputStoreInterface $launchInputStore = null,
        private ?ToolBatchCollector $toolBatchCollector = null,
    ) {
    }

    /**
     * handles ExecuteToolCall messages on the agent.execution.bus.
     */
    #[AsMessageHandler(bus: 'agent.execution.bus')]
    public function __invoke(ExecuteToolCall $message): void
    {
        RunLogContext::enter([
            'run_id' => $message->runId(),
            'session_id' => $message->runId(),
            'component' => 'tool',
            'queue' => 'agent.execution.bus',
            'worker' => 'tool',
            'tool_name' => $message->toolName,
        ]);

        try {
            $execute = function () use ($message): void {
                $existing = $this->deferredToolCompletionRepository->findByRunAndToolCall($message->runId(), $message->toolCallId);
                if (null !== $existing) {
                    $this->toolAuthorization->transferToDeferred($message, $existing->deferredId);
                    $this->execute($message);

                    return;
                }
                $claim = $this->toolAuthorization->claim($message);
                if (null === $claim) {
                    return; // Running work is never automatically repeated.
                }
                $outcome = $claim instanceof ToolCallResult ? $claim : $this->execute($message);
                if (null !== $outcome && \is_string($claim)) {
                    // This write must succeed before result notification or delivery ACK.
                    $this->toolAuthorization->saveResult($message, $claim, $outcome);
                }
                if (null === $outcome) {
                    $registration = $this->deferredToolCompletionRepository->findByRunAndToolCall($message->runId(), $message->toolCallId);
                    if (null === $registration) {
                        throw new \RuntimeException('Deferred execution has no durable registration.');
                    }
                    $this->toolAuthorization->transferToDeferred($message, $registration->deferredId);
                    $this->dispatchDeferredRegistered($registration);

                    return;
                }

                try {
                    $this->commandBus->dispatch($outcome);
                } catch (ExceptionInterface $exception) {
                    throw new \RuntimeException('Failed to dispatch tool result to command bus.', previous: $exception);
                }

                $this->resultStore->releaseCompleted(
                    $message->runId(),
                    $message->toolCallId,
                    $message->toolName,
                    $message->toolIdempotencyKey,
                );
            };

            if (null === $this->tracer) {
                $execute();

                return;
            }

            $this->tracer->inSpan('turn.execution.tool_worker', [
                'run_id' => $message->runId(),
                'turn_no' => $message->turnNo(),
                'step_id' => $message->stepId(),
                'tool_call_id' => $message->toolCallId,
                'tool_name' => $message->toolName,
                'worker' => 'tool',
            ], $execute, root: true);
        } finally {
            RunLogContext::leave();
        }
    }

    private function execute(ExecuteToolCall $message): ?ToolCallResult
    {
        $existing = $this->deferredToolCompletionRepository->findByRunAndToolCall($message->runId(), $message->toolCallId);
        if (null !== $existing) {
            if ('pending' === $this->deferredToolCompletionRepository->status($existing->deferredId)) {
                $this->dispatchDeferredRegistered($existing);
            }

            return null;
        }

        RunLogContext::enter(['event_type' => 'tool.execute.started']);

        try {
            if (null !== $message->launchContext) {
                // A synchronous result can already be durable while siblings are
                // pending and canonical cleanup has removed this launch input.
                // Durable collector reads release the decoded batch on return.
                $stored = $this->toolBatchCollector?->getStoredResult($message->runId(), $message->turnNo(), $message->stepId(), $message->toolCallId);
                if (null !== $stored && !$stored->isHumanInputSuspension()
                    && $stored->runId() === $message->runId()
                    && $stored->turnNo() === $message->turnNo()
                    && $stored->stepId() === $message->stepId()
                    && $stored->toolCallId === $message->toolCallId) {
                    return $stored;
                }
            }

            $launchContext = null;
            if (null !== $message->launchContext) {
                $reference = $message->launchContext;
                if ($reference->producingRunId !== $message->runId()
                    || $reference->producingTurnNo !== $message->turnNo()
                    || $reference->producingStepId !== $message->stepId()
                    || $reference->toolCallId !== $message->toolCallId
                    || $reference->producingModel !== $message->parentModel
                    || $reference->kind !== $message->toolName) {
                    throw new \RuntimeException('Tool launch input reference does not match execution envelope.');
                }
                if (null === $this->launchInputStore) {
                    throw new \LogicException('Child launch input store is required.');
                }
                $launchContext = $this->launchInputStore->read($reference);
            }

            $cancelToken = new RunCancellationToken($this->statusReader, $message->runId());

            $batchToolCallCount = 1;
            if (\is_array($message->assistantMessage)) {
                $toolCallsInStep = $message->assistantMessage['tool_calls'] ?? null;
                if (\is_array($toolCallsInStep) && [] !== $toolCallsInStep) {
                    $batchToolCallCount = \count($toolCallsInStep);
                }
            }

            $toolCall = new ToolCall(
                toolCallId: $message->toolCallId,
                toolName: $message->toolName,
                arguments: $message->args,
                orderIndex: $message->orderIndex,
                runId: $message->runId(),
                mode: ToolExecutionMode::tryFrom((string) $message->mode),
                timeoutSeconds: $message->timeoutSeconds,
                toolIdempotencyKey: $message->toolIdempotencyKey,
                context: [
                    'run_id' => $message->runId(),
                    'turn_no' => $message->turnNo(),
                    'step_id' => $message->stepId(),
                    'arg_schema' => $message->argSchema,
                    'max_parallelism' => $message->maxParallelism,
                    'cancel_token' => $cancelToken,
                    'tools_ref' => $message->toolsRef,
                    'assistant_batch_tool_call_count' => $batchToolCallCount,
                    // Internal only — never model args. Used by ExtensionToolHookEventSubscriber
                    // to resume an exact approved call without re-prompting the originating hook.
                    'human_input_answer' => $message->humanInputAnswer,
                    'parent_model' => $message->parentModel,
                    'launch_context' => $launchContext,
                ],
            );

            $executeTool = fn () => $this->toolExecutor->execute($toolCall);

            $toolResult = null === $this->tracer
                ? $executeTool()
                : $this->tracer->inSpan('tool.call', [
                    'run_id' => $message->runId(),
                    'turn_no' => $message->turnNo(),
                    'step_id' => $message->stepId(),
                    'tool_call_id' => $message->toolCallId,
                    'tool_name' => $message->toolName,
                ], $executeTool)
            ;

            if ($this->isDeferredOutcome($toolResult)) {
                $correlation = $this->registerDeferredExecution($message, $toolResult);
                // The repository is now the durable dedupe source for deferred
                // re-delivery; retaining the process-local marker would leak it.
                $this->resultStore->releaseCompleted(
                    $message->runId(),
                    $message->toolCallId,
                    $message->toolName,
                    $message->toolIdempotencyKey,
                );

                return null;
            }

            return ToolCallResultFactory::fromExecuteToolCallAndToolResult($message, $toolResult);
        } catch (\Throwable $exception) {
            return ToolCallResultFactory::fromExecuteToolCallAndThrowable($message, $exception);
        } finally {
            RunLogContext::leave(); // event_type scope
        }
    }

    private function isDeferredOutcome(ToolResult $toolResult): bool
    {
        $details = $toolResult->details;
        if (!\is_array($details)) {
            return false;
        }

        $raw = $details['raw_result'] ?? null;

        return $raw instanceof DeferredToolCompletionOutcome;
    }

    private function registerDeferredExecution(ExecuteToolCall $message, ToolResult $toolResult): DeferredToolCompletionCorrelation
    {
        $raw = $toolResult->details['raw_result'] ?? null;
        if (!$raw instanceof DeferredToolCompletionOutcome) {
            throw new \RuntimeException('Deferred tool outcome missing typed raw_result marker.');
        }

        $correlation = new DeferredToolCompletionCorrelation(
            deferredId: $raw->deferredId,
            runId: $message->runId(),
            turnNo: $message->turnNo(),
            stepId: $message->stepId(),
            attempt: $message->attempt(),
            idempotencyKey: $message->idempotencyKey(),
            toolCallId: $message->toolCallId,
            toolName: $message->toolName,
            arguments: $message->args,
            orderIndex: $message->orderIndex,
            toolIdempotencyKey: $message->toolIdempotencyKey,
            mode: $message->mode,
            timeoutSeconds: $message->timeoutSeconds,
            maxParallelism: $message->maxParallelism,
            assistantMessage: $message->assistantMessage,
            argSchema: $message->argSchema,
            toolsRef: $message->toolsRef,
        );

        return $this->deferredToolCompletionRepository->registerPending($correlation);
    }

    private function dispatchDeferredRegistered(DeferredToolCompletionCorrelation $correlation): void
    {
        if (null === $this->eventDispatcher) {
            return;
        }

        $this->eventDispatcher->dispatch(new DeferredToolCompletionRegisteredEvent($correlation));
    }
}
