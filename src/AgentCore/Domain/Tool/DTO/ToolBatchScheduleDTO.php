<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\DTO;

/** Scheduling membership only; invocation and result bodies remain in the store. */
final class ToolBatchScheduleDTO
{
    /**
     * @param array<string, int>    $expectedOrder
     * @param array<string, string> $callModes
     * @param list<string>          $pendingQueue
     * @param array<string, true>   $inFlight
     * @param array<string, true>   $completedIds
     * @param array<string, string> $awaitingHumanInput
     */
    public function __construct(
        public array $expectedOrder,
        public array $callModes,
        public array $pendingQueue,
        public array $inFlight,
        public array $completedIds,
        public bool $finalized,
        public int $maxParallelism,
        public array $awaitingHumanInput = [],
    ) {
    }
}
