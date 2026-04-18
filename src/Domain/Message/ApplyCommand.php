<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class ApplyCommand extends AbstractAgentBusMessage
{
    /**
     * @param array<string, mixed>      $payload
     * @param array{cancel_safe?: bool} $options
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $kind,
        public array $payload = [],
        public array $options = [],
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
