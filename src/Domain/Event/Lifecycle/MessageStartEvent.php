<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

/**
 * Represents a domain event signaling the initiation of message processing within the lifecycle. This immutable value object encapsulates the context required to track the start of a message operation. It serves as a structural marker for event sourcing and domain audit trails.
 */
final readonly class MessageStartEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::MESSAGE_START;
}
