<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract;

final readonly class ChildRunBatchSupervisionResultDTO
{
    /**
     * @param list<ChildRunBatchItemSnapshotDTO> $items
     */
    public function __construct(
        public array $items,
    ) {
    }
}
