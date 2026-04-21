<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * This class acts as a message handler for the agent execution bus, processing tool call commands by delegating execution to a dedicated tool executor. It coordinates the invocation of external tools and manages the resulting state updates within the agent's runtime context.
 */
final readonly class ExecuteToolCallWorker
{
    public function __construct(
        private ToolExecutorInterface $toolExecutor,
        private MessageBusInterface $commandBus,
        private ?RunStoreInterface $runStore = null,
        private ?RunMetrics $metrics = null,
        private ?RunTracer $tracer = null,
    ) {
    }

    /**
     * handles ExecuteToolCall messages on the agent.execution.bus.
     */
    #[AsMessageHandler(bus: 'agent.execution.bus')]
    public function __invoke(ExecuteToolCall $message): void
    {
        $execute = function () use ($message): void {
            $result = $this->execute($message);

            try {
                $this->commandBus->dispatch($result);
            } catch (ExceptionInterface $exception) {
                throw new \RuntimeException('Failed to dispatch tool result to command bus.', previous: $exception);
            }
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
    }

    private function execute(ExecuteToolCall $message): ToolCallResult
    {
        $cancelToken = null !== $this->runStore
            ? new RunCancellationToken($this->runStore, $message->runId())
            : new NullCancellationToken();

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
            ],
        );

        $startedAt = hrtime(true);

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

            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $timedOut = \is_array($toolResult->details) && true === ($toolResult->details['timed_out'] ?? false);
            $this->metrics?->recordToolLatency($durationMs, $toolResult->isError, $timedOut);

            $toolIdempotencyKey = \is_array($toolResult->details)
                && \is_string($toolResult->details['tool_idempotency_key'] ?? null)
                    ? $toolResult->details['tool_idempotency_key']
                    : $message->toolIdempotencyKey;

            return new ToolCallResult(
                runId: $message->runId(),
                turnNo: $message->turnNo(),
                stepId: $message->stepId(),
                attempt: $message->attempt(),
                idempotencyKey: $message->idempotencyKey(),
                toolCallId: $message->toolCallId,
                orderIndex: $message->orderIndex,
                result: [
                    'tool_name' => $toolResult->toolName,
                    'content' => $toolResult->content,
                    'details' => $toolResult->details,
                    'tool_idempotency_key' => $toolIdempotencyKey,
                    'mode' => $message->mode,
                ],
                isError: $toolResult->isError,
                error: null,
            );
        } catch (\Throwable $exception) {
            $durationMs = (hrtime(true) - $startedAt) / 1_000_000;
            $this->metrics?->recordToolLatency($durationMs, true, false);

            return new ToolCallResult(
                runId: $message->runId(),
                turnNo: $message->turnNo(),
                stepId: $message->stepId(),
                attempt: $message->attempt(),
                idempotencyKey: $message->idempotencyKey(),
                toolCallId: $message->toolCallId,
                orderIndex: $message->orderIndex,
                result: [
                    'tool_name' => $message->toolName,
                    'content' => [[
                        'type' => 'text',
                        'text' => $exception->getMessage(),
                    ]],
                    'details' => [
                        'error_type' => $exception::class,
                    ],
                    'tool_idempotency_key' => $message->toolIdempotencyKey,
                    'mode' => $message->mode,
                ],
                isError: true,
                error: [
                    'type' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );
        }
    }
}
