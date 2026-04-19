<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * This readonly final class encapsulates the immutable context required after a tool execution, bundling the assistant's message, the specific tool call, its arguments, and the resulting outcome. It serves as a structured data carrier for propagating tool interaction details within the agent domain.
 */
final readonly class AfterToolCallContext
{
    /**
     * Initializes the context with assistant message, tool call, arguments, result, and error status.
     */
    public function __construct(
        public AgentMessage $assistantMessage,
        public ToolCall $toolCall,
        public mixed $args,
        public ToolResult $result,
        public bool $isError,
        public mixed $context,
    ) {
    }
}
