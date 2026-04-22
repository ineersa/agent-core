<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

use Ineersa\AgentCore\Domain\Event\RunEvent;

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
