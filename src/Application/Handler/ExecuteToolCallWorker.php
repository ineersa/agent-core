<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\RunCancellationToken;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ExecuteToolCallWorker
{
    public function __construct(
        private ToolExecutorInterface $toolExecutor,
        private MessageBusInterface $commandBus,
        private ?RunStoreInterface $runStore = null,
    ) {
    }

    #[AsMessageHandler(bus: 'agent.execution.bus')]
    public function __invoke(ExecuteToolCall $message): void
    {
        $result = $this->execute($message);

        try {
            $this->commandBus->dispatch($result);
        } catch (ExceptionInterface $exception) {
            throw new \RuntimeException('Failed to dispatch tool result to command bus.', previous: $exception);
        }
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
            assistantMessage: $this->hydrateAssistantMessage($message->assistantMessage),
            context: [
                'run_id' => $message->runId(),
                'turn_no' => $message->turnNo(),
                'step_id' => $message->stepId(),
                'arg_schema' => $message->argSchema,
                'max_parallelism' => $message->maxParallelism,
                'cancel_token' => $cancelToken,
            ],
        );

        try {
            $toolResult = $this->toolExecutor->execute($toolCall);

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

    /**
     * @param array<string, mixed>|null $payload
     */
    private function hydrateAssistantMessage(?array $payload): ?AgentMessage
    {
        if (null === $payload) {
            return null;
        }

        $role = \is_string($payload['role'] ?? null) ? $payload['role'] : 'assistant';
        $content = \is_array($payload['content'] ?? null) ? $payload['content'] : [];

        return new AgentMessage(
            role: $role,
            content: $content,
            name: \is_string($payload['name'] ?? null) ? $payload['name'] : null,
            toolCallId: \is_string($payload['tool_call_id'] ?? null) ? $payload['tool_call_id'] : null,
            toolName: \is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : null,
            details: $payload['details'] ?? null,
            isError: \is_bool($payload['is_error'] ?? null) ? $payload['is_error'] : false,
            metadata: \is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        );
    }
}
