<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Replay;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * App-session result of branch replay filtering (full diagnostics for TUI/session).
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
