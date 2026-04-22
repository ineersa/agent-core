<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Tool\ToolResult;

/**
 * In-process cache for tool execution results, supporting lookup by run+tool-call and by tool name+idempotency key.
 */
final class ToolExecutionResultStore
{
    /** @var array<string, ToolResult> */
    private array $resultsByRunToolCall = [];

    /** @var array<string, ToolResult> */
    private array $resultsByToolIdempotency = [];

    public function findByRunToolCall(string $runId, string $toolCallId): ?ToolResult
    {
        return $this->resultsByRunToolCall[$this->runToolCallKey($runId, $toolCallId)] ?? null;
    }

    public function findByToolAndIdempotencyKey(string $toolName, string $toolIdempotencyKey): ?ToolResult
    {
        return $this->resultsByToolIdempotency[$this->toolIdempotencyKey($toolName, $toolIdempotencyKey)] ?? null;
    }

    public function remember(string $runId, string $toolCallId, string $toolName, ?string $toolIdempotencyKey, ToolResult $result): void
    {
        $this->resultsByRunToolCall[$this->runToolCallKey($runId, $toolCallId)] = $result;

        if (null === $toolIdempotencyKey || '' === $toolIdempotencyKey) {
            return;
        }

        $this->resultsByToolIdempotency[$this->toolIdempotencyKey($toolName, $toolIdempotencyKey)] = $result;
    }

    private function runToolCallKey(string $runId, string $toolCallId): string
    {
        return $runId.'|'.$toolCallId;
    }

    private function toolIdempotencyKey(string $toolName, string $toolIdempotencyKey): string
    {
        return $toolName.'|'.$toolIdempotencyKey;
    }
}
