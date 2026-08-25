<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/**
 * Durable identity of the single in-flight operation.
 *
 * The message key is an operation identity only. It is not retained after the
 * operation is completed or superseded, so it cannot become a receipt ledger.
 */
final readonly class CurrentOperationDTO
{
    public function __construct(
        public CurrentOperationKindEnum $kind,
        public int $turnNo,
        public string $stepId,
        public int $attempt,
        public string $idempotencyKey,
        public ?string $subjectId = null,
    ) {
    }

    public function matches(CurrentOperationKindEnum $kind, int $turnNo, string $stepId, int $attempt): bool
    {
        return $this->kind === $kind
            && $this->turnNo === $turnNo
            && $this->stepId === $stepId
            && $this->attempt === $attempt;
    }
}
