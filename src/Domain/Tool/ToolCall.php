<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * Represents a discrete tool invocation within an agent's execution flow, capturing the identifier, target tool name, and serialized arguments. It serves as a value object to track the sequence and context of tool calls during runtime.
 */
final readonly class ToolCall
{
    /**
     * Initializes a tool call instance with its unique ID, name, arguments, and execution order.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public int $orderIndex,
        public ?string $runId = null,
        public ?ToolExecutionMode $mode = null,
        public ?int $timeoutSeconds = null,
        public ?string $toolIdempotencyKey = null,
        public ?AgentMessage $assistantMessage = null,
        public array $context = [],
    ) {
    }
}
