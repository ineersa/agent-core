<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
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
     * @param iterable<HookSubscriberInterface|EssentialAfterTurnHookInterface> $subscribers
     */
    public function __construct(
        private iterable $subscribers,
    ) {
    }

    /** @return list<object> */
    public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
    {
        $actions = [];
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber instanceof EssentialAfterTurnHookInterface) {
                array_push($actions, ...$subscriber->prepareAfterTurnCommit($context, $predecessorSequence));
            }
        }

        return $actions;
    }

    public function dispatchAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext
    {
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber instanceof HookSubscriberInterface) {
                $context = $subscriber->handleAfterTurnCommit($context);
            }
        }

        return $context;
    }
}
