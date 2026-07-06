<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\TurnTree;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Result of filtering an event stream through active-branch path selection.
 *
 * Interim Core contract type so replay handlers can consume branch-filtered
 * events without depending on CodingAgent session implementations (SESSION-07A).
 * Full replay orchestration relocation is SESSION-07B.
 */
final readonly class BranchReplayResultDTO
{
    /**
     * @param list<RunEvent> $events              Events on the active branch path only
     * @param int            $canonicalEventCount Total event count in the full canonical stream
     * @param list<int>      $activePathTurnNos   Turn numbers from root to current leaf
     * @param int|null       $currentLeafTurnNo   The current active leaf turn number
     */
    public function __construct(
        public array $events,
        public int $canonicalEventCount,
        public array $activePathTurnNos,
        public ?int $currentLeafTurnNo,
    ) {
    }
}
