<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/** Array filtering for explicit repair and context export, not startup recovery. */
final class HistoryReplayFilter
{
    public function __construct(private readonly HistoryProjector $projector)
    {
    }

    /** @param list<RunEvent> $events
     * @return list<RunEvent>
     */
    public function filter(array $events): array
    {
        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);
        $plan = $this->projector->replayPlan($events);

        return array_values(array_filter($events, $plan->includes(...)));
    }
}
