<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

final readonly class RepairResult
{
    public function __construct(
        public bool $repairableStaleCancellationDetected,
        public bool $staleCancellationRepaired,
        public string $message,
        public ?SessionRepairRefusalReasonEnum $refusalReason = null,
        /** Number of redispatch requests made by repair, not broker acceptances or completed executions. */
        public int $activeOperationsRedriven = 0,
    ) {
    }
}
