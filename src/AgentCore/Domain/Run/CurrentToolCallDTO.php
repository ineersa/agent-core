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
        foreach (['batchId' => $batchId, 'toolCallId' => $toolCallId] as $name => $value) {
            if ('' === trim($value) || mb_strlen($value) > self::ID_MAX_LENGTH) {
                throw new \InvalidArgumentException($name.' must be bounded and non-blank.');
            }
        }
        if ($orderIndex < 0 || $attempt < 0) {
            throw new \InvalidArgumentException('Tool order and attempt must not be negative.');
        }
    }

    public function withStatus(RunOperationalToolCallStatusEnum $status): self
    {
        return new self($this->batchId, $this->toolCallId, $this->orderIndex, $status, $this->attempt);
    }
}
