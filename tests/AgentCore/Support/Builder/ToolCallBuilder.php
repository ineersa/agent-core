<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Builder;

use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/**
 * Builder for domain ToolCall value objects in tests.
 *
 * Defaults: toolCallId="tool-call-1", toolName="web_search",
 * arguments=['query' => 'test'], orderIndex=0, runId=null, mode=null,
 * timeoutSeconds=null, toolIdempotencyKey=null, context=[].
 *
 * @phpstan-type ToolArguments array<string, mixed>
 * @phpstan-type ToolContext array<string, mixed>
 */
final class ToolCallBuilder
{
    private string $toolCallId = 'tool-call-1';
    private string $toolName = 'web_search';

    /** @var ToolArguments */
    private array $arguments = ['query' => 'test'];

    private int $orderIndex = 0;
    private ?string $runId = null;
    private ?ToolExecutionMode $mode = null;
    private ?int $timeoutSeconds = null;
    private ?string $toolIdempotencyKey = null;

    /** @var ToolContext */
    private array $context = [];

    public static function create(string $toolCallId = 'tool-call-1'): self
    {
        return (new self())->withToolCallId($toolCallId);
    }

    public function withToolCallId(string $toolCallId): self
    {
        $this->toolCallId = $toolCallId;

        return $this;
    }

    public function withToolName(string $toolName): self
    {
        $this->toolName = $toolName;

        return $this;
    }

    /**
     * @param ToolArguments $arguments
     */
    public function withArguments(array $arguments): self
    {
        $this->arguments = $arguments;

        return $this;
    }

    public function withOrderIndex(int $orderIndex): self
    {
        $this->orderIndex = $orderIndex;

        return $this;
    }

    public function withRunId(?string $runId): self
    {
        $this->runId = $runId;

        return $this;
    }

    public function withMode(?ToolExecutionMode $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function withTimeoutSeconds(?int $timeoutSeconds): self
    {
        $this->timeoutSeconds = $timeoutSeconds;

        return $this;
    }

    public function withToolIdempotencyKey(?string $toolIdempotencyKey): self
    {
        $this->toolIdempotencyKey = $toolIdempotencyKey;

        return $this;
    }

    /**
     * @param ToolContext $context
     */
    public function withContext(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    public function build(): ToolCall
    {
        return new ToolCall(
            toolCallId: $this->toolCallId,
            toolName: $this->toolName,
            arguments: $this->arguments,
            orderIndex: $this->orderIndex,
            runId: $this->runId,
            mode: $this->mode,
            timeoutSeconds: $this->timeoutSeconds,
            toolIdempotencyKey: $this->toolIdempotencyKey,
            context: $this->context,
        );
    }
}
