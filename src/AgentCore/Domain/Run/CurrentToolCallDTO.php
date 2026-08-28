<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

/**
 * Bounded current tool-call coordination descriptor. Concrete tool payload,
 * name, arguments, and result remain owned by the durable tool batch store.
 */
final readonly class CurrentToolCallDTO
{
    public const int ID_MAX_LENGTH = 255;

    public function __construct(
        public string $batchId,
        public string $toolCallId,
        public int $orderIndex,
        public RunOperationalToolCallStatusEnum $status,
        public int $attempt,
    ) {
    }

    public function withStatus(RunOperationalToolCallStatusEnum $status): self
    {
        return new self($this->batchId, $this->toolCallId, $this->orderIndex, $status, $this->attempt);
    }
}
