<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

final readonly class AfterToolCallContext
{
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
