<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

/**
 * ToolExecutionPolicy defines immutable configuration constraints for tool execution, including mode, timeout, and parallelism limits. It serves as a value object to enforce execution boundaries within the agent core domain.
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
