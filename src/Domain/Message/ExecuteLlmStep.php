<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class ExecuteLlmStep extends AbstractAgentBusMessage
{
    /**
     * @param list<AgentMessage>   $messages
     * @param array<string, mixed> $options
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public array $messages = [],
        public array $options = [],
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
