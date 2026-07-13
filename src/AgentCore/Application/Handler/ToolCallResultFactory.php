<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

/**
 * Maps tool execution outcomes to the canonical ToolCallResult envelope.
 */
final class ToolCallResultFactory
{
    public static function fromExecuteToolCallAndToolResult(ExecuteToolCall $message, ToolResult $toolResult): ToolCallResult
    {
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
                'arguments' => $message->args,
            ],
            isError: $toolResult->isError,
            error: null,
        );
    }

    public static function fromExecuteToolCallAndThrowable(ExecuteToolCall $message, \Throwable $exception): ToolCallResult
    {
        $errorType = $exception::class;
        $details = [
            'error_type' => $errorType,
        ];

        if ($exception instanceof ToolCallException) {
            $details['retryable'] = $exception->retryable();
            $details['hint'] = $exception->hint();
        }

        $error = [
            'type' => $errorType,
            'message' => $exception->getMessage(),
        ];
        if ($exception instanceof ToolCallException) {
            $error['retryable'] = $exception->retryable();
            $error['hint'] = $exception->hint();
        }

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
                'details' => $details,
                'tool_idempotency_key' => $message->toolIdempotencyKey,
                'mode' => $message->mode,
                'arguments' => $message->args,
            ],
            isError: true,
            error: $error,
        );
    }
}
