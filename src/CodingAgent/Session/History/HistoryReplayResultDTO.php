<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Filtered events for retained linear history prefix + diagnostics.
 */
final readonly class HistoryReplayResultDTO
{
    /**
     * @param list<RunEvent> $events              Events on the retained prefix (plus metadata)
     * @param int            $canonicalEventCount Full stream event count
     * @param int            $canonicalLastSeq    Max sequence in the full stream
     * @param list<int>      $retainedTurnNos     Retained turns through position
     * @param int|null       $positionTurnNo      Selected position (null = before first / empty)
     */
    public function __construct(
        public array $events,
        public int $canonicalEventCount,
        public int $canonicalLastSeq,
        public array $retainedTurnNos,
        public ?int $positionTurnNo,
    ) {
    }
}
