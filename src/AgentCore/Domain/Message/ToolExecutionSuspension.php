<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;

/**
 * Run-control message admitting a non-blocking tool-execution suspension.
 *
 * Carries the typed pending human-input request plus original run/turn/step/
 * tool-call correlation. Does not duplicate ExecuteToolCall args/envelope —
 * ToolBatchStateDTO remains the exact-call owner.
 */
final readonly class ToolExecutionSuspension extends AbstractAgentBusMessage
{
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public string $toolCallId,
        public int $orderIndex,
        public PendingHumanInputRequestDTO $request,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
