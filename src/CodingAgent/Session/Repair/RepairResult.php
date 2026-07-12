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
        public bool $repairableStaleCancellationDetected,
        public bool $staleCancellationRepaired,
        public int $terminalEventsAppended,
        /** null = replay validation not evaluated (e.g. dry-run); true/false = post-repair or refusal validation outcome */
        public ?bool $replayOk,
        public string $message,
        public array $duplicateSeqs = [],
        public array $missingSeqs = [],
        public ?SessionRepairRefusalReasonEnum $refusalReason = null,
    ) {
    }
}
