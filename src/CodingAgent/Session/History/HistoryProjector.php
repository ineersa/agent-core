<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/** Replay planning for explicit repair and context export. */
final class HistoryProjector
{
    /** @param iterable<RunEvent> $events */
    public function replayPlan(iterable $events, ?int $positionTurnNo = null): HistoryReplayPlan
    {
        return HistoryReplayPlan::build($events, $positionTurnNo);
    }
}
