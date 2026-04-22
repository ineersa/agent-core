<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;

/**
 * Named-lookup registry that resolves hook subscribers by hook name.
 */
final class HookSubscriberRegistry
{
    /** @var iterable<HookSubscriberInterface> */
    private iterable $subscribers;

    /**
     * Initializes the registry with a collection of hook subscribers.
     *
     * @param iterable<HookSubscriberInterface> $subscribers
     */
    public function __construct(iterable $subscribers)
    {
        $this->subscribers = $subscribers;
    }

    /**
     * Retrieves subscribers registered for a specific hook name.
     *
     * @return iterable<HookSubscriberInterface>
     */
    public function subscribersFor(string $hookName): iterable
    {
        foreach ($this->subscribers as $subscriber) {
            if (!\in_array($hookName, $subscriber::subscribedHooks(), true)) {
                continue;
            }

            yield $subscriber;
        }
    }
}
