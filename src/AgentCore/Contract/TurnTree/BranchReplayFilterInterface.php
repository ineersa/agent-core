<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\TurnTree;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Filters canonical events to the active or target leaf branch path.
 *
 * Implemented by CodingAgent session replay (SESSION-07A).
 */
interface BranchReplayFilterInterface
{
    /**
     * @param list<RunEvent> $events Unsorted canonical events
     */
    public function filter(string $runId, array $events): BranchReplayResultDTO;

    /**
     * @param list<RunEvent> $events           Unsorted canonical events
     * @param int|null       $targetLeafTurnNo Target leaf (null = current leaf)
     */
    public function filterForLeaf(string $runId, array $events, ?int $targetLeafTurnNo = null): BranchReplayResultDTO;
}
