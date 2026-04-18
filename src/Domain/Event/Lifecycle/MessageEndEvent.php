<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

final readonly class MessageEndEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::MESSAGE_END;
}
