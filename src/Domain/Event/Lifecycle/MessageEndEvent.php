<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

/**
 * Represents the terminal state of a message lifecycle within the agent core domain. This immutable event captures the conclusion of a message processing sequence for domain event sourcing. It serves as a durable record of message completion for downstream consumers.
 */
final readonly class MessageEndEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::MESSAGE_END;
}
