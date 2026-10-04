<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/** Small delivery reference to an owner-sealed immutable invocation. */
final readonly class ExecutionRequest extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $effectId,
        public string $requestType,
        public string $sha256,
        public int $bytes,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
