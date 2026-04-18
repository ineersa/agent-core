<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Extension\EventSubscriberInterface;

final class EventSubscriberRegistry
{
    /** @var iterable<EventSubscriberInterface> */
    private iterable $subscribers;

    /**
     * @param iterable<EventSubscriberInterface> $subscribers
     */
    public function __construct(iterable $subscribers)
    {
        $this->subscribers = $subscribers;
    }

    /**
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
