<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

/**
 * Represents the completion of a tool execution within an agent's lifecycle, capturing the outcome and any resulting state changes. This event serves as a domain signal for downstream processing of tool results.
 */
final readonly class ToolExecutionEndEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::TOOL_EXECUTION_END;
}
