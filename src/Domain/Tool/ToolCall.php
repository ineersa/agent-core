<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $toolCallId,
        public string $toolName,
        public array $arguments,
        public int $orderIndex,
    ) {
    }
}
