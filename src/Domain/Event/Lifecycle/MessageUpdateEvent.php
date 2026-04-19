<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

/**
 * Represents a domain event signaling an update to a message within the lifecycle of an agent interaction. This immutable value object encapsulates the context required to track message state changes without side effects.
 */
final readonly class MessageUpdateEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::MESSAGE_UPDATE;
}
