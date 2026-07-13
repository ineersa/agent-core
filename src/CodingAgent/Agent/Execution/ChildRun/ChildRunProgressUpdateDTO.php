<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun;

final readonly class ChildRunProgressUpdateDTO
{
    /**
     * @param list<ChildRunBatchItemSnapshotDTO> $items
     * @param array<string, int>                 $activeTurns childRunId => turnNo
     */
    public function __construct(
        public string $parentRunId,
        public array $items,
        public array $activeTurns,
        public int $seq,
        public int $progressStartedMicros,
        public string $aggregateStatus,
        public bool $isSingleChild,
        public ?ChildRunSingleProgressContextDTO $singleContext = null,
    ) {
        if ($isSingleChild && null === $singleContext) {
            throw new \InvalidArgumentException('Single-child progress updates require singleContext.');
        }
        if (!$isSingleChild && null !== $singleContext) {
            throw new \InvalidArgumentException('Parallel progress updates must not include singleContext.');
        }
    }
}
