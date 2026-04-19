<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

/**
 * Represents a domain event signaling the conclusion of an agent turn within the lifecycle. This readonly value object encapsulates the immutable state of a completed interaction step. It serves as a structural component for event-driven architecture without performing actions.
 */
final readonly class TurnEndEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::TURN_END;
}
