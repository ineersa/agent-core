<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

/**
 * This readonly final class encapsulates the immutable context required before a tool execution, bundling the assistant's message, the specific tool call, and its arguments. It serves as a structured data carrier to pass execution state through the agent's processing pipeline without exposing mutable state.
 */
final readonly class BeforeToolCallContext
{
    public function __construct(
        public AgentMessage $assistantMessage,
        public ToolCall $toolCall,
        public mixed $args,
        public mixed $context,
    ) {
    }
}
