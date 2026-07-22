<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

/**
 * Applies a user-submitted bang shell command through the run pipeline.
 *
 * The input is kept verbatim, including its leading "!". The pipeline owns
 * deciding which turn receives the command and derives the executable shell
 * text only when creating the execution effect.
 */
final readonly class ApplyShellCommand extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $rawInput,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
