<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

final readonly class ToolCall
{
    /**
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
