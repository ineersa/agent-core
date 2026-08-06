<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\TurnTree;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Result of filtering an event stream to the active retained linear history prefix.
 *
 * Interim Core contract type so replay handlers can consume active-history events
 * without depending on CodingAgent session implementations.
 */
final readonly class BranchReplayResultDTO
{
    /**
     * @param list<RunEvent> $events              Events on the active retained history only
     * @param int            $canonicalEventCount Total event count in the full canonical stream
     * @param list<int>      $activePathTurnNos   Turn numbers from start through current tip
     * @param int|null       $currentLeafTurnNo   The current selected tip turn number
     */
    public function __construct(
        public array $events,
        public int $canonicalEventCount,
        public array $activePathTurnNos,
        public ?int $currentLeafTurnNo,
    ) {
    }
}
