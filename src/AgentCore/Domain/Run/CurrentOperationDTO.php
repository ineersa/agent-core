<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;

/**
 * Durable identity of the single in-flight operation.
 *
 * The message key is an operation identity only. It is not retained after the
 * operation is completed or superseded, so it cannot become a receipt ledger.
 */
final readonly class CurrentOperationDTO
{
    public function __construct(
        public int $turnNo,
        public string $stepId,
        public int $attempt,
        public string $idempotencyKey,
    ) {
        if ($turnNo < 0) {
            throw new \InvalidArgumentException('Current operation turn number must not be negative.');
        }
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Current operation attempt must be positive.');
        }
        if ('' === trim($stepId)) {
            throw new \InvalidArgumentException('Current operation step id must not be blank.');
        }
        if ('' === trim($idempotencyKey)) {
            throw new \InvalidArgumentException('Current operation idempotency key must not be blank.');
        }
    }

    public function matchesMessage(AbstractAgentBusMessage $message): bool
    {
        return $this->matches(
            $message->turnNo(),
            $message->stepId(),
            $message->attempt(),
            $message->idempotencyKey(),
        );
    }

    public function matches(int $turnNo, string $stepId, int $attempt, string $idempotencyKey): bool
    {
        return $this->turnNo === $turnNo
            && $this->stepId === $stepId
            && $this->attempt === $attempt
            && $this->idempotencyKey === $idempotencyKey;
    }
}
