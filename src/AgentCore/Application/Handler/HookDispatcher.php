<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Extension\HookSubscriberInterface;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;

/**
 * Aggregates typed after-turn-commit subscribers in registration order.
 *
 * Typed-subscriber aggregation only: the inert Serializer/EventDispatcher
 * BoundaryHookEvent bridge was removed (no production listener ever consumed
 * it). RunCommit owns failure isolation around this dispatch.
 */
final readonly class HookDispatcher
{
    /**
     * @param iterable<HookSubscriberInterface> $subscribers
     */
    public function __construct(
        private iterable $subscribers,
    ) {
    }

    public function dispatchAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        foreach ($this->subscribers as $subscriber) {
            $context = $subscriber->handleAfterTurnCommit($context);
        }

        return $context;
    }
}
