<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

/**
 * Represents a domain event signaling the initiation of a tool execution within an agent's lifecycle. This immutable event captures the context required to track the start of a specific tool invocation.
 */
final readonly class ToolExecutionStartEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::TOOL_EXECUTION_START;
}
