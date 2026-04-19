<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The RunEventDispatcher class acts as an application-level facade for routing RunEvent instances to registered subscribers. It leverages an EventSubscriberRegistry to identify relevant handlers and delegates execution to a standard EventDispatcherInterface. This design decouples the specific event type from the underlying dispatching mechanism.
 */
final readonly class RunEventDispatcher
{
    /**
     * initializes the dispatcher with subscriber registry and event dispatcher.
     */
    public function __construct(
        private EventSubscriberRegistry $registry,
        private EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * routes RunEvent to subscribers via registry and dispatches via event dispatcher.
     */
    public function dispatch(RunEvent $event): void
    {
        $this->eventDispatcher->dispatch($event, $event->type);

        foreach ($this->registry->subscribersFor($event->type) as $subscriber) {
            $subscriber->onEvent($event);
        }
    }
}
