<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Outcome of a tool call execution, capturing the tool identity, structured content blocks, and optional error details.
 */
final readonly class ToolResult
{
    /**
     * Initializes the tool result with call ID, name, content, details, and error status.
     *
     * @param array<int, array<string, mixed>> $content
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $content,
        public mixed $details = null,
        public bool $isError = false,
    ) {
    }
}
