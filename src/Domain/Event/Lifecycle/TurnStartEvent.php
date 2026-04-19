<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

/**
 * Represents a domain event signaling the initiation of a new turn within an agent lifecycle. This immutable value object encapsulates the context required to track turn progression and state transitions.
 */
final readonly class TurnStartEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::TURN_START;
}
