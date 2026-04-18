<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;

final class HookSubscriberRegistry
{
    /** @var iterable<HookSubscriberInterface> */
    private iterable $subscribers;

    /**
     * @param iterable<HookSubscriberInterface> $subscribers
     */
    public function __construct(iterable $subscribers)
    {
        $this->subscribers = $subscribers;
    }

    /**
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
