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
        /** Number of current operation messages dispatched by an applied manual repair. */
        public int $activeOperationsRedriven = 0,
    ) {
    }
}
