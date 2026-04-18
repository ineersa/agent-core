<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\ExceptionInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ExecuteToolCallWorker
{
    public function __construct(
        private ToolExecutorInterface $toolExecutor,
        private MessageBusInterface $commandBus,
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
        try {
            $toolResult = $this->toolExecutor->execute(new ToolCall(
                toolCallId: $message->toolCallId,
                toolName: $message->toolName,
                arguments: $message->args,
                orderIndex: $message->orderIndex,
            ));

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
                    'tool_idempotency_key' => $message->toolIdempotencyKey,
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
                result: null,
                isError: true,
                error: [
                    'type' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            );
        }
    }
}
