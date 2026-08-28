<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
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
        private ?RunTracer $tracer = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
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
                $outcome = $this->execute($message);
                if (null === $outcome) {
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
        $existing = $this->deferredToolCompletionRepository->findPendingByRunAndToolCall($message->runId(), $message->toolCallId);
        if (null !== $existing) {
            $this->dispatchDeferredRegistered($existing);

            return null;
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
            ],
        );

        RunLogContext::enter(['event_type' => 'tool.execute.started']);

        try {
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
                $this->dispatchDeferredRegistered($correlation);

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
