<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

/**
 * Flat retained history for one session/run.
 *
 * - retainedTurnNos: every active TurnAdvanced anchor (including internal tool/shell/assistant turns)
 * - promptsByTurnNo: sparse map of actual selectable human prompts keyed by anchor turn
 * - positionTurnNo: explicit selected tip; 0 means before first / empty (never null)
 *
 * @param list<int>          $retainedTurnNos
 * @param array<int, string> $promptsByTurnNo insertion order follows retained turns
 */
final readonly class HistoryDTO
{
    /**
     * @param list<int>          $retainedTurnNos
     * @param array<int, string> $promptsByTurnNo
     */
    public function __construct(
        public array $retainedTurnNos,
        public array $promptsByTurnNo,
        public int $positionTurnNo,
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

    /**
     * Predecessor retained turn of $turnNo, or 0 when $turnNo is the first retained turn.
     */
    public function predecessorTurnNo(int $turnNo): int
    {
        $prev = 0;
        foreach ($this->retainedTurnNos as $current) {
            if ($current === $turnNo) {
                return $prev;
            }
            $prev = $current;
        }

        throw new \RuntimeException(\sprintf('Turn %d is not in retained history.', $turnNo));
    }
}
