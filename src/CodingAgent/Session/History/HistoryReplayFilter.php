<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/** Array compatibility boundary; streaming callers use HistoryReplayPlan directly. */
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
        return $this->filterSorted($events, null);
    }

    /** @param list<RunEvent> $events
     * @return list<RunEvent>
     */
    public function filterAtPosition(array $events, int $positionTurnNo): array
    {
        return $this->filterSorted($events, $positionTurnNo);
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    private function filterSorted(array $events, ?int $positionTurnNo): array
    {
        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);
        $plan = $this->projector->replayPlan($events, $positionTurnNo);

        return array_values(array_filter($events, $plan->includes(...)));
    }
}
