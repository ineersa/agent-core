<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
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
use Ineersa\AgentCore\Exception\ToolExecutionNotStartedException;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ExecuteToolCallWorker
{
    public function __construct(
        private MessageBusInterface $commandBus,
        private LoggerInterface $logger,
        private ToolExecutorInterface $toolExecutor,
        private DeferredToolCompletionRepositoryInterface $deferredToolCompletionRepository,
        private ToolExecutionResultStore $resultStore,
        private RunOperationalStatusReaderInterface $statusReader,
        private ?RunTracer $tracer = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?ToolLaunchInputStoreInterface $launchInputStore = null,
    ) {
    }

    /**
     * Executes the ordinary request and posts its result to the command bus.
     */
    #[AsMessageHandler(bus: 'agent.execution.bus')]
    public function __invoke(ExecuteToolCall $message): ?ToolCallResult
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
            $execute = function () use ($message): ?ToolCallResult {
                $outcome = $this->execute($message);
                if (null === $outcome) {
                    if ('completed' === $this->deferredToolCompletionRepository->statusForRunAndToolCall($message->runId(), $message->toolCallId)) {
                        return null;
                    }
                    $registration = $this->deferredToolCompletionRepository->findByRunAndToolCall($message->runId(), $message->toolCallId);
                    if (null === $registration) {
                        throw new \RuntimeException('Deferred execution has no durable registration.');
                    }
                    $this->dispatchDeferredRegistered($registration);

                    return null;
                }

                return $outcome;
            };

            $result = null === $this->tracer ? $execute() : $this->tracer->inSpan('turn.execution.tool_worker', [
                'run_id' => $message->runId(),
                'turn_no' => $message->turnNo(),
                'step_id' => $message->stepId(),
                'tool_call_id' => $message->toolCallId,
                'tool_name' => $message->toolName,
                'worker' => 'tool',
            ], $execute, root: true);
            if (null === $result) {
                return null;
            }
            try {
                $this->commandBus->dispatch($result);
            } catch (\Throwable $exception) {
                $this->logger->warning('runtime.result_send_failed', [
                    'run_id' => $message->runId(),
                    'session_id' => $message->runId(),
                    'component' => 'execution_worker',
                    'event_type' => 'runtime.result_send_failed',
                    'exception_class' => $exception::class,
                ]);

                return $result;
            }
            $this->resultStore->releaseCompleted($message->runId(), $message->toolCallId, $message->toolName, $message->toolIdempotencyKey, $message->attempt().'|'.$message->idempotencyKey());

            return $result;
        } finally {
            RunLogContext::leave();
        }
    }

    private function execute(ExecuteToolCall $message): ?ToolCallResult
    {
        $metadata = $this->deferredToolCompletionRepository->findMetadataByRunAndToolCall($message->runId(), $message->toolCallId);
        if (null !== $metadata) {
            // Prepared-but-not-ready rows still need first execution.
            // Completed or ready pending rows are already deferred and must not rerun.
            if ('completed' === $metadata->status || null !== $metadata->readyAt) {
                return null;
            }
        }

        RunLogContext::enter(['event_type' => 'tool.execute.started']);

        try {
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
                try {
                    $launchContext = $this->launchInputStore->read($reference);
                } catch (\Throwable $exception) {
                    throw new ToolExecutionNotStartedException('Tool launch input is missing or invalid.', $exception);
                }
            }

            $cancelToken = new RunCancellationToken($this->statusReader, $message->runId());
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
                    'human_input_answer' => $message->humanInputAnswer,
                    'parent_model' => $message->parentModel,
                    'launch_context' => $launchContext,
                    'attempt' => $message->attempt(),
                    'idempotency_key' => $message->idempotencyKey(),
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
                ], $executeTool, level: LogLevel::INFO);

            if ($this->isDeferredOutcome($toolResult)) {
                $raw = $toolResult->details['raw_result'] ?? null;
                if (!$raw instanceof DeferredToolCompletionOutcome) {
                    throw new \RuntimeException('Deferred tool outcome missing typed raw_result marker.');
                }
                $this->registerDeferredExecution($message, $raw);
                $this->resultStore->releaseCompleted($message->runId(), $message->toolCallId, $message->toolName, $message->toolIdempotencyKey, $message->attempt().'|'.$message->idempotencyKey());

                return null;
            }

            return ToolCallResultFactory::fromExecuteToolCallAndToolResult($message, $toolResult);
        } catch (\Throwable $exception) {
            return ToolCallResultFactory::fromExecuteToolCallAndThrowable($message, $exception);
        } finally {
            RunLogContext::leave();
        }
    }

    private function isDeferredOutcome(ToolResult $toolResult): bool
    {
        $details = $toolResult->details;
        if (!\is_array($details)) {
            return false;
        }

        return ($details['raw_result'] ?? null) instanceof DeferredToolCompletionOutcome;
    }

    private function registerDeferredExecution(ExecuteToolCall $message, DeferredToolCompletionOutcome $raw): DeferredToolCompletionCorrelation
    {
        $metadata = $this->deferredToolCompletionRepository->findMetadataByRunAndToolCall($message->runId(), $message->toolCallId);
        if (null === $metadata) {
            throw new ToolCallException('Owner-prepared deferred registration is missing for this invocation.', retryable: false);
        }

        $reference = $metadata->reference;
        if (
            $metadata->deferredId !== $raw->deferredId
            || $reference->runId !== $message->runId()
            || $reference->turnNo !== $message->turnNo()
            || $reference->stepId !== $message->stepId()
            || $reference->attempt !== $message->attempt()
            || $reference->idempotencyKey !== $message->idempotencyKey()
            || $reference->toolCallId !== $message->toolCallId
            || $reference->orderIndex !== $message->orderIndex
            || $reference->toolName !== $message->toolName
        ) {
            throw new ToolCallException('Prepared deferred registration does not match its deferred outcome.', retryable: false);
        }

        if ('completed' === $metadata->status) {
            // Terminal no-op: do not reopen a deleted correlation body.
            return ToolCallResultFactory::correlationFromExecuteToolCall($message, $metadata->deferredId);
        }

        $correlation = $this->deferredToolCompletionRepository->findByDeferredId($metadata->deferredId);
        if (null === $correlation) {
            throw new ToolCallException('Owner-prepared deferred registration body is unavailable.', retryable: false);
        }

        return $correlation;
    }

    private function dispatchDeferredRegistered(DeferredToolCompletionCorrelation $correlation): void
    {
        $this->eventDispatcher?->dispatch(new DeferredToolCompletionRegisteredEvent($correlation));
    }
}
