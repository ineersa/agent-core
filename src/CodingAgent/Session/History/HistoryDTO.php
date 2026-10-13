<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

/** Retained turn order for explicit repair/export array replay. */
final readonly class HistoryDTO
{
    /**
     * @param list<int> $retainedTurnNos
     */
    public function __construct(
        public array $retainedTurnNos,
    ) {
    }

    /**
     * Retained turns from start through $positionTurnNo inclusive.
     * Empty when position is 0 (before first turn).
     *
     * @return list<int>
     */
    public function retainedTurnNosThrough(int $positionTurnNo): array
    {
        if ($positionTurnNo <= 0) {
            return [];
        }

        $prefix = [];
        foreach ($this->retainedTurnNos as $turnNo) {
            $prefix[] = $turnNo;
            if ($turnNo === $positionTurnNo) {
                return $prefix;
            }
        }

        // Target not retained: empty prefix (do not invent ancestry).
        return [];
    }
}
