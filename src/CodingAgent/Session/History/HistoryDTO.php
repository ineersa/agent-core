<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\History;

/**
 * Ordered retained history for one session/run.
 *
 * @param list<HistoryTurnDTO> $turns Retained turns in order; discarded turns omitted
 */
final readonly class HistoryDTO
{
    /**
     * @param list<HistoryTurnDTO> $turns
     */
    public function __construct(
        public string $runId,
        public array $turns,
        public ?int $positionTurnNo,
    ) {
    }

    /**
     * @return list<int>
     */
    public function retainedTurnNos(): array
    {
        return array_map(static fn (HistoryTurnDTO $turn): int => $turn->turnNo, $this->turns);
    }

    public function turn(int $turnNo): ?HistoryTurnDTO
    {
        foreach ($this->turns as $turn) {
            if ($turn->turnNo === $turnNo) {
                return $turn;
            }
        }

        return null;
    }

    /**
     * Retained turns from start through $positionTurnNo inclusive.
     * Empty when position is 0 / null (before first turn).
     *
     * @return list<int>
     */
    public function retainedTurnNosThrough(?int $positionTurnNo): array
    {
        if (null === $positionTurnNo || 0 === $positionTurnNo) {
            return [];
        }

        $prefix = [];
        foreach ($this->turns as $turn) {
            $prefix[] = $turn->turnNo;
            if ($turn->turnNo === $positionTurnNo) {
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
        foreach ($this->turns as $turn) {
            if ($turn->turnNo === $turnNo) {
                return $prev;
            }
            $prev = $turn->turnNo;
        }

        throw new \RuntimeException(\sprintf('Turn %d is not in retained history.', $turnNo));
    }
}
