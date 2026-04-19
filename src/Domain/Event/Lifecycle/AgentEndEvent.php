<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

/**
 * Represents a domain event signaling the termination of an agent's lifecycle within the system. This immutable value object captures the context of the agent's conclusion for event sourcing and domain modeling purposes.
 */
final readonly class AgentEndEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::AGENT_END;
}
