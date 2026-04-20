<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Tool\ToolResult;

/**
 * This class provides a caching layer for tool execution results, enabling efficient retrieval of previously computed outputs. It supports lookup by run and tool call identifiers, as well as by tool name and idempotency keys to prevent redundant execution.
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
