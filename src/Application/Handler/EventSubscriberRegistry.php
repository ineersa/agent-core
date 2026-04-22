<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Extension\EventSubscriberInterface;

final class EventSubscriberRegistry
{
    /** @var iterable<EventSubscriberInterface> */
    private iterable $subscribers;

    /**
     * Initializes the registry with a collection of event subscribers.
     *
     * @param iterable<EventSubscriberInterface> $subscribers
     */
    public function __construct(iterable $subscribers)
    {
        $this->subscribers = $subscribers;
    }

    /**
     * Retrieves subscribers matching the specified event type.
     *
     * @return iterable<EventSubscriberInterface>
     */
    public function subscribersFor(string $eventType): iterable
    {
        foreach ($this->subscribers as $subscriber) {
            if (!\in_array($eventType, $subscriber::subscribedEventTypes(), true)) {
                continue;
            }

            yield $subscriber;
        }
    }
}
