<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

/**
 * Represents a domain event signaling the initiation of an agent's lifecycle within the system. This immutable value object captures the context required to track the start of an agent instance.
 */
final readonly class AgentStartEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::AGENT_START;
}
