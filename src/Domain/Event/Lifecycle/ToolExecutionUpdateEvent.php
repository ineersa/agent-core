<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

/**
 * Represents a domain event signaling an update to the execution state of a tool within an agent's lifecycle. This immutable value object captures the specific details of a tool execution change for domain event sourcing.
 */
final readonly class ToolExecutionUpdateEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::TOOL_EXECUTION_UPDATE;
}
