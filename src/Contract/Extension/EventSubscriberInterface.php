<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Defines the contract for components that react to specific domain events within the AgentCore system. Implementers declare which event types they observe and provide the logic to process those events when they occur.
 */
interface EventSubscriberInterface
{
    /**
     * Returns the array of event class names this subscriber is interested in.
     *
     * @return list<string> Core lifecycle event types or extension types prefixed with "ext_"
     */
    public static function subscribedEventTypes(): array;

    /**
     * Processes a single RunEvent instance received from the event bus.
     */
    public function onEvent(RunEvent $event): void;
}
