<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Reacts to specific domain events by declaring subscribed event types and processing them.
 */
interface EventSubscriberInterface
{
    /**
     * Returns the array of event class names this subscriber is interested in.
     *
     * @return list<string> Core lifecycle event types or extension types prefixed with "ext_"
     */
    public static function subscribedEventTypes(): array;

    public function onEvent(RunEvent $event): void;
}
