<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionHumanInputSuspension;
use Ineersa\AgentCore\Domain\Tool\ToolResult;

/**
 * Maps tool execution outcomes to the canonical ToolCallResult envelope.
 */
final class ToolCallResultFactory
{
    public static function fromExecuteToolCallAndToolResult(ExecuteToolCall $message, ToolResult $toolResult): ToolCallResult
    {
        $raw = \is_array($toolResult->details) ? ($toolResult->details['raw_result'] ?? null) : null;
        if ($raw instanceof ToolExecutionHumanInputSuspension) {
            return self::fromExecuteToolCallAndHumanInputSuspension($message, $raw);
        }

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

    /**
     * Map a typed non-terminal human-input suspension into the existing ToolCallResult envelope.
     *
     * Invariant: the PendingHumanInputRequestDTO produced by ToolExecutor/toolbox is preserved
     * raw — no payload rewrite, no continuation_ref backfill, no string kind router. Correlation
     * (run/turn/step/toolCall) lives on the envelope; `$pendingHumanInput` alone marks the
     * non-terminal variant for ToolCallResultHandler admission.
     */
    public static function fromExecuteToolCallAndHumanInputSuspension(
        ExecuteToolCall $message,
        ToolExecutionHumanInputSuspension $suspension,
    ): ToolCallResult {
        return new ToolCallResult(
            runId: $message->runId(),
            turnNo: $message->turnNo(),
            stepId: $message->stepId(),
            attempt: $message->attempt(),
            idempotencyKey: $message->idempotencyKey(),
            toolCallId: $message->toolCallId,
            orderIndex: $message->orderIndex,
            result: null,
            isError: false,
            error: null,
            pendingHumanInput: $suspension->request,
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

    /**
     * @param array<int, array<string, mixed>> $content
     * @param array<string, mixed>|null        $details
     * @param array<string, mixed>|null        $error
     */
    public static function fromDeferredCorrelationAndCompletion(
        \Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation $correlation,
        array $content,
        ?array $details = null,
        bool $isError = false,
        ?array $error = null,
    ): ToolCallResult {
        $toolIdempotencyKey = \is_array($details)
            && \is_string($details['tool_idempotency_key'] ?? null)
                ? $details['tool_idempotency_key']
                : $correlation->toolIdempotencyKey;

        return new ToolCallResult(
            runId: $correlation->runId,
            turnNo: $correlation->turnNo,
            stepId: $correlation->stepId,
            attempt: $correlation->attempt,
            idempotencyKey: $correlation->idempotencyKey,
            toolCallId: $correlation->toolCallId,
            orderIndex: $correlation->orderIndex,
            result: [
                'tool_name' => $correlation->toolName,
                'content' => $content,
                'details' => $details,
                'tool_idempotency_key' => $toolIdempotencyKey,
                'mode' => $correlation->mode,
                'arguments' => $correlation->arguments,
            ],
            isError: $isError,
            error: $error,
        );
    }
}
