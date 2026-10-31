<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Tool\ToolResult;

final class ToolExecutionResultStore
{
    /** @var array<string, ToolResult> */
    private array $resultsByRunToolCall = [];

    /** @var array<string, ToolResult> */
    private array $resultsByToolIdempotency = [];

    public function findByRunToolCall(string $runId, string $toolCallId, ?string $invocationKey = null): ?ToolResult
    {
        return $this->resultsByRunToolCall[$this->runToolCallKey($runId, $toolCallId, $invocationKey)] ?? null;
    }

    public function findByToolAndIdempotencyKey(string $toolName, string $toolIdempotencyKey): ?ToolResult
    {
        return $this->resultsByToolIdempotency[$this->toolIdempotencyKey($toolName, $toolIdempotencyKey)] ?? null;
    }

    public function remember(
        string $runId,
        string $toolCallId,
        string $toolName,
        ?string $toolIdempotencyKey,
        ToolResult $result,
        ?string $invocationKey = null,
    ): void {
        $this->resultsByRunToolCall[$this->runToolCallKey($runId, $toolCallId, $invocationKey)] = $result;

        if (null === $toolIdempotencyKey || '' === $toolIdempotencyKey) {
            return;
        }

        $this->resultsByToolIdempotency[$this->toolIdempotencyKey($toolName, $toolIdempotencyKey)] = $result;
    }

    public function releaseCompleted(
        string $runId,
        string $toolCallId,
        string $toolName,
        ?string $toolIdempotencyKey,
        ?string $invocationKey = null,
    ): void {
        unset($this->resultsByRunToolCall[$this->runToolCallKey($runId, $toolCallId, $invocationKey)]);

        if (null === $toolIdempotencyKey || '' === $toolIdempotencyKey) {
            return;
        }

        unset($this->resultsByToolIdempotency[$this->toolIdempotencyKey($toolName, $toolIdempotencyKey)]);
    }

    private function runToolCallKey(string $runId, string $toolCallId, ?string $invocationKey): string
    {
        if (null === $invocationKey || '' === $invocationKey) {
            throw new \InvalidArgumentException('Tool execution result store requires an invocation key.');
        }

        return $runId.'|'.$toolCallId.'|'.$invocationKey;
    }

    private function toolIdempotencyKey(string $toolName, string $toolIdempotencyKey): string
    {
        return $toolName.'|'.$toolIdempotencyKey;
    }
}
