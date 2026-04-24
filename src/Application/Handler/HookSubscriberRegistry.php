<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;

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
     * @return iterable<HookSubscriberInterface>
     */
    public function all(): iterable
    {
        return $this->subscribers;
    }
}
