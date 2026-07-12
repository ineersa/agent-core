<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Repair;

final readonly class RepairResult
{
    /**
     * @param list<int> $duplicateSeqs
     * @param list<int> $missingSeqs
     */
    public function __construct(
        public bool $repairWasNeeded,
        public bool $staleCancellationRepaired,
        public int $terminalEventsAppended,
        public bool $replayOk,
        public string $message,
        public array $duplicateSeqs = [],
        public ?SessionRepairRefusalReasonEnum $refusalReason = null,
        public array $missingSeqs = [],
    ) {
    }
}
