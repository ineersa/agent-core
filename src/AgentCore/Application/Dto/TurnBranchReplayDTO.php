<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Dto;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Result of filtering an event stream through active-branch path selection.
 *
 * Carry the filtered events plus canonical stream diagnostics so downstream
 * replay services can report full-stream integrity while replaying only
 * the active branch.
 */
final readonly class TurnBranchReplayDTO
{
    /**
     * @param list<RunEvent> $events              Events on the active branch path only
     * @param int            $canonicalEventCount Total event count in the full canonical stream
     * @param int            $canonicalLastSeq    Max sequence number in the full canonical stream
     * @param list<int>      $activePathTurnNos   Turn numbers from root to current leaf
     * @param int|null       $currentLeafTurnNo   The current active leaf turn number
     */
    public function __construct(
        public array $events,
        public int $canonicalEventCount,
        public int $canonicalLastSeq,
        public array $activePathTurnNos,
        public ?int $currentLeafTurnNo,
    ) {
    }
}
