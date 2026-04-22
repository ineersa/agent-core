<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * Immutable execution constraints for tool calls, governing mode, timeout, and parallelism limits.
 */
final readonly class ToolExecutionPolicy
{
    public function __construct(
        public ToolExecutionMode $mode,
        public int $timeoutSeconds,
        public int $maxParallelism,
    ) {
    }
}
